<?php

namespace Tests\Feature\VentasEnDolares;

use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Estado de Resultados (`GET api/reportes/estado-resultados`) y su detalle (`GET api/reportes/detalle`)
 * con ventas en pesos y en dólares creadas por el endpoint real.
 *
 * LO QUE HACE EL REPORTE CON `moneda_id` (`ContabilidadRepository`):
 *
 *  - `moneda=pesos` (default): suma las ventas con `moneda_id` 0, NULL o 1; `moneda=dolares`: solo
 *    `moneda_id = 2`. Nunca se suman entre sí (`aplicar_filtro_moneda()`).
 *  - `moneda=consolidado` (solo con la extensión `ventas_en_dolares`): pesos + dólares convertidos
 *    con la cotización GLOBAL de la cuenta (`users.dollar`), y se marca `cotizacion_estimada = true`
 *    (no usa el `valor_dolar` con el que se hizo cada venta).
 *  - El costo de mercadería sale de `article_sale.cost * amount` filtrado por la moneda de la venta.
 *  - Una venta borrada (soft delete) no entra.
 *
 * Un solo día lejano para toda la clase (`DIA`), con reloj fijo.
 *
 * @group ventas-en-dolares
 */
class Reportes_Por_Moneda_Test extends EmpresaTestCase
{
    use EscenarioDeMonedas;

    const DELTA = 0.011;

    /** Día exclusivo de esta clase (lejano, nadie más siembra ventas ahí). */
    const DIA = '2015-12-10';

    /** @var \App\Models\Article */
    protected $en_pesos;

    /** @var \App\Models\Article */
    protected $en_dolares;

    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_escenario_2r(self::DIA.' 10:00:00', ['dollar' => 1000]);

        $this->en_pesos   = $this->crear_articulo('zz Articulo pesos reporte', ['cost' => 1000, 'cost_in_dollars' => 0, 'percentage_gain' => 50], 500);
        $this->en_dolares = $this->crear_articulo('zz Articulo dolares reporte', ['cost' => 10, 'cost_in_dollars' => 1, 'percentage_gain' => 50], 500);
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
     * @param  string  $moneda  'pesos' | 'dolares' | 'consolidado'
     * @return array  El bloque `estado_resultados`.
     */
    protected function estado($moneda)
    {
        $response = $this->getJson('api/reportes/estado-resultados?desde='.self::DIA.'&hasta='.self::DIA.'&moneda='.$moneda);

        $this->assertEquals(200, $response->getStatusCode(), 'estado-resultados no devolvio 200. Cuerpo: '.substr($response->getContent(), 0, 400));

        return $response->json('estado_resultados');
    }

    /**
     * @param  string  $concepto
     * @param  string  $moneda
     * @return array
     */
    protected function detalle($concepto, $moneda)
    {
        $query = http_build_query([
            'concepto' => $concepto,
            'desde'    => self::DIA,
            'hasta'    => self::DIA,
            'moneda'   => $moneda,
        ]);

        $response = $this->getJson('api/reportes/detalle?'.$query);

        $this->assertEquals(200, $response->getStatusCode(), 'reportes/detalle no devolvio 200. Cuerpo: '.substr($response->getContent(), 0, 400));

        return $response->json();
    }

    /**
     * Una venta en pesos y otra en dólares el mismo día: el reporte en pesos trae SOLO la de pesos
     * (ventas brutas y costo de mercadería) y el reporte en dólares SOLO la de dólares.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function el_reporte_en_pesos_no_cuenta_la_venta_en_dolares_ni_al_reves()
    {
        $pesos = $this->vender(1, 2, 1);
        $usd   = $this->vender(2, 3, 2);

        $en_pesos = $this->estado('pesos');
        $en_usd   = $this->estado('dolares');

        $this->assertEquals('pesos', $en_pesos['moneda']);
        $this->assertEquals('dolares', $en_usd['moneda']);

        $this->assertEqualsWithDelta((float) $pesos->total, (float) $en_pesos['ventas_brutas'], self::DELTA, 'El reporte en pesos no coincide con la venta en pesos (¿sumo la de dolares?).');
        $this->assertEqualsWithDelta((float) $pesos->total_cost, (float) $en_pesos['costo_mercaderia_vendida'], self::DELTA);

        $this->assertEqualsWithDelta((float) $usd->total, (float) $en_usd['ventas_brutas'], self::DELTA, 'El reporte en dolares no coincide con la venta en dolares (¿sumo la de pesos?).');
        $this->assertEqualsWithDelta((float) $usd->total_cost, (float) $en_usd['costo_mercaderia_vendida'], self::DELTA);
    }

    /**
     * En cada moneda la cascada cierra: ventas netas - costo neto de mercadería = resultado bruto.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function el_resultado_bruto_de_cada_moneda_cierra_con_ventas_menos_costo()
    {
        $this->vender(1, 2, 1);
        $this->vender(2, 3, 2);

        foreach (['pesos', 'dolares'] as $moneda) {
            $e = $this->estado($moneda);

            $this->assertEqualsWithDelta(
                (float) $e['ventas_netas'] - ((float) $e['costo_mercaderia_vendida'] - (float) $e['costo_mercaderia_devuelta']),
                (float) $e['resultado_bruto'],
                self::DELTA,
                'La cascada del reporte en '.$moneda.' no cierra.'
            );
        }
    }

    /**
     * CARACTERIZACIÓN del consolidado: pesos + dólares * `users.dollar` (1000 en esta prueba, el
     * dólar GLOBAL de la cuenta) y `cotizacion_estimada = true`. Notar que la venta en dólares se
     * hizo con `valor_dolar` 1200: el consolidado NO usa ese valor por venta sino el global (por eso
     * se marca como estimado). Se documenta acá; no es un error del sistema.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function el_consolidado_convierte_los_dolares_con_el_dolar_global_y_se_marca_estimado()
    {
        $pesos = $this->vender(1, 2, 1);
        $usd   = $this->vender(2, 3, 2);

        $e = $this->estado('consolidado');

        $this->assertEquals('consolidado', $e['moneda']);
        $this->assertTrue((bool) $e['cotizacion_estimada'], 'El consolidado convierte con el dolar global y tiene que marcarse como estimado.');

        $this->assertEqualsWithDelta(
            (float) $pesos->total + (float) $usd->total * 1000,
            (float) $e['ventas_brutas'],
            1.0,
            'ventas_brutas consolidado = pesos + dolares * users.dollar.'
        );
        $this->assertEqualsWithDelta(
            (float) $pesos->total_cost + (float) $usd->total_cost * 1000,
            (float) $e['costo_mercaderia_vendida'],
            1.0
        );
    }

    /**
     * El detalle (drill-down) de ventas brutas por moneda lista SOLO las ventas de esa moneda, y su
     * suma coincide con la tarjeta del reporte de esa misma moneda.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function el_detalle_de_ventas_lista_solo_las_ventas_de_la_moneda_y_coincide_con_la_tarjeta()
    {
        $pesos = $this->vender(1, 2, 1);
        $usd   = $this->vender(2, 3, 2);

        $detalle_pesos = $this->detalle('ventas_brutas', 'pesos');
        $detalle_usd   = $this->detalle('ventas_brutas', 'dolares');

        $this->assertEquals([$pesos->id], array_column($detalle_pesos['registros'], 'link_id'), 'El detalle en pesos tiene que listar solo la venta en pesos.');
        $this->assertEquals([$usd->id], array_column($detalle_usd['registros'], 'link_id'), 'El detalle en dolares tiene que listar solo la venta en dolares.');

        $this->assertEqualsWithDelta((float) $this->estado('pesos')['ventas_brutas'], (float) $detalle_pesos['total'], self::DELTA);
        $this->assertEqualsWithDelta((float) $this->estado('dolares')['ventas_brutas'], (float) $detalle_usd['total'], self::DELTA);
    }

    /**
     * Eliminar la venta en dólares la saca del reporte en dólares (queda en cero) y no mueve el de
     * pesos.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function una_venta_eliminada_sale_del_reporte_de_su_moneda()
    {
        $pesos = $this->vender(1, 2, 1);
        $usd   = $this->vender(2, 3, 2);

        $this->deleteJson('api/sale/'.$usd->id)->assertStatus(200);

        $this->assertEqualsWithDelta(0, (float) $this->estado('dolares')['ventas_brutas'], self::DELTA);
        $this->assertEqualsWithDelta(0, (float) $this->estado('dolares')['costo_mercaderia_vendida'], self::DELTA);
        $this->assertEqualsWithDelta((float) $pesos->total, (float) $this->estado('pesos')['ventas_brutas'], self::DELTA);
    }

    /**
     * DECISIÓN DE LUCAS (30/9/2026): una venta con `moneda_id` NULL se trata SIEMPRE como pesos, en
     * todas las pantallas. Antes se contaba como pesos en el Estado de Resultados
     * (`ContabilidadRepository::aplicar_filtro_moneda()`: "0, null y 1 son pesos") pero NO sumaba en
     * ningún chip del listado de ventas (`ListadoVentasHelper::agregados()` comparaba `moneda_id = 1`).
     * Se arma con una venta hecha por API y dejada con `moneda_id` NULL directo en la base (así están
     * los datos viejos): el listado tiene que sumarla en pesos igual que cuando tenía moneda 1, no
     * sumarla en dólares, y coincidir con el reporte.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function una_venta_sin_moneda_cuenta_como_pesos_en_el_listado_y_en_el_reporte()
    {
        $venta = $this->vender(1, 2, 1);

        $antes = $this->getJson('api/sale/from-date/ventas/'.self::DIA.'?per_page=50');
        $pesos_antes = (float) $antes->json('totales.pesos.total');
        $costos_antes = (float) $antes->json('totales.pesos.costos');
        $ganancia_antes = (float) $antes->json('totales.pesos.ganancia');

        DB::table('sales')->where('id', $venta->id)->update(['moneda_id' => null]);

        $reporte_pesos = (float) $this->estado('pesos')['ventas_brutas'];

        $response = $this->getJson('api/sale/from-date/ventas/'.self::DIA.'?per_page=50');
        $listado_pesos = (float) $response->json('totales.pesos.total');
        $listado_dolares = (float) $response->json('totales.dolares.total');

        $this->assertGreaterThan(0, $pesos_antes, 'La venta de prueba tiene que sumar en pesos antes de dejarla sin moneda.');
        $this->assertEqualsWithDelta($pesos_antes, $listado_pesos, self::DELTA, 'Sin moneda la venta sigue sumando en pesos en el listado.');
        $this->assertEqualsWithDelta($costos_antes, (float) $response->json('totales.pesos.costos'), self::DELTA, 'Los costos tambien.');
        $this->assertEqualsWithDelta($ganancia_antes, (float) $response->json('totales.pesos.ganancia'), self::DELTA, 'La ganancia tambien.');
        $this->assertEqualsWithDelta(0.0, $listado_dolares, self::DELTA, 'Una venta sin moneda nunca suma en dolares.');
        $this->assertEqualsWithDelta($reporte_pesos, $listado_pesos, self::DELTA, 'El listado y el Estado de Resultados dicen lo mismo.');
    }
}
