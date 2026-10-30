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
 * Misión agente-mejoras-uso-real (E1, punto 7, 29/10/2026) — «VOLVÉ A CARGARLA CON OTRO PROVEEDOR».
 *
 * 🔴 EL CASO REAL (Ferre Total, CONV 19): «la podés volver a cargar con el proveedor mariano limpieza»
 * sobre la Compra N° 1357 que el agente acababa de crear → el agente creó OTRA compra (la 1359). Ahora
 * le cambia el proveedor a la que acaba de crear, y la deuda se muda de cuenta. Solo sobre compras del
 * agente, vacías, sin factura a mano y sin escaneo confirmado.
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
class Cambio_de_proveedor_de_compra_Test extends ComprasDelAgenteTestCase
{
    // --------------------------------------------------------------- 7 · cambio de proveedor

    /**
     * 🔴 CONV 19: «volvé a cargarla con el proveedor mariano limpieza» sobre la compra con factura que
     * el agente acaba de crear: se cambia el proveedor de ESA compra (dándolo de alta), no se crea otra,
     * y el escaneo sigue colgado de ella.
     *
     * @test
     */
    public function volver_a_cargarla_con_otro_proveedor_cambia_el_de_la_compra_recien_hecha()
    {
        list($conversation, $compra) = $this->compra_con_factura_del_agente($this->ferretotal);

        $this->assertSame(1, ProviderOrderScan::where('provider_order_id', $compra->id)->count());

        $assistant = $this->siguiente($conversation, 'La podés volver a cargar con el proveedor mariano limpieza');

        $respuesta = HerramientasDeCarga::ejecutar('proponer_cambio_de_proveedor_de_compra', ['proveedor' => 'mariano limpieza'], $conversation, $assistant);
        $datos = json_decode($respuesta['content'], true);
        $this->assertTrue($datos['ok'], $respuesta['content']);

        $resultado = $this->confirmar($conversation, $assistant, $datos['tarjeta_id']);
        $this->assertTrue($resultado['ok'], json_encode($resultado));

        $mariano = Provider::where('user_id', $this->comercio->id)->where('name', 'mariano limpieza')->first();
        $this->assertNotNull($mariano, 'El proveedor nuevo se da de alta en la misma carga.');

        $this->assertSame((int) $mariano->id, (int) $compra->fresh()->provider_id);
        $this->assertSame(1, ProviderOrder::where('user_id', $this->comercio->id)->count(), '🔴 No se creó otra compra.');
        $this->assertSame(1, ProviderOrderScan::where('provider_order_id', $compra->id)->count(), 'El escaneo se conserva.');
        $this->assertStringContainsString('Compra N° ' . $compra->num, $resultado['resultado']);
    }

    /**
     * Con deuda: el movimiento de cuenta corriente se muda a la cuenta del proveedor nuevo, y la del
     * anterior queda sin él.
     *
     * @test
     */
    public function el_cambio_muda_la_deuda_a_la_cuenta_del_proveedor_nuevo()
    {
        list($conversation, $compra) = $this->compra_sin_articulos_confirmada();

        $cavallfer = $this->crear_proveedor('EDUARDO JORGE CAVALLO (CAVALLFER)');

        $assistant = $this->siguiente($conversation, 'Era de cavallfer, cambiala');

        $respuesta = HerramientasDeCarga::ejecutar('proponer_cambio_de_proveedor_de_compra', ['proveedor' => 'cavallfer', 'compra_num' => $compra->num], $conversation, $assistant);
        $datos = json_decode($respuesta['content'], true);
        $this->assertTrue($datos['ok'], $respuesta['content']);
        $this->assertStringContainsString('pasan de la cuenta de FERRETOTAL', json_encode(AiMessageAction::find($datos['tarjeta_id'])->presentacion, JSON_UNESCAPED_UNICODE));

        $resultado = $this->confirmar($conversation, $assistant, $datos['tarjeta_id']);
        $this->assertTrue($resultado['ok'], json_encode($resultado));

        $movimientos = CurrentAcount::where('provider_order_id', $compra->id)->get();
        $this->assertCount(1, $movimientos);
        $this->assertSame((int) $this->cuenta_de($cavallfer)->id, (int) $movimientos[0]->credit_account_id);
        $this->assertSame((int) $cavallfer->id, (int) $movimientos[0]->provider_id);
        $this->assertEqualsWithDelta(198522, (float) $movimientos[0]->debe, 0.01);
        $this->assertSame(0, CurrentAcount::where('credit_account_id', $this->cuenta_de($this->ferretotal)->id)->count(), 'La cuenta de FERRETOTAL queda sin la deuda.');
    }

    /**
     * Con el dueño en "directo" el cambio se hace en el acto (como un pago); en "resuelto" deja tarjeta.
     *
     * @test
     */
    public function en_directo_el_cambio_se_hace_en_el_acto_y_en_resuelto_deja_tarjeta()
    {
        list($conversation, $compra) = $this->compra_con_factura_del_agente($this->ferretotal);
        $cavallfer = $this->crear_proveedor('CAVALLFER');

        User::where('id', $this->comercio->id)->update(['agente_confianza' => 'resuelto']);

        $en_resuelto = json_decode(HerramientasDeCarga::ejecutar('proponer_cambio_de_proveedor_de_compra', ['proveedor' => 'CAVALLFER'], $conversation, $this->siguiente($conversation, 'Era de cavallfer'))['content'], true);
        $this->assertTrue($en_resuelto['ok'], json_encode($en_resuelto));
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($en_resuelto['tarjeta_id'])->estado_guardado());
        $this->assertSame((int) $this->ferretotal->id, (int) $compra->fresh()->provider_id);

        User::where('id', $this->comercio->id)->update(['agente_confianza' => 'directo']);

        $en_directo = json_decode(HerramientasDeCarga::ejecutar('proponer_cambio_de_proveedor_de_compra', ['proveedor' => 'CAVALLFER'], $conversation, $this->siguiente($conversation, 'Era de cavallfer, cambiala'))['content'], true);
        $this->assertTrue($en_directo['ok'], json_encode($en_directo));
        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, AiMessageAction::find($en_directo['tarjeta_id'])->estado_guardado());
        $this->assertSame((int) $cavallfer->id, (int) $compra->fresh()->provider_id);
    }

    /**
     * 🔴 Ronda de arreglos (30/10/2026), arreglo 7a: si el proveedor nuevo hay que darlo de alta, la
     * tarjeta espera el sí aunque el dueño esté en "directo" (como la compra con factura).
     *
     * @test
     */
    public function con_un_proveedor_que_hay_que_dar_de_alta_espera_el_si_aunque_este_en_directo()
    {
        list($conversation, $compra) = $this->compra_con_factura_del_agente($this->ferretotal);

        User::where('id', $this->comercio->id)->update(['agente_confianza' => 'directo']);

        $respuesta = json_decode(HerramientasDeCarga::ejecutar('proponer_cambio_de_proveedor_de_compra', ['proveedor' => 'mariano limpieza'], $conversation, $this->siguiente($conversation, 'La podés volver a cargar con el proveedor mariano limpieza'))['content'], true);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertTrue(!empty($respuesta['requiere_confirmacion']), json_encode($respuesta));
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($respuesta['tarjeta_id'])->estado_guardado());
        $this->assertStringContainsString('mariano limpieza', (string) AiMessageAction::find($respuesta['tarjeta_id'])->presentacion['aviso']);
        $this->assertSame(0, Provider::where('user_id', $this->comercio->id)->where('name', 'mariano limpieza')->count(), 'Todavía no se dio de alta.');
        $this->assertSame((int) $this->ferretotal->id, (int) $compra->fresh()->provider_id);
    }

    /**
     * 🔴 Ronda de arreglos (30/10/2026), arreglo 7b: con pagos imputados en su cuenta corriente, la compra
     * no se cambia de proveedor (el pago quedaría a favor en la cuenta del viejo): se manda a la pantalla.
     *
     * @test
     */
    public function con_pagos_imputados_no_se_cambia_el_proveedor()
    {
        list($conversation, $compra) = $this->compra_sin_articulos_confirmada();
        $this->crear_proveedor('CAVALLFER');

        CurrentAcount::where('provider_order_id', $compra->id)->update(['status' => 'pagandose', 'pagandose' => 50000]);

        $respuesta = json_decode(HerramientasDeCarga::ejecutar('proponer_cambio_de_proveedor_de_compra', ['proveedor' => 'CAVALLFER', 'compra_num' => $compra->num], $conversation, $this->siguiente($conversation, 'Era de cavallfer'))['content'], true);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('pagos imputados', (string) $respuesta['error']);
        $this->assertSame((int) $this->ferretotal->id, (int) $compra->fresh()->provider_id);
    }

    /**
     * No se toca una compra que no creó el agente, ni una con artículos, ni una con el escaneo
     * confirmado: esas se cambian desde la pantalla.
     *
     * @test
     */
    public function el_cambio_solo_va_sobre_compras_del_agente_vacias_y_sin_escaneo_confirmado()
    {
        $cavallfer = $this->crear_proveedor('CAVALLFER');

        $a_mano = ProviderOrder::create(['user_id' => $this->comercio->id, 'provider_id' => $this->ferretotal->id, 'num' => 501]);

        list($conversation, $assistant) = $this->escena('Pasá la compra 501 a cavallfer');
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $de_la_pantalla = \App\Http\Controllers\Helpers\asistente_ia\CambioDeProveedorDeCompraIaHelper::proponer($contexto, $assistant, ['proveedor' => 'CAVALLFER', 'compra_num' => 501]);
        $this->assertFalse($de_la_pantalla['ok']);
        $this->assertStringContainsString('no la cargué yo', (string) $de_la_pantalla['error']);

        list($otra_conv, $del_agente) = $this->compra_con_factura_del_agente($this->ferretotal);
        $con_articulo = Article::create(['user_id' => $this->comercio->id, 'name' => 'Artículo E1']);
        $del_agente->articles()->attach($con_articulo->id, ['amount' => 1]);

        $con_articulos = \App\Http\Controllers\Helpers\asistente_ia\CambioDeProveedorDeCompraIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($otra_conv), $this->siguiente($otra_conv, 'cambiala a cavallfer'), ['proveedor' => 'CAVALLFER', 'compra_num' => $del_agente->num]);
        $this->assertFalse($con_articulos['ok']);
        $this->assertStringContainsString('artículos', (string) $con_articulos['error']);

        $del_agente->articles()->detach();
        ProviderOrderScan::where('provider_order_id', $del_agente->id)->update(['estado' => 'listo', 'resultado_gestion' => 'confirmado']);

        $confirmado = \App\Http\Controllers\Helpers\asistente_ia\CambioDeProveedorDeCompraIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($otra_conv), $this->siguiente($otra_conv, 'cambiala a cavallfer'), ['proveedor' => 'CAVALLFER', 'compra_num' => $del_agente->num]);
        $this->assertFalse($confirmado['ok']);
        $this->assertStringContainsString('confirmado', (string) $confirmado['error']);

        $this->assertSame((int) $this->ferretotal->id, (int) $del_agente->fresh()->provider_id);
    }

}
