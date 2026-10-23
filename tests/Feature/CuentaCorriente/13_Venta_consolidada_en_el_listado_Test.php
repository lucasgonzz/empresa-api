<?php

namespace Tests\Feature\CuentaCorriente;

use App\Models\AfipTicket;
use Carbon\Carbon;
use Tests\EmpresaTestCase;

/**
 * Misión cc-consolidar-desde-cuenta-corriente (23/10/2026) — el listado de la cuenta corriente
 * dice en qué factura quedó consolidada una venta.
 *
 * `GET api/current-acount/{credit_account_id}/{cantidad}` ya devolvía `sale.afip_tickets`. La
 * factura de una venta consolidada no es de ella sino de la venta CONTENEDORA
 * (`sales.consolidacion_facturacion_id`), así que la fila no tenía de dónde sacar el número. Ahora
 * suma `sale.consolidacion_facturacion.afip_tickets`.
 *
 * Es una clave nueva y opcional dentro de `sale`: una venta sin consolidar la trae en null y el
 * resto de la respuesta no cambia. Se prueba por el endpoint real, en las dos formas del listado
 * (los últimos N y el período por fecha).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group cuenta-corriente
 */
class Venta_consolidada_en_el_listado_Test extends EmpresaTestCase
{
    use ArmaCadenas;

    /** @var \App\Models\Client */
    protected $cliente;

    /** @var \App\Models\CreditAccount */
    protected $cuenta;

    protected function setUp(): void
    {
        parent::setUp();

        list($this->cliente, $this->cuenta) = $this->cliente_con_cuenta($this->app['auth']->user()->id, 'Consolidada');
    }

    /**
     * Una venta del cliente con su movimiento en la cuenta corriente.
     *
     * @param  float  $total
     * @param  array  $campos_de_la_venta  Para pisar columnas de la venta (la contenedora, por ejemplo).
     * @return array{0: \App\Models\Sale, 1: \App\Models\CurrentAcount}
     */
    protected function venta_con_movimiento($total, $campos_de_la_venta = [])
    {
        $venta = $this->venta($this->cliente, $total, Carbon::now());

        if (!empty($campos_de_la_venta)) {
            $venta->update($campos_de_la_venta);
        }

        $movimiento = $this->movimiento($this->cuenta, [
            'debe'       => $total,
            'sale_id'    => $venta->id,
            'detalle'    => 'Venta N°'.$venta->num,
            'created_at' => Carbon::now(),
        ]);

        return [$venta, $movimiento];
    }

    /**
     * La contenedora de una consolidación, ya facturada (con su comprobante).
     *
     * @param  array|null  $ticket  Campos del comprobante, o null para una contenedora sin comprobante.
     * @return \App\Models\Sale
     */
    protected function contenedora($ticket = [])
    {
        $contenedora = $this->venta($this->cliente, 300, Carbon::now());
        $contenedora->update([
            'is_consolidacion_facturacion' => 1,
            'omitir_en_cuenta_corriente'   => 1,
            'save_current_acount'          => 0,
        ]);

        if (!is_null($ticket)) {
            AfipTicket::create(array_merge([
                'sale_id'     => $contenedora->id,
                'cae'         => '71234567890123',
                'cbte_numero' => 183,
                'punto_venta' => '1',
                'resultado'   => 'A',
            ], $ticket));
        }

        return $contenedora;
    }

    /**
     * @param  string  $query
     * @return \Illuminate\Testing\TestResponse
     */
    protected function listar($query = '')
    {
        return $this->getJson('api/current-acount/'.$this->cuenta->id.'/20'.($query ? '?'.$query : ''));
    }

    /**
     * @param  \Illuminate\Testing\TestResponse  $respuesta
     * @param  int                               $movimiento_id
     * @return array
     */
    protected function fila($respuesta, $movimiento_id)
    {
        foreach ($respuesta->json('models') as $modelo) {
            if ($modelo['id'] == $movimiento_id) {
                return $modelo;
            }
        }

        $this->fail('El movimiento '.$movimiento_id.' no está en el listado.');
    }

    /**
     * Test 1 — La fila de una venta consolidada trae la contenedora y su factura.
     *
     * @test
     */
    public function la_venta_consolidada_trae_la_contenedora_con_su_factura()
    {
        $contenedora = $this->contenedora();

        list($venta, $movimiento) = $this->venta_con_movimiento(100, ['consolidacion_facturacion_id' => $contenedora->id]);

        $fila = $this->fila($this->listar()->assertStatus(200), $movimiento->id);

        $this->assertEquals($contenedora->id, $fila['sale']['consolidacion_facturacion_id']);
        $this->assertEquals($contenedora->id, $fila['sale']['consolidacion_facturacion']['id']);
        $this->assertEquals($contenedora->num, $fila['sale']['consolidacion_facturacion']['num']);
        $this->assertCount(1, $fila['sale']['consolidacion_facturacion']['afip_tickets']);
        $this->assertEquals(183, $fila['sale']['consolidacion_facturacion']['afip_tickets'][0]['cbte_numero']);
        $this->assertEquals('71234567890123', $fila['sale']['consolidacion_facturacion']['afip_tickets'][0]['cae']);
    }

    /**
     * Test 2 — Una contenedora todavía sin comprobante viaja igual, con la lista de tickets vacía:
     * la SPA tiene que poder decir "consolidada en la venta N° X" aunque aún no haya factura.
     *
     * @test
     */
    public function la_contenedora_sin_comprobante_viaja_con_tickets_vacios()
    {
        $contenedora = $this->contenedora(null);

        list($venta, $movimiento) = $this->venta_con_movimiento(100, ['consolidacion_facturacion_id' => $contenedora->id]);

        $fila = $this->fila($this->listar()->assertStatus(200), $movimiento->id);

        $this->assertEquals($contenedora->id, $fila['sale']['consolidacion_facturacion']['id']);
        $this->assertSame([], $fila['sale']['consolidacion_facturacion']['afip_tickets']);
    }

    /**
     * Test 3 — Una venta sin consolidar no cambia: la clave nueva viene en null y lo de siempre
     * (sus propios comprobantes) sigue estando.
     *
     * @test
     */
    public function la_venta_sin_consolidar_trae_la_clave_nueva_en_null()
    {
        list($venta, $movimiento) = $this->venta_con_movimiento(100);

        AfipTicket::create(['sale_id' => $venta->id, 'cae' => '71234567890999', 'cbte_numero' => 55, 'resultado' => 'A']);

        $fila = $this->fila($this->listar()->assertStatus(200), $movimiento->id);

        $this->assertNull($fila['sale']['consolidacion_facturacion_id']);
        $this->assertNull($fila['sale']['consolidacion_facturacion']);
        $this->assertCount(1, $fila['sale']['afip_tickets']);
    }

    /**
     * Test 4 — El listado por período (`desde`) trae lo mismo: es otro camino de código.
     *
     * @test
     */
    public function el_listado_por_periodo_tambien_trae_la_contenedora()
    {
        $contenedora = $this->contenedora();

        list($venta, $movimiento) = $this->venta_con_movimiento(100, ['consolidacion_facturacion_id' => $contenedora->id]);

        $respuesta = $this->listar('desde='.Carbon::now()->subDays(2)->format('Y-m-d'))->assertStatus(200);

        $fila = $this->fila($respuesta, $movimiento->id);

        $this->assertEquals($contenedora->id, $fila['sale']['consolidacion_facturacion']['id']);
        $this->assertEquals(183, $fila['sale']['consolidacion_facturacion']['afip_tickets'][0]['cbte_numero']);
    }
}
