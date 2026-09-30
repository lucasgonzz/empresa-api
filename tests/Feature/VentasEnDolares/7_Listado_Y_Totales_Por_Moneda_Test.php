<?php

namespace Tests\Feature\VentasEnDolares;

use App\Models\Sale;
use Tests\EmpresaTestCase;

/**
 * Listado de ventas con totales por moneda (`GET api/sale/from-date/ventas/{dia}?per_page=N`,
 * `ListadoVentasHelper`), con ventas creadas por el endpoint real.
 *
 * LA REGLA. `totales.pesos` suma solo las ventas con `moneda_id = 1` y `totales.dolares` solo las de
 * `moneda_id = 2` (espejo de `Total.vue`): cada una expresada en SU moneda, sin convertir ni sumarse
 * entre sí, con `total`, `costos` (`sales.total_cost`) y `ganancia` (`sales.ganancia`). Los totales
 * SIN IVA (`total_sin_iva`, `costos_sin_iva`, `ventas_con_iva_sin_medir`) son solo de pesos: el IVA
 * de ARCA se declara en pesos y el helper lo dice en su docblock ("no hay una conversión honesta para
 * dólares"). Sin `per_page` el listado sigue devolviendo el array plano de siempre.
 *
 * Los valores esperados son los que persistieron las propias ventas (`sales.total`, `total_cost`,
 * `ganancia`): esta prueba mide la agregación por moneda, no el cálculo de cada venta (eso lo miden
 * `Venta_En_Pesos_Test` y `Venta_En_Dolares_Test`).
 *
 * @group ventas-en-dolares
 */
class Listado_Y_Totales_Por_Moneda_Test extends EmpresaTestCase
{
    use EscenarioDeMonedas;

    const DELTA = 0.005;

    /** Día exclusivo de esta clase (lejano, nadie más siembra ventas ahí). */
    const DIA = '2015-11-10';

    /** @var \App\Models\Article */
    protected $en_pesos;

    /** @var \App\Models\Article */
    protected $en_dolares;

    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_escenario_2r(self::DIA.' 10:00:00');

        $this->en_pesos   = $this->crear_articulo('zz Articulo pesos listado', ['cost' => 1000, 'cost_in_dollars' => 0, 'percentage_gain' => 50], 500);
        $this->en_dolares = $this->crear_articulo('zz Articulo dolares listado', ['cost' => 10, 'cost_in_dollars' => 1, 'percentage_gain' => 50], 500);
    }

    protected function tearDown(): void
    {
        $this->limpiar_escenario_2r();

        parent::tearDown();
    }

    /**
     * @param  int  $moneda_id
     * @param  float  $cant_pesos
     * @param  float  $cant_dolares
     * @return \App\Models\Sale
     */
    protected function vender($moneda_id, $cant_pesos, $cant_dolares)
    {
        return $this->guardar_venta($this->payload_venta($moneda_id, $this->VALOR_DOLAR, [
            $this->item($this->en_pesos, $cant_pesos, $this->price_vender_para($this->en_pesos, $moneda_id, $this->VALOR_DOLAR)),
            $this->item($this->en_dolares, $cant_dolares, $this->price_vender_para($this->en_dolares, $moneda_id, $this->VALOR_DOLAR)),
        ]));
    }

    /**
     * Pide el listado paginado del día de la clase y devuelve el body decodificado.
     *
     * @return array
     */
    protected function pedir_listado_paginado()
    {
        $response = $this->getJson('api/sale/from-date/ventas/'.self::DIA.'?per_page=50');

        $this->assertEquals(200, $response->getStatusCode(), 'El listado no devolvio 200. Cuerpo: '.substr($response->getContent(), 0, 400));

        return $response->json();
    }

    /**
     * Una venta en pesos y una en dólares el mismo día: cada una aparece SOLO en el total de su
     * moneda, con su total, su costo y su ganancia, y no se suman entre sí.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function una_venta_en_pesos_y_una_en_dolares_aparecen_cada_una_en_su_moneda_sin_sumarse()
    {
        $pesos = $this->vender(1, 2, 1);
        $usd   = $this->vender(2, 3, 2);

        $body    = $this->pedir_listado_paginado();
        $totales = $body['totales'];

        $this->assertEquals(2, $totales['cantidad']);

        $this->assertEqualsWithDelta((float) $pesos->total, (float) $totales['pesos']['total'], self::DELTA, 'totales.pesos.total tiene que ser SOLO la venta en pesos.');
        $this->assertEqualsWithDelta((float) $pesos->total_cost, (float) $totales['pesos']['costos'], self::DELTA);
        $this->assertEqualsWithDelta((float) $pesos->ganancia, (float) $totales['pesos']['ganancia'], self::DELTA);

        $this->assertEqualsWithDelta((float) $usd->total, (float) $totales['dolares']['total'], self::DELTA, 'totales.dolares.total tiene que ser SOLO la venta en dolares.');
        $this->assertEqualsWithDelta((float) $usd->total_cost, (float) $totales['dolares']['costos'], self::DELTA);
        $this->assertEqualsWithDelta((float) $usd->ganancia, (float) $totales['dolares']['ganancia'], self::DELTA);

        // Magnitudes: la de pesos es de miles y la de dolares de decenas. Si se hubieran sumado, el
        // total de pesos seria (pesos + dolares) o el de dolares (dolares + pesos).
        $this->assertGreaterThan(1000, (float) $totales['pesos']['total']);
        $this->assertLessThan(1000, (float) $totales['dolares']['total']);
    }

    /**
     * En cada moneda la ganancia del total cierra con total - costos (ventas de mostrador sin
     * comprobante: no hay IVA declarado que restar).
     *
     * @group ventas-en-dolares
     * @test
     */
    public function la_ganancia_de_cada_moneda_cierra_con_total_menos_costos()
    {
        $this->vender(1, 2, 1);
        $this->vender(2, 3, 2);

        $totales = $this->pedir_listado_paginado()['totales'];

        foreach (['pesos', 'dolares'] as $moneda) {
            $this->assertEqualsWithDelta(
                (float) $totales[$moneda]['total'] - (float) $totales[$moneda]['costos'],
                (float) $totales[$moneda]['ganancia'],
                0.011,
                'En '.$moneda.' la ganancia del listado no cierra con total - costos.'
            );
        }
    }

    /**
     * Dos ventas en dólares se suman entre sí y `totales.pesos` queda en cero: una venta en dólares
     * no puede filtrarse al total en pesos por ningún camino.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function dos_ventas_en_dolares_se_suman_entre_si_y_los_pesos_quedan_en_cero()
    {
        $a = $this->vender(2, 3, 2);
        $b = $this->vender(2, 1, 5);

        $totales = $this->pedir_listado_paginado()['totales'];

        $this->assertEquals(2, $totales['cantidad']);
        $this->assertEqualsWithDelta((float) $a->total + (float) $b->total, (float) $totales['dolares']['total'], self::DELTA);
        $this->assertEqualsWithDelta((float) $a->total_cost + (float) $b->total_cost, (float) $totales['dolares']['costos'], self::DELTA);
        $this->assertEqualsWithDelta(0, (float) $totales['pesos']['total'], self::DELTA);
        $this->assertEqualsWithDelta(0, (float) $totales['pesos']['costos'], self::DELTA);
        $this->assertEqualsWithDelta(0, (float) $totales['pesos']['ganancia'], self::DELTA);
    }

    /**
     * Los totales SIN IVA son solo de pesos: `total_sin_iva` y `costos_sin_iva` salen de la venta
     * en pesos (sin comprobante declara IVA 0, entra entera) y la venta en dólares no aporta nada
     * (el listado no tiene una conversión honesta a pesos para el IVA de ARCA).
     *
     * @group ventas-en-dolares
     * @test
     */
    public function los_totales_sin_iva_son_solo_de_pesos()
    {
        $pesos = $this->vender(1, 2, 1);
        $this->vender(2, 3, 2);

        $totales = $this->pedir_listado_paginado()['totales'];

        $this->assertEqualsWithDelta((float) $pesos->total, (float) $totales['pesos']['total_sin_iva'], self::DELTA, 'total_sin_iva incluyo la venta en dolares o perdio la de pesos.');
        $this->assertEqualsWithDelta((float) $pesos->total_cost, (float) $totales['pesos']['costos_sin_iva'], self::DELTA);
        $this->assertEquals(0, (int) $totales['pesos']['ventas_con_iva_sin_medir']);
        $this->assertArrayNotHasKey('total_sin_iva', $totales['dolares'], 'El IVA de ARCA se declara en pesos: no hay total_sin_iva en dolares.');
    }

    /**
     * Cada fila del listado paginado trae su `moneda_id` y su `valor_dolar`, para que la pantalla
     * muestre cada venta en su moneda.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function las_filas_del_listado_traen_su_moneda_y_su_valor_dolar()
    {
        $pesos = $this->vender(1, 2, 1);
        $usd   = $this->vender(2, 3, 2);

        $filas = [];

        foreach ($this->pedir_listado_paginado()['models']['data'] as $fila) {
            $filas[(int) $fila['id']] = $fila;
        }

        $this->assertArrayHasKey($pesos->id, $filas);
        $this->assertArrayHasKey($usd->id, $filas);

        $this->assertEquals(1, (int) $filas[$pesos->id]['moneda_id']);
        $this->assertEquals(2, (int) $filas[$usd->id]['moneda_id']);
        $this->assertEquals(1200, (int) $filas[$usd->id]['valor_dolar']);
        $this->assertEqualsWithDelta((float) $usd->total, (float) $filas[$usd->id]['total'], self::DELTA);
    }

    /**
     * Sin `per_page` el listado sigue devolviendo la lista plana de siempre (sin `totales`), y cada
     * venta trae su moneda y su total en su propia moneda.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function sin_per_page_el_listado_plano_trae_cada_venta_en_su_moneda()
    {
        $pesos = $this->vender(1, 2, 1);
        $usd   = $this->vender(2, 3, 2);

        $response = $this->getJson('api/sale/from-date/ventas/'.self::DIA);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertArrayNotHasKey('totales', $response->json());

        $filas = [];

        foreach ($response->json('models') as $fila) {
            $filas[(int) $fila['id']] = $fila;
        }

        $this->assertEquals(1, (int) $filas[$pesos->id]['moneda_id']);
        $this->assertEquals(2, (int) $filas[$usd->id]['moneda_id']);
        $this->assertEqualsWithDelta((float) $pesos->total, (float) $filas[$pesos->id]['total'], self::DELTA);
        $this->assertEqualsWithDelta((float) $usd->total, (float) $filas[$usd->id]['total'], self::DELTA);
    }

    /**
     * Eliminar la venta en dólares la saca del listado y de los totales en dólares (queda en cero),
     * y la de pesos no se toca.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function eliminar_la_venta_en_dolares_la_saca_de_sus_totales_y_no_toca_los_pesos()
    {
        $pesos = $this->vender(1, 2, 1);
        $usd   = $this->vender(2, 3, 2);

        $this->deleteJson('api/sale/'.$usd->id)->assertStatus(200);

        $totales = $this->pedir_listado_paginado()['totales'];

        $this->assertEquals(1, $totales['cantidad']);
        $this->assertEqualsWithDelta(0, (float) $totales['dolares']['total'], self::DELTA);
        $this->assertEqualsWithDelta((float) $pesos->total, (float) $totales['pesos']['total'], self::DELTA);
    }
}
