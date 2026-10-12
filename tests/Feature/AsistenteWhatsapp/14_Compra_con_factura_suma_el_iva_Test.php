<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionPorTextoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaCompraConFacturaIaHelper;
use App\Http\Controllers\Helpers\providerOrder\ProviderOrderAltaHelper;
use App\Models\AiMessage;
use App\Models\AiMessageImagen;
use App\Models\Article;
use App\Models\CurrentAcount;
use App\Models\Iva;
use App\Models\Provider;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderScan;
use App\Models\ProviderOrderStatus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * Misión `compra-asistente-iva-total` (9/10/2026) — la compra que el asistente de WhatsApp crea
 * con una factura escaneada tiene que sumar el IVA de esa factura a su total, igual que la que se
 * carga desde el formulario de Compras.
 *
 * El defecto (hallazgo 1 del informe `20261009-devolucion-proveedor-iva-sin-factura`): el alta del
 * asistente (PropuestaCompraConFacturaIaHelper::orden_para_la_factura) no mandaba
 * `total_with_iva`, ProviderOrderAltaHelper lo insertaba en NULL y
 * NewProviderOrderHelper::suma_iva_al_total() exige esa bandera. Al confirmar el escaneo con la
 * factura, la compra guardaba el IVA en `total_iva` pero no lo sumaba al total, y la deuda con el
 * proveedor (`current_acounts.debe`) quedaba NETA hasta que alguien volviera a guardar la compra
 * desde el formulario, que manda la bandera en 1 siempre.
 *
 * 🔴 Se recorre el camino real de punta a punta, sin insertar la compra a mano: la propuesta del
 * asistente, el "sí" por WhatsApp (que es lo que crea la compra), el escaneo que termina y la
 * confirmación desde la pantalla de revisión. Insertar la compra directo con la bandera puesta es
 * exactamente lo que dejó pasar el defecto en la suite del escaneo.
 */
class Compra_con_factura_suma_el_iva_Test extends AsistenteWhatsappTestCase
{
    /** @var \App\Models\Provider */
    protected $proveedor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dar_extension(self::SLUG_ASISTENTE);
        $this->dar_extension(self::SLUG_ESCANEO);

        Queue::fake();
        Notification::fake();
        Storage::fake('local');

        $this->proveedor = Provider::create([
            'user_id' => $this->comercio->id,
            'name'    => 'Distribuidora Sur',
        ]);

        /*
         * Las dos cuentas corrientes del proveedor, como las deja el alta desde la pantalla: sin
         * ellas set_current_acount() no tiene dónde asentar la deuda, y la deuda es lo que se mide.
         */
        CreditAccountHelper::crear_credit_accounts('provider', $this->proveedor->id, $this->comercio->id);
    }

    /**
     * La compra que crea el asistente al recibir el "sí" del dueño, con la foto de la factura
     * colgada. Es el mismo recorrido que Compra_con_factura_Test::al_confirmar_crea_la_compra...
     *
     * @return \App\Models\ProviderOrder
     */
    protected function compra_creada_por_el_asistente()
    {
        $this->proponer_y_confirmar_la_compra();

        $compra = ProviderOrder::where('user_id', $this->comercio->id)
                                ->where('provider_id', $this->proveedor->id)
                                ->first();

        $this->assertNotNull($compra, 'El "sí" tiene que haber creado la compra.');

        return $compra;
    }

    /**
     * La propuesta del asistente para la foto de la factura y el "sí" del dueño por WhatsApp, que
     * es lo que crea la compra —o reusa la vacía que la tarjeta prometió— y le cuelga el escaneo.
     *
     * @return void
     */
    protected function proponer_y_confirmar_la_compra()
    {
        $conversation = $this->conversacion_whatsapp();

        $del_dueno = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Mirá esta factura']);

        $this->guardar_foto($del_dueno);

        $this->mensaje($conversation, 'assistant', 'listo', ['contenido' => '¿De qué proveedor es?']);
        $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'De Distribuidora Sur']);

        $propone = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $respuesta = PropuestaCompraConFacturaIaHelper::proponer(
            ContextoDeCargaIa::de_la_conversacion($conversation),
            $propone,
            ['proveedor' => 'Distribuidora Sur']
        );

        $this->assertTrue($respuesta['ok'], 'Motivo: ' . json_encode($respuesta));

        /*
         * Una tarjeta de un mensaje todavía 'pendiente' no se puede confirmar: se cierra el turno
         * como lo hace el job, y el "sí" lo contesta el assistant del turno siguiente.
         */
        $propone->estado    = 'listo';
        $propone->contenido = 'Te doy de alta la compra de Distribuidora Sur con esa factura, ¿la registro?';
        $propone->save();

        $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Sí, dale']);

        $contestando = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $resultado = ConfirmacionPorTextoIaHelper::confirmar($conversation, $contestando, $respuesta['tarjeta_id']);

        $this->assertTrue($resultado['ok'], 'Motivo: ' . json_encode($resultado));
    }

    /**
     * Una compra vacía de este proveedor tal como la dejaba el asistente ANTES del arreglo: por el
     * mismo ProviderOrderAltaHelper::crear() y con las mismas claves que mandaba
     * orden_para_la_factura(), SIN `total_with_iva` —esa clave no viajaba, así que queda en NULL—.
     * Por ese camino la compra queda como la real: total 0 y su movimiento de cuenta corriente con
     * debe 0, así que al confirmar el escaneo la deuda se ACTUALIZA
     * (NewProviderOrderHelper::actualizar_current_acount()) en vez de crearse.
     *
     * Recién creada, entra en la ventana de reuso (PropuestaCompraConFacturaIaHelper::DIAS_DE_REUSO)
     * — la misma condición que Compra_con_factura_Test::reusa_una_compra_vacia_y_reciente_del_mismo_proveedor.
     *
     * @return \App\Models\ProviderOrder
     */
    protected function compra_vacia_como_la_dejaba_el_asistente()
    {
        $estado = ProviderOrderStatus::where('name', PropuestaCompraConFacturaIaHelper::ESTADO_EN_PROCESO)
                                        ->orderBy('id')
                                        ->first();

        return ProviderOrderAltaHelper::crear([
            'user_id'                  => $this->comercio->id,
            'provider_id'              => (int) $this->proveedor->id,
            'provider_order_status_id' => is_null($estado) ? null : (int) $estado->id,
            'address_id'               => null,
            'update_prices'            => 0,
            'update_stock'             => 0,
            'generate_current_acount'  => 1,
            'precios_incluyen_iva'     => 0,
            'moneda_id'                => 1,
            'articles'                 => [],
        ]);
    }

    /**
     * El escaneo que dejó el asistente, terminado (como lo deja RunProviderOrderScanJob), y
     * confirmado desde la pantalla de revisión con un artículo de 3 u. × $1.000 y una factura A
     * con $21.000 de IVA discriminado — los mismos importes que
     * EscaneoFacturaCompra\Endpoints_y_confirmacion_Test::confirmar_deja_el_iva_y_la_deuda...
     *
     * @param  \App\Models\ProviderOrder  $compra
     * @return \Illuminate\Testing\TestResponse
     */
    protected function confirmar_el_escaneo_con_la_factura(ProviderOrder $compra)
    {
        $scan = ProviderOrderScan::where('provider_order_id', $compra->id)->first();

        $this->assertNotNull($scan, 'El asistente tiene que haber dejado el escaneo de la factura.');

        $scan->update([
            'estado'    => 'listo',
            'progreso'  => 100,
            'resultado' => [
                'version'   => 1,
                'articulos' => [],
                'factura'   => ['es_factura_afip' => true],
                'avisos'    => [],
            ],
        ]);

        $articulo = Article::create([
            'name'    => 'ART ESCANEADO POR EL ASISTENTE',
            'status'  => 'active',
            'user_id' => $this->comercio->id,
        ]);

        $iva = Iva::first();

        $this->assertNotNull($iva, 'La base de testing tiene que tener alícuotas de IVA sembradas.');

        Sanctum::actingAs($this->comercio);

        $respuesta = $this->postJson('api/provider-order-scan/' . $scan->uuid . '/confirmar', [
            'articulos' => [[
                'article_id'        => $articulo->id,
                'crear_en_catalogo' => false,
                'bar_code'          => null,
                'codigo_proveedor'  => null,
                'nombre'            => $articulo->name,
                'cantidad'          => 3,
                'costo_unitario'    => 1000,
                'notas'             => null,
            ]],
            'factura' => [
                'guardar'             => true,
                'pasar_a_manual'      => false,
                'code'                => '0003-00077777',
                'issued_at'           => '2026-10-09',
                'emisor_cuit'         => '30712345678',
                'emisor_razon_social' => 'DISTRIBUIDORA SUR S.A.',
                'total'               => 124300,
                'ivas'                => [
                    ['iva_id' => $iva->id, 'neto' => 100000, 'iva_importe' => 21000],
                ],
            ],
        ]);

        $respuesta->assertStatus(200);

        return $respuesta;
    }

    /**
     * @param  \App\Models\AiMessage  $mensaje
     * @return \App\Models\AiMessageImagen
     */
    protected function guardar_foto(AiMessage $mensaje)
    {
        $recurso = imagecreatetruecolor(40, 40);

        ob_start();
        imagepng($recurso);
        $binario = ob_get_clean();

        $path = 'asistente_imagenes/' . $this->comercio->id . '/' . $mensaje->id . '/1.webp';

        Storage::disk('local')->put($path, $binario);

        return AiMessageImagen::create([
            'ai_message_id' => $mensaje->id,
            'user_id'       => $this->comercio->id,
            'orden'         => 1,
            'path'          => $path,
            'mime'          => 'image/webp',
            'bytes'         => strlen($binario),
        ]);
    }

    /**
     * @param  string  $condicion  'RRII' o 'MT'.
     * @return void
     */
    protected function condicion_iva($condicion)
    {
        $this->comercio->condicion_iva_precios = $condicion;
        $this->comercio->save();
    }

    /**
     * @param  \App\Models\ProviderOrder  $compra
     * @return float|null
     */
    protected function deuda_de($compra)
    {
        $current_acount = CurrentAcount::where('provider_order_id', $compra->id)->first();

        $this->assertNotNull($current_acount, 'La compra nace con generate_current_acount = 1: tiene que dejar su movimiento.');

        return (float) $current_acount->debe;
    }

    /**
     * La bandera, sola: la compra del asistente nace como la del formulario.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function la_compra_del_asistente_nace_sumando_el_iva_como_la_del_formulario()
    {
        $compra = $this->compra_creada_por_el_asistente();

        $this->assertEquals(
            1,
            (int) $compra->total_with_iva,
            'La SPA manda total_with_iva = 1 en TODA compra; la del asistente no puede nacer en NULL.'
        );
    }

    /**
     * 🔴 El defecto, de punta a punta: un Responsable Inscripto con una factura A con IVA
     * discriminado tiene que quedar debiéndole al proveedor el total CON el IVA.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function al_confirmar_el_escaneo_la_deuda_del_proveedor_lleva_el_iva_de_la_factura()
    {
        $this->condicion_iva('RRII');

        $compra = $this->compra_creada_por_el_asistente();

        $this->confirmar_el_escaneo_con_la_factura($compra);

        $compra->refresh();

        $this->assertEquals(21000, (float) $compra->total_iva, 'El IVA de la factura queda en la compra.');

        $this->assertEquals(
            24000,
            (float) $compra->total,
            'Total = $3.000 de artículos + $21.000 de IVA de la factura.'
        );

        $this->assertEquals(
            24000,
            $this->deuda_de($compra),
            'La deuda con el proveedor es el total CON el IVA, sin tener que volver a guardar la compra desde el formulario.'
        );
    }

    /**
     * La pata Monotributista de suma_iva_al_total() sigue mandando: la bandera prendida no le
     * suma el IVA a un comercio que no lo recupera como crédito fiscal.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function un_monotributista_no_suma_el_iva_de_la_factura_al_total()
    {
        $this->condicion_iva('MT');

        $compra = $this->compra_creada_por_el_asistente();

        $this->confirmar_el_escaneo_con_la_factura($compra);

        $compra->refresh();

        $this->assertEquals(21000, (float) $compra->total_iva, 'El IVA se sigue registrando aunque no se sume.');
        $this->assertEquals(3000, (float) $compra->total);
        $this->assertEquals(3000, $this->deuda_de($compra));
    }

    /**
     * La pata `precios_incluyen_iva` también: si el proveedor tiene marcado que sus precios ya
     * traen el IVA (la propuesta lo copia de la ficha del proveedor), sumarlo encima lo duplicaría
     * (prompt 614).
     *
     * @group asistente-whatsapp
     * @test
     */
    public function con_precios_que_incluyen_iva_no_lo_suma_dos_veces()
    {
        $this->condicion_iva('RRII');

        $this->proveedor->precios_incluyen_iva = 1;
        $this->proveedor->save();

        $compra = $this->compra_creada_por_el_asistente();

        $this->assertEquals(1, (int) $compra->precios_incluyen_iva);

        $this->confirmar_el_escaneo_con_la_factura($compra);

        $compra->refresh();

        $this->assertEquals(3000, (float) $compra->total);
        $this->assertEquals(3000, $this->deuda_de($compra));
    }

    /**
     * 🔴 La compra vacía que dejó el asistente ANTES del arreglo, con `total_with_iva` en NULL, es
     * justo la que se reusa si el dueño manda la factura de ese proveedor dentro de la semana. Al
     * reusarla tiene que quedar sumando el IVA: si no, la factura que se le cuelga ahora deja la
     * deuda NETA, y volver a guardarla desde el formulario no la arregla (la casilla está oculta y
     * al editar la SPA reenvía lo guardado).
     *
     * @group asistente-whatsapp
     * @test
     */
    public function la_compra_vacia_que_se_reusa_con_la_bandera_apagada_queda_sumando_el_iva()
    {
        $this->condicion_iva('RRII');

        $vacia = $this->compra_vacia_como_la_dejaba_el_asistente();

        $this->assertNull(
            $vacia->fresh()->total_with_iva,
            'Precondición: la compra vacía nace como la dejaba el asistente, con la bandera en NULL.'
        );

        $this->assertEquals(
            0,
            $this->deuda_de($vacia),
            'Precondición: como la real, la compra vacía ya tiene su movimiento de cuenta corriente, con debe 0.'
        );

        $this->proponer_y_confirmar_la_compra();

        $this->assertEquals(
            1,
            ProviderOrder::where('user_id', $this->comercio->id)->where('provider_id', $this->proveedor->id)->count(),
            'Se reusa la compra vacía: no se puede haber creado otra.'
        );

        $vacia->refresh();

        $this->assertEquals(
            1,
            (int) $vacia->total_with_iva,
            'La compra vacía que se reusa queda sumando el IVA, como una nueva del asistente.'
        );

        $this->confirmar_el_escaneo_con_la_factura($vacia);

        $vacia->refresh();

        $this->assertEquals(21000, (float) $vacia->total_iva, 'El IVA de la factura queda en la compra.');

        $this->assertEquals(
            24000,
            (float) $vacia->total,
            'Total = $3.000 de artículos + $21.000 de IVA de la factura.'
        );

        $this->assertEquals(
            24000,
            $this->deuda_de($vacia),
            'La deuda con el proveedor es el total CON el IVA también en la compra reusada.'
        );
    }
}
