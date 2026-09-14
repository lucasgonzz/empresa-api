<?php

namespace Tests\Feature\Sales;

use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * `Sale::scopeEnRangoDeFechas` con la preferencia de fecha de pedido APAGADA: un dia (o un rango
 * de dias) se pide como `created_at >= 'X 00:00:00' AND created_at < 'X+1 00:00:00'` en vez de
 * `DATE(created_at) = X`, para que MySQL pueda usar `sales_user_id_created_at_idx`.
 *
 * Lo que se protege:
 *
 *   1. Las filas son LAS MISMAS que con DATE(): el `23:59:59` del dia entra y el `00:00:00` del dia
 *      siguiente queda afuera, en un dia solo y en un rango.
 *   2. El SQL del camino apagado NO lleva `DATE(` (si vuelve, vuelve el barrido de la tabla).
 *   3. El camino del grafico (`$comparar_solo_la_fecha = false`) y el camino prendido no cambian.
 *   4. Un Carbon de entrada se normaliza al arranque del dia y no se muta.
 */
class Rango_De_Fechas_Usa_Limites_Del_Dia_Test extends TestCase
{
    // DatabaseTransactions (no RefreshDatabase): la base de testing esta sembrada y compartida por
    // el slot. Mismo criterio que 18_Fecha_De_Pedido_En_Reportes_Test.
    use DatabaseTransactions;

    public $user_id = 500;

    /** Dias fijos y lejanos, distintos de los de las otras suites de ventas. */
    public $dia = '2037-06-20';
    public $dia_siguiente = '2037-06-21';
    public $dia_anterior = '2037-06-18';

    protected function setUp(): void
    {
        parent::setUp();

        /* Preferencia APAGADA: es el camino que cambia de forma en esta mision. */
        $user = User::find($this->user_id);
        $user->fechar_ventas_por_fecha_de_entrega = 0;
        $user->save();

        $this->actingAs($user, 'web');
    }

    /**
     * Un solo dia: entra todo el dia, incluido el ultimo segundo, y no entra el primer segundo del
     * dia siguiente.
     *
     * @group sales
     * @test
     */
    public function un_dia_incluye_su_ultimo_segundo_y_excluye_el_primero_del_siguiente()
    {
        $primer_segundo = $this->crear_venta($this->dia . ' 00:00:00')->id;
        $ultimo_segundo = $this->crear_venta($this->dia . ' 23:59:59')->id;
        $del_siguiente  = $this->crear_venta($this->dia_siguiente . ' 00:00:00')->id;

        $listado = $this->listado($this->dia);
        $this->assertContains($primer_segundo, $listado);
        $this->assertContains($ultimo_segundo, $listado,
            'La venta de las 23:59:59 es del dia: con el limite superior exclusivo del dia siguiente tiene que entrar.');
        $this->assertNotContains($del_siguiente, $listado,
            'El 00:00:00 del dia siguiente NO es del dia: el limite superior tiene que ser estricto (<).');

        $listado_siguiente = $this->listado($this->dia_siguiente);
        $this->assertContains($del_siguiente, $listado_siguiente);
        $this->assertNotContains($ultimo_segundo, $listado_siguiente);
    }

    /**
     * Un rango A..B: entra desde el primer segundo de A hasta el ultimo de B.
     *
     * @group sales
     * @test
     */
    public function un_rango_incluye_el_ultimo_segundo_del_ultimo_dia()
    {
        $primer_segundo_de_a = $this->crear_venta($this->dia_anterior . ' 00:00:00')->id;
        $del_medio           = $this->crear_venta('2037-06-19 15:30:00')->id;
        $ultimo_segundo_de_b = $this->crear_venta($this->dia . ' 23:59:59')->id;
        $despues_de_b        = $this->crear_venta($this->dia_siguiente . ' 00:00:00')->id;
        $antes_de_a          = $this->crear_venta('2037-06-17 23:59:59')->id;

        $listado = $this->listado($this->dia_anterior, $this->dia);
        $this->assertContains($primer_segundo_de_a, $listado);
        $this->assertContains($del_medio, $listado);
        $this->assertContains($ultimo_segundo_de_b, $listado,
            'La venta de las 23:59:59 del ultimo dia del rango tiene que entrar.');
        $this->assertNotContains($despues_de_b, $listado);
        $this->assertNotContains($antes_de_a, $listado);
    }

    /**
     * El SQL del camino apagado no lleva `DATE(`: son dos comparaciones directas sobre `created_at`
     * con los limites del dia como binds.
     *
     * @group sales
     * @test
     */
    public function el_sql_del_camino_apagado_no_lleva_date()
    {
        $query = Sale::where('user_id', $this->user_id)->enRangoDeFechas($this->dia, null, $this->user_id);

        $this->assertFalse(stripos($query->toSql(), 'date(') !== false,
            'Con la preferencia apagada el scope no puede emitir DATE(created_at): esa forma no usa el indice.');
        $this->assertSame([$this->user_id, $this->dia . ' 00:00:00', $this->dia_siguiente . ' 00:00:00'], $query->getBindings());
        $this->assertMatchesRegularExpression('/`created_at` >= \? and `created_at` < \?/', $query->toSql());

        $rango = Sale::where('user_id', $this->user_id)->enRangoDeFechas($this->dia_anterior, $this->dia, $this->user_id);

        $this->assertFalse(stripos($rango->toSql(), 'date(') !== false);
        $this->assertSame([$this->user_id, $this->dia_anterior . ' 00:00:00', $this->dia_siguiente . ' 00:00:00'], $rango->getBindings());
    }

    /**
     * El camino del grafico (`$comparar_solo_la_fecha = false`) sigue comparando el datetime
     * completo con los binds tal cual llegan, como siempre.
     *
     * @group sales
     * @test
     */
    public function el_camino_del_grafico_no_cambia()
    {
        $desde = $this->dia_anterior . ' 00:00:00';
        $hasta = $this->dia . ' 23:59:59';

        $query = Sale::where('user_id', $this->user_id)->enRangoDeFechas($desde, $hasta, $this->user_id, false);

        $this->assertMatchesRegularExpression('/`created_at` >= \? and `created_at` <= \?/', $query->toSql());
        $this->assertSame([$this->user_id, $desde, $hasta], $query->getBindings());

        $un_instante = Sale::where('user_id', $this->user_id)->enRangoDeFechas($desde, null, $this->user_id, false);

        $this->assertMatchesRegularExpression('/`created_at` = \?/', $un_instante->toSql());
        $this->assertSame([$this->user_id, $desde], $un_instante->getBindings());
    }

    /**
     * El camino prendido (fecha de pedido) no se toca: sigue con el COALESCE y su DATE().
     *
     * @group sales
     * @test
     */
    public function el_camino_prendido_no_cambia()
    {
        $user = User::find($this->user_id);
        $user->fechar_ventas_por_fecha_de_entrega = 1;
        $user->save();

        $query = Sale::where('user_id', $this->user_id)->enRangoDeFechas($this->dia, null, $this->user_id);

        $this->assertStringContainsStringIgnoringCase('COALESCE(sales.fecha_entrega, sales.created_at)', $query->toSql());
        $this->assertStringContainsStringIgnoringCase('DATE(', $query->toSql());
        $this->assertSame([$this->user_id, $this->dia], $query->getBindings());
    }

    /**
     * Un Carbon de entrada (como los `mes_inicio` / `mes_fin` de PerformanceHelper, que traen
     * hora) se normaliza al arranque del dia, y el objeto del llamador no se muta.
     *
     * @group sales
     * @test
     */
    public function un_carbon_de_entrada_se_normaliza_al_dia_y_no_se_muta()
    {
        $desde = Carbon::parse($this->dia_anterior . ' 10:15:00');
        $hasta = Carbon::parse($this->dia . ' 23:59:59');

        $query = Sale::where('user_id', $this->user_id)->enRangoDeFechas($desde, $hasta, $this->user_id);

        $this->assertSame([$this->user_id, $this->dia_anterior . ' 00:00:00', $this->dia_siguiente . ' 00:00:00'], $query->getBindings());

        $this->assertSame($this->dia_anterior . ' 10:15:00', $desde->format('Y-m-d H:i:s'),
            'El scope no puede mutar el Carbon del llamador: PerformanceHelper lo reusa despues.');
        $this->assertSame($this->dia . ' 23:59:59', $hasta->format('Y-m-d H:i:s'));

        /* Y con Carbon entra la misma fila que con el string. */
        $ultimo_segundo = $this->crear_venta($this->dia . ' 23:59:59')->id;
        $this->assertContains($ultimo_segundo, $query->pluck('id')->all());
    }

    /**
     * Venta minima, terminada, con `created_at` en el instante dado.
     *
     * @param  string $created_at  `Y-m-d H:i:s`
     * @return Sale
     */
    function crear_venta($created_at)
    {
        return Sale::create([
            'user_id'                      => $this->user_id,
            'address_id'                   => 1,
            'moneda_id'                    => 1,
            'total'                        => 100,
            'sub_total'                    => 100,
            'terminada'                    => 1,
            'confirmed'                    => 1,
            'omitir_en_cuenta_corriente'   => 1,
            'is_consolidacion_facturacion' => 0,
            'created_at'                   => $created_at,
            'terminada_at'                 => $created_at,
        ]);
    }

    /**
     * Ids que devuelve el listado de ventas (SaleController::index, camino sin paginar) para ese
     * dia o rango. Pasa por el endpoint a proposito: es el scope aplicado donde lo usa el comercio.
     *
     * @param  string      $desde
     * @param  string|null $hasta
     * @return array
     */
    function listado($desde, $hasta = null)
    {
        $url = 'api/sale/from-date/ventas/' . $desde . (is_null($hasta) ? '' : '/' . $hasta);

        $response = $this->getJson($url);
        $response->assertStatus(200);

        return array_column($response->json('models'), 'id');
    }
}
