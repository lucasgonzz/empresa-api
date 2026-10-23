<?php

namespace Tests\Feature\ConsolidarFacturacion;

use App\Http\Controllers\Helpers\sale\ConsolidarFacturacionHelper;
use App\Models\AfipTicket;
use App\Models\Client;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Archivo 3 — UNA VENTA YA CONSOLIDADA NO SE FACTURA SUELTA
 * (mision cc-afip-ticket-rechaza-consolidada, 23/10/2026).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 EL HUECO QUE ESTE ARCHIVO CIERRA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  La venta ORIGINAL de una consolidacion no tiene factura propia: su comprobante es el de la
 *  venta contenedora. `POST api/afip-ticket` (SaleController::makeAfipTicket) no miraba
 *  `consolidacion_facturacion_id`: se podia emitir una factura suelta de una venta que ya estaba
 *  facturada dentro de una consolidacion, y quedaba facturada dos veces. `validar()` (archivo 2)
 *  cubre el orden inverso, facturar suelta y despues consolidar.
 *
 *  Reglas, iguales a las de `validar()`:
 *    - una original con la contenedora VIVA no se factura suelta;
 *    - una original cuya contenedora se borro si (nada la sostiene);
 *    - la propia contenedora si: es como se reintenta el comprobante de una consolidacion;
 *    - una venta comun no cambia.
 *
 *  🔴 Un POST a `afip-ticket` que pasara la guarda emitiria contra ARCA de verdad. Por eso el
 *  rechazo se prueba por el endpoint con un `ventas_afip_information_id` inexistente (0): si algun
 *  dia la guarda se rompiera, el pedido muere en `MakeAfipTicket` al leer la configuracion fiscal,
 *  ANTES de cualquier llamada a la red (y el test falla igual por el 500). Los casos que SI se
 *  facturan se prueban sobre el motivo que calcula el helper, sin emitir.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 *
 * @group consolidar_facturacion
 */
class Facturar_suelta_una_venta_consolidada_Test extends EmpresaTestCase
{
    /**
     * Test 1 — Una original con la contenedora viva se rechaza con 422 y el mensaje nombra la
     * consolidacion; no se crea ningun comprobante.
     *
     * @test
     */
    public function una_venta_consolidada_no_se_factura_suelta()
    {
        $contenedora = $this->contenedora(['num' => 9001]);
        $original = $this->venta(['num' => 9002, 'consolidacion_facturacion_id' => $contenedora->id]);

        $respuesta = $this->facturar_por_endpoint($original->id);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('está incluida en la consolidación N° 9001', $respuesta->json('message'));
        $this->assertStringContainsString('Ver consolidadas', $respuesta->json('message'));
        $this->assertEquals(0, AfipTicket::where('sale_id', $original->id)->count(), 'No se crea ningun comprobante.');
    }

    /**
     * Test 2 — La guarda va antes que las demas validaciones del pedido (importe personalizado):
     * un POST con importe y reparto invalidos recibe el rechazo por la consolidacion, no el del
     * importe.
     *
     * @test
     */
    public function la_guarda_va_antes_que_la_validacion_del_importe()
    {
        $contenedora = $this->contenedora(['num' => 9011]);
        $original = $this->venta(['consolidacion_facturacion_id' => $contenedora->id]);

        $respuesta = $this->facturar_por_endpoint($original->id, [
            'monto_a_facturar'           => 500,
            'importe_personalizado_ivas' => [['alicuota' => 'no-es-una-alicuota', 'importe' => 'x']],
        ]);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('está incluida en la consolidación N° 9011', $respuesta->json('message'));
    }

    /**
     * Test 3 — Lo que el flujo de Ventas y de Vender manda todos los dias no cambia: una venta
     * comun (sin consolidacion) se puede facturar.
     *
     * @test
     */
    public function una_venta_comun_se_sigue_pudiendo_facturar()
    {
        $comun = $this->venta();

        $this->assertNull(ConsolidarFacturacionHelper::motivo_por_el_que_no_se_puede_facturar_suelta($comun));
    }

    /**
     * Test 4 — La propia contenedora si se factura por este camino: es como se reintenta el
     * comprobante de una consolidacion que quedo sin CAE.
     *
     * @test
     */
    public function la_contenedora_si_se_puede_facturar()
    {
        $contenedora = $this->contenedora();

        // Con un intento anterior sin CAE, que es justo el caso del reintento.
        AfipTicket::create(['sale_id' => $contenedora->id, 'cae' => null, 'resultado' => 'R']);

        $this->assertNull(ConsolidarFacturacionHelper::motivo_por_el_que_no_se_puede_facturar_suelta($contenedora->fresh()));
    }

    /**
     * Test 5 — Si la contenedora se borro, sus ventas originales vuelven a poder facturarse; con la
     * contenedora viva, no.
     *
     * @test
     */
    public function las_ventas_de_una_contenedora_borrada_se_pueden_facturar()
    {
        $contenedora = $this->contenedora();
        $original = $this->venta(['consolidacion_facturacion_id' => $contenedora->id]);

        $this->assertNotNull(ConsolidarFacturacionHelper::motivo_por_el_que_no_se_puede_facturar_suelta($original->fresh()));

        $contenedora->delete();

        $this->assertNull(ConsolidarFacturacionHelper::motivo_por_el_que_no_se_puede_facturar_suelta($original->fresh()));
    }

    /**
     * Test 6 — Sin numero de venta el mensaje usa el id (nunca "La venta N° " en blanco).
     *
     * @test
     */
    public function el_mensaje_usa_el_id_si_la_venta_no_tiene_numero()
    {
        $contenedora = $this->contenedora();
        $original = $this->venta(['consolidacion_facturacion_id' => $contenedora->id]);

        $motivo = ConsolidarFacturacionHelper::motivo_por_el_que_no_se_puede_facturar_suelta($original->fresh());

        $this->assertStringContainsString('La venta N° '.$original->id.' está incluida', $motivo);
        $this->assertStringContainsString('consolidación N° '.$contenedora->id, $motivo);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return \App\Models\User
     */
    protected function comercio()
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->assertNotNull($user, 'Falta el usuario del fixture.');

        return $user;
    }

    /**
     * @return \App\Models\Client
     */
    protected function cliente()
    {
        $client = Client::where('name', TestingFerreteriaSeeder::CLIENTE_CC)->first();

        $this->assertNotNull($client, 'Falta el cliente "'.TestingFerreteriaSeeder::CLIENTE_CC.'" del fixture.');

        return $client;
    }

    /**
     * Una venta terminada del cliente del fixture.
     *
     * @param  array  $campos  Para pisar columnas.
     * @return \App\Models\Sale
     */
    protected function venta($campos = [])
    {
        return Sale::create(array_merge([
            'user_id'                    => $this->comercio()->id,
            'client_id'                  => $this->cliente()->id,
            'omitir_en_cuenta_corriente' => 1,
            'save_current_acount'        => 0,
            'terminada'                  => 1,
            'is_cerrada'                 => 0,
            'sub_total'                  => 1000,
            'total'                      => 1000,
            'moneda_id'                  => 1,
            'descuento'                  => 0,
        ], $campos));
    }

    /**
     * Una venta contenedora ya creada.
     *
     * @param  array  $campos
     * @return \App\Models\Sale
     */
    protected function contenedora($campos = [])
    {
        return $this->venta(array_merge(['is_consolidacion_facturacion' => 1], $campos));
    }

    /**
     * Le pega al endpoint real con un punto de venta inexistente (ver el encabezado: es lo que
     * garantiza que, si la guarda se rompiera, el pedido no llegue a ARCA).
     *
     * @param  int    $sale_id
     * @param  array  $extra   Campos del pedido (importe personalizado, etc.).
     * @return \Illuminate\Testing\TestResponse
     */
    protected function facturar_por_endpoint($sale_id, $extra = [])
    {
        return $this->postJson('api/afip-ticket', array_merge([
            'sale_id'                    => $sale_id,
            'ventas_afip_information_id' => 0,
            'afip_tipo_comprobante_id'   => 0,
        ], $extra));
    }
}
