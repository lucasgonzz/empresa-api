<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionPorTextoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaCompraConFacturaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaCompraSinArticulosIaHelper;
use App\Jobs\RunProviderOrderScanJob;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Models\Article;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Iva;
use App\Models\Provider;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
use App\Models\ProviderOrderAfipTicketIva;
use App\Models\ProviderOrderScan;
use App\Models\ProviderOrderScanImage;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
use App\Services\AsistenteIa\Mcp\McpHerramientasHelper;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
/**
 * Misión agente-mejoras-uso-real (E1, punto 6, 29/10/2026) — LA COMPRA SIN ARTÍCULOS.
 *
 * 🔴 EL CASO REAL (Ferre Total, CONV 19): «me cargás una compra sin artículos con una factura sin IVA
 * de 198.522 al proveedor ferretotal, con movimientos en cuenta corriente» → el agente pidió la FOTO.
 * Ahora arma la tarjeta sin pedir foto, y al confirmarla la deuda entra a la cuenta del proveedor por
 * el total exacto (sin sumarle el IVA dos veces). Nunca se ejecuta sola: mueve la deuda.
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
class Compra_sin_articulos_Test extends ComprasDelAgenteTestCase
{
    // ------------------------------------------------------------------ 6 · compra sin artículos

    /**
     * 🔴 CONV 19: la tarjeta sale sin pedir ninguna foto y dice «$ 198.522 sin IVA».
     *
     * @test
     */
    public function la_compra_sin_articulos_arma_la_tarjeta_sin_pedir_foto()
    {
        list($conversation, $assistant) = $this->escena('Me cargás una compra sin artículos con una factura sin iva de 198.522 al proveedor ferretotal. Con movimientos en cuenta corriente.');

        $respuesta = PropuestaCompraSinArticulosIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, [
            'proveedor'        => 'ferretotal',
            'total'            => 198522,
            'iva'              => 'sin_iva',
            'cuenta_corriente' => true,
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);
        $this->assertSame(AiMessageAction::TIPO_COMPRA_SIN_ARTICULOS, $tarjeta->tipo);
        $this->assertSame((int) $this->ferretotal->id, (int) $tarjeta->datos['provider_id']);
        $this->assertStringContainsString('$ 198.522 sin IVA', json_encode($tarjeta->presentacion, JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, ProviderOrder::where('user_id', $this->comercio->id)->count(), 'Proponer no crea nada.');
    }

    /**
     * Al confirmar: compra sin artículos, «Total desde facturas», la factura sin alícuotas por el total
     * dicho, y la deuda en la cuenta corriente del proveedor por ese total exacto.
     *
     * @test
     */
    public function al_confirmar_la_deuda_entra_por_el_total_exacto()
    {
        list(, $compra) = $this->compra_sin_articulos_confirmada(['numero_comprobante' => '0001-00004567']);

        $this->assertSame(0, $compra->articles()->count());
        $this->assertSame(1, (int) $compra->total_from_provider_order_afip_tickets);
        $this->assertSame(0, (int) $compra->total_with_iva, '🔴 El IVA ya está adentro del total de la factura: no se suma por encima.');
        $this->assertSame(0, (int) $compra->update_stock);
        $this->assertEqualsWithDelta(198522, (float) $compra->total, 0.01);

        $factura = ProviderOrderAfipTicket::where('provider_order_id', $compra->id)->first();
        $this->assertNotNull($factura);
        $this->assertSame('0001-00004567', $factura->code);
        $this->assertEqualsWithDelta(198522, (float) $factura->total, 0.01);
        $this->assertNull($factura->total_iva, 'Sin IVA: no aplica (no es "IVA 0").');
        $this->assertSame(0, ProviderOrderAfipTicketIva::where('provider_order_afip_ticket_id', $factura->id)->count());

        $movimiento = $this->movimiento_de($compra);
        $this->assertNotNull($movimiento);
        $this->assertEqualsWithDelta(198522, (float) $movimiento->debe, 0.01);
        $this->assertSame((int) $this->cuenta_de($this->ferretotal)->id, (int) $movimiento->credit_account_id);
    }

    /**
     * Con 21 %, el total dicho es el FINAL: neto + IVA = total, y la compra no queda con el IVA dos veces.
     *
     * @test
     */
    public function con_iva_21_el_total_se_desarma_en_neto_mas_iva()
    {
        if (is_null(Iva::where('percentage', '21')->value('id'))) {
            Iva::create(['percentage' => '21']);
        }

        list(, $compra) = $this->compra_sin_articulos_confirmada(['total' => 121000, 'iva' => 21]);

        $factura = ProviderOrderAfipTicket::where('provider_order_id', $compra->id)->first();
        $alicuota = ProviderOrderAfipTicketIva::where('provider_order_afip_ticket_id', $factura->id)->first();

        $this->assertEqualsWithDelta(100000, (float) $alicuota->neto, 0.01);
        $this->assertEqualsWithDelta(21000, (float) $alicuota->iva_importe, 0.01);
        $this->assertEqualsWithDelta(121000, (float) $factura->total, 0.01);
        $this->assertEqualsWithDelta(21000, (float) $factura->total_iva, 0.01);
        $this->assertEqualsWithDelta(121000, (float) $compra->total, 0.01, 'No 142.000: el IVA no se suma dos veces.');
        $this->assertEqualsWithDelta(121000, (float) $this->movimiento_de($compra)->debe, 0.01);
    }

    /**
     * 🔴 Ronda de arreglos (30/10/2026), arreglo 6: si el modelo llama a la compra CON factura sin foto
     * y la persona dictó un total (CONV 19), el error no le pide la foto: lo manda a la compra sin
     * artículos. Sin monto dictado, sigue pidiendo la foto como siempre.
     *
     * @test
     */
    public function la_compra_con_factura_sin_foto_y_con_un_total_dictado_deriva_a_la_compra_sin_articulos()
    {
        $this->dar_extension(self::SLUG_ESCANEO);

        list($conversation, $assistant) = $this->escena('Me cargas una compra sin artículos con una factura sin iva de 198.522 al proveedor ferretotal. Con movimientos en cuenta corriente.');

        $respuesta = PropuestaCompraConFacturaIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, ['proveedor' => 'ferretotal']);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringStartsWith('No se armó ninguna tarjeta', (string) $respuesta['error']);
        $this->assertStringContainsString('proponer_compra_sin_articulos', (string) $respuesta['error']);
        $this->assertSame('198.522', $respuesta['opciones']['monto_dictado']);

        list($otra, $sin_monto) = $this->escena('Cargame la compra de ferretotal, la factura del 29/10/2026 N° 0001-00004567');

        $respuesta = PropuestaCompraConFacturaIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($otra), $sin_monto, ['proveedor' => 'ferretotal']);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringStartsWith('No tengo ninguna foto de factura sin usar', (string) $respuesta['error'], 'Una fecha o un N° de comprobante no son un monto.');
    }

    /**
     * 🔴 Ronda de arreglos (30/10/2026), arreglo 12: un total dictado con punto de miles («198.522») es
     * 198522, no 198,52; «1.500,50» es 1500,5. Y un número con tres decimales no se adivina: vuelve un
     * error para el modelo.
     *
     * @test
     */
    public function el_total_dictado_con_punto_de_miles_se_lee_bien()
    {
        list($conversation, $assistant) = $this->escena('Cargame una compra sin artículos sin iva de 198.522 a ferretotal');
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $con_miles = PropuestaCompraSinArticulosIaHelper::proponer($contexto, $assistant, ['proveedor' => 'ferretotal', 'total' => '198.522', 'iva' => 'sin_iva']);
        $this->assertTrue($con_miles['ok'], json_encode($con_miles));
        $this->assertEqualsWithDelta(198522, (float) AiMessageAction::find($con_miles['tarjeta_id'])->datos['total'], 0.001);

        $con_centavos = PropuestaCompraSinArticulosIaHelper::proponer($contexto, $assistant, ['proveedor' => 'ferretotal', 'total' => '$ 1.500,50', 'iva' => 'sin_iva']);
        $this->assertTrue($con_centavos['ok'], json_encode($con_centavos));
        $this->assertEqualsWithDelta(1500.5, (float) AiMessageAction::find($con_centavos['tarjeta_id'])->datos['total'], 0.001);

        $mal_leido = PropuestaCompraSinArticulosIaHelper::proponer($contexto, $assistant, ['proveedor' => 'ferretotal', 'total' => 198.522, 'iva' => 'sin_iva']);
        $this->assertFalse($mal_leido['ok']);
        $this->assertStringStartsWith('No se armó ninguna tarjeta', (string) $mal_leido['error']);
    }

    /**
     * 🔴 Ronda de arreglos (30/10/2026), arreglo 5: dos facturas del mismo proveedor en la misma charla
     * son dos tarjetas, no una que pisa a la otra. La clave lleva el total y el número cuando viene.
     *
     * @test
     */
    public function dos_facturas_del_mismo_proveedor_no_se_pisan_las_tarjetas()
    {
        list($conversation, $assistant) = $this->escena('Cargame dos compras sin artículos sin iva a ferretotal: una de 198.522 y otra de 45.000');
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $una = PropuestaCompraSinArticulosIaHelper::proponer($contexto, $assistant, ['proveedor' => 'ferretotal', 'total' => 198522, 'iva' => 'sin_iva']);
        $otra = PropuestaCompraSinArticulosIaHelper::proponer($contexto, $assistant, ['proveedor' => 'ferretotal', 'total' => 45000, 'iva' => 'sin_iva', 'numero_comprobante' => '0002-00000099']);

        $this->assertTrue($una['ok'], json_encode($una));
        $this->assertTrue($otra['ok'], json_encode($otra));
        $this->assertSame([], $otra['reemplazo'], 'La segunda factura no reemplaza a la primera.');
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($una['tarjeta_id'])->estado_guardado());

        $this->assertSame('compra_sin_articulos:' . $this->ferretotal->id . ':198522.00', AiMessageAction::find($una['tarjeta_id'])->clave);
        $this->assertSame('compra_sin_articulos:' . $this->ferretotal->id . ':45000.00:0002-00000099', AiMessageAction::find($otra['tarjeta_id'])->clave);

        /* La corrección sigue reemplazando, por reemplaza_a. */
        $corregida = PropuestaCompraSinArticulosIaHelper::proponer($contexto, $assistant, ['proveedor' => 'ferretotal', 'total' => 200000, 'iva' => 'sin_iva', 'reemplaza_a' => $una['tarjeta_id']]);
        $this->assertSame([(int) $una['tarjeta_id']], $corregida['reemplazo']);
    }

    /**
     * Sin cuenta corriente no hay movimiento; sin total o sin IVA, se pregunta.
     *
     * @test
     */
    public function sin_cuenta_corriente_no_hay_movimiento_y_lo_que_falta_se_pregunta()
    {
        list(, $compra) = $this->compra_sin_articulos_confirmada(['cuenta_corriente' => false]);

        $this->assertSame(0, (int) $compra->generate_current_acount);
        $this->assertNull($this->movimiento_de($compra));

        list($conversation, $assistant) = $this->escena('Cargame una compra sin artículos a ferretotal');
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $sin_total = PropuestaCompraSinArticulosIaHelper::proponer($contexto, $assistant, ['proveedor' => 'ferretotal', 'iva' => 'sin_iva']);
        $this->assertFalse($sin_total['ok']);
        $this->assertCount(1, $sin_total['faltan']);

        $sin_iva = PropuestaCompraSinArticulosIaHelper::proponer($contexto, $assistant, ['proveedor' => 'ferretotal', 'total' => 5000]);
        $this->assertFalse($sin_iva['ok']);
        $this->assertCount(1, $sin_iva['faltan']);
        $this->assertStringContainsString('IVA', $sin_iva['faltan'][0]);
    }

    /**
     * 🔴 Nunca se ejecuta sola: ni en "directo". Mueve la deuda con el proveedor.
     *
     * @test
     */
    public function la_compra_sin_articulos_nunca_se_auto_confirma()
    {
        $this->assertContains(AiMessageAction::TIPO_COMPRA_SIN_ARTICULOS, HerramientasDeCarga::NUNCA_AUTO_CONFIRMABLES);

        User::where('id', $this->comercio->id)->update(['agente_confianza' => 'directo']);

        list($conversation, $assistant) = $this->escena('Cargame una compra sin artículos de 5000 sin iva a ferretotal, sin preguntar');

        $resultado = HerramientasDeCarga::ejecutar('proponer_compra_sin_articulos', ['proveedor' => 'ferretotal', 'total' => 5000, 'iva' => 'sin_iva'], $conversation, $assistant);

        $this->assertFalse($resultado['is_error'], $resultado['content']);
        $respuesta = json_decode($resultado['content'], true);
        $this->assertTrue($respuesta['ok'], $resultado['content']);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($respuesta['tarjeta_id'])->estado_guardado());
        $this->assertSame(0, ProviderOrder::where('user_id', $this->comercio->id)->count());
    }

}
