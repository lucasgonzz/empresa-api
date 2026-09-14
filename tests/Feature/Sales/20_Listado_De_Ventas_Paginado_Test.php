<?php

namespace Tests\Feature\Sales;

use App\Http\Controllers\Helpers\sale\ListadoVentasHelper;
use App\Models\AfipTicket;
use App\Models\CurrentAcount;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Modo paginado del listado de ventas por fecha (`GET api/sale/from-date/{modulo}/{from_date?}/{until_date?}`).
 *
 * Lo que se protege aca, en orden de importancia:
 *
 *   1. Sin `per_page` la respuesta es la de siempre: `models` es un array plano y no hay `totales`.
 *      Es lo que siguen leyendo `por_entregar`, `por_estado`, `deposito` y cualquier SPA vieja.
 *   2. Con `per_page`, las paginas no se pisan ni se saltean filas aunque varias ventas compartan
 *      `created_at` (el desempate por id). Es el candado de esta suite: se puso rojo al sacar el
 *      `orderBy('sales.id', 'DESC')` del helper.
 *   3. Los totales por moneda salen de las columnas persistidas y espejan `Total.vue`.
 *   4. Cada filtro de pantalla (solapas y show options) afecta las filas Y `totales.cantidad`.
 *   5. Los contadores de las solapas se calculan sobre la base, no sobre lo filtrado.
 *   6. `per_page` fuera de rango cae al default (25) o al techo (200).
 */
class Listado_De_Ventas_Paginado_Test extends TestCase
{
    // DatabaseTransactions (no RefreshDatabase): la base de testing esta sembrada y compartida por
    // el slot. Mismo criterio que 18_Fecha_De_Pedido_En_Reportes_Test.
    use DatabaseTransactions;

    public $user_id = 500;

    /** Sucursal `Principal` del user 500 en el fixture. */
    public $address_principal_id = 1;

    /** Segunda sucursal: `sales.address_id` no tiene FK, asi que alcanza con un id que no sea el 1. */
    public $address_secundaria_id = 2;

    /** `sales.employee_id` tampoco tiene FK: un id fijo alcanza para la solapa de empleado. */
    public $empleado_id = 777001;

    /** Cliente `Cliente Cuenta Corriente` del user 500 en el fixture (`sales.client_id` SI tiene FK). */
    public $client_id = 1;

    /** Metodo de pago `Efectivo` del fixture. */
    public $efectivo_id = 3;

    /** Metodo de pago `Transferencia` del fixture. */
    public $transferencia_id = 4;

    /** Dia fijo y lejano, distinto del que usa 18_Fecha_De_Pedido_En_Reportes_Test. */
    public $dia = '2037-06-15';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::find($this->user_id), 'web');
    }

    /**
     * Sin `per_page` la respuesta es la de siempre: `models` es un array plano (lista) con todas
     * las ventas del dia y no viene ninguna clave `totales`. Si este se pone rojo, se les rompio
     * la pantalla a Deposito, Por entregar y Por estado.
     *
     * @group sales
     * @test
     */
    public function sin_per_page_devuelve_el_listado_entero_como_hoy()
    {
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->crear_venta()->id;
        }

        $response = $this->getJson('api/sale/from-date/ventas/' . $this->dia);
        $response->assertStatus(200);

        $json = $response->json();
        $this->assertArrayNotHasKey('totales', $json,
            'Sin per_page no puede aparecer totales: la respuesta tiene que ser byte a byte la de siempre.');

        $models = $json['models'];
        $this->assertSame(range(0, count($models) - 1), array_keys($models),
            'Sin per_page, models tiene que ser una lista plana, no un paginador con data/current_page.');
        $this->assertArrayNotHasKey('data', $models);

        $this->assertEqualsCanonicalizing($ids, array_column($models, 'id'));
    }

    /**
     * 🔴 EL CANDADO. Cinco ventas con EXACTAMENTE el mismo `created_at`, de a dos por pagina: las
     * tres paginas tienen que devolver las cinco, sin repetir ninguna y sin saltear ninguna, y la
     * consulta de cada pagina tiene que llevar el desempate por id.
     *
     * Sin el `orderBy('sales.id', 'DESC')`, `ORDER BY created_at DESC` solo no garantiza el mismo
     * orden entre dos consultas con distinto OFFSET, y una venta puede salir en la pagina 1 y de
     * nuevo en la 2 mientras otra no sale en ninguna.
     *
     * Medido el 14/9/2026 sacando ese orderBy del helper: las aserciones sobre las FILAS quedaron
     * en verde igual —con cinco filas MySQL 8 resuelve el ORDER BY con un "Backward index scan"
     * sobre `sales_user_id_created_at_idx`, cuyo sufijo es la PK, asi que los empates salen en un
     * orden estable— y la que se puso roja fue la del SQL ejecutado. Por eso esta la aserción sobre
     * el query log: el defecto es no determinista (depende del plan que elija el optimizador para
     * cada OFFSET), y un test que solo mira las filas lo deja pasar en verde.
     *
     * @group sales
     * @test
     */
    public function pagina_de_a_dos_devuelve_las_cinco_ventas_sin_repetir_ni_saltear()
    {
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->crear_venta(['created_at' => $this->dia . ' 12:00:00'])->id;
        }

        $primera = $this->getJson('api/sale/from-date/ventas/' . $this->dia . '?per_page=2&page=1');
        $primera->assertStatus(200);
        $primera->assertJsonPath('models.total', 5);
        $primera->assertJsonPath('models.last_page', 3);
        $primera->assertJsonPath('models.per_page', 2);
        $primera->assertJsonPath('models.current_page', 1);
        $primera->assertJsonPath('totales.cantidad', 5);
        $this->assertCount(2, $primera->json('models.data'));

        $vistos = [];
        for ($page = 1; $page <= 3; $page++) {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $response = $this->getJson('api/sale/from-date/ventas/' . $this->dia . '?per_page=2&page=' . $page);
            $response->assertStatus(200);

            $consulta_de_la_pagina = $this->consulta_de_la_pagina(DB::getQueryLog(), 2, ($page - 1) * 2);
            DB::disableQueryLog();

            $this->assertNotNull($consulta_de_la_pagina,
                'Tiene que haber UNA consulta a sales con limit 2 y el offset de la pagina ' . $page . '.');
            $this->assertStringContainsString(
                'order by `sales`.`created_at` desc, `sales`.`id` desc',
                $consulta_de_la_pagina,
                'La consulta de la pagina tiene que desempatar por id: sin eso, ORDER BY created_at DESC solo no fija el orden entre paginas.'
            );

            foreach ($response->json('models.data') as $venta) {
                $this->assertNotContains($venta['id'], $vistos,
                    'La venta ' . $venta['id'] . ' ya habia salido en una pagina anterior: sin desempate por id las paginas se pisan.');
                $vistos[] = $venta['id'];
            }
        }

        $this->assertCount(5, $vistos, 'Las tres paginas tienen que sumar exactamente las cinco ventas.');
        $this->assertEqualsCanonicalizing($ids, $vistos,
            'Entre las tres paginas falta alguna venta: sin desempate por id se saltean filas.');

        /* Con el mismo created_at el orden total es por id descendente, pagina tras pagina. */
        $ordenado = $vistos;
        rsort($ordenado);
        $this->assertSame($ordenado, $vistos,
            'Las filas tienen que salir en orden created_at DESC, id DESC, tambien a traves de las paginas.');
    }

    /**
     * Busca en el query log la consulta que trae las filas de la pagina (la de `sales` con ese
     * limit y ese offset), o null si no esta.
     *
     * @param  array $log     DB::getQueryLog()
     * @param  int   $limit
     * @param  int   $offset
     * @return string|null
     */
    function consulta_de_la_pagina($log, $limit, $offset)
    {
        foreach ($log as $entrada) {
            $sql = $entrada['query'];

            if (strpos($sql, 'from `sales`') === false) {
                continue;
            }

            /* `limit N` solo (offset 0 se omite o sale como `offset 0`) o `limit N offset M`. */
            if (!preg_match('/ limit (\d+)(?: offset (\d+))?$/', $sql, $m)) {
                continue;
            }

            $offset_de_la_consulta = isset($m[2]) ? (int) $m[2] : 0;

            if ((int) $m[1] === $limit && $offset_de_la_consulta === $offset) {
                return $sql;
            }
        }

        return null;
    }

    /**
     * Los totales espejan `Total.vue`: pesos = moneda 1, dolares = moneda 2, cuenta corriente solo
     * con cliente y sin `omitir_en_cuenta_corriente`; una venta con `moneda_id` NULL no suma en
     * ninguno (igual que hoy en el navegador).
     *
     * @group sales
     * @test
     */
    public function los_totales_por_moneda_salen_de_las_columnas_persistidas()
    {
        /* Pesos: con cliente en cuenta corriente, sin cliente, y con cliente pero omitida. */
        $this->crear_venta(['moneda_id' => 1, 'total' => 100, 'total_cost' => 60, 'ganancia' => 40, 'client_id' => $this->client_id, 'omitir_en_cuenta_corriente' => 0]);
        $this->crear_venta(['moneda_id' => 1, 'total' => 50,  'total_cost' => 30, 'ganancia' => 20, 'client_id' => null]);
        $this->crear_venta(['moneda_id' => 1, 'total' => 30,  'total_cost' => 10, 'ganancia' => 20, 'client_id' => $this->client_id, 'omitir_en_cuenta_corriente' => 1]);

        /* Dolares: una en cuenta corriente y una sin cliente. */
        $this->crear_venta(['moneda_id' => 2, 'total' => 10, 'total_cost' => 6, 'ganancia' => 4, 'client_id' => $this->client_id, 'omitir_en_cuenta_corriente' => 0]);
        $this->crear_venta(['moneda_id' => 2, 'total' => 5,  'total_cost' => 2, 'ganancia' => 3, 'client_id' => null]);

        /* Sin moneda: cuenta, pero no suma en ningun chip. */
        $this->crear_venta(['moneda_id' => null, 'total' => 1000, 'total_cost' => 900, 'ganancia' => 100, 'client_id' => $this->client_id, 'omitir_en_cuenta_corriente' => 0]);

        $response = $this->getJson('api/sale/from-date/ventas/' . $this->dia . '?per_page=25');
        $response->assertStatus(200);

        $totales = $response->json('totales');

        $this->assertSame(6, $totales['cantidad']);

        $this->assertEquals(180, $totales['pesos']['total']);
        $this->assertEquals(100, $totales['pesos']['costos']);
        $this->assertEquals(80,  $totales['pesos']['ganancia']);
        $this->assertEquals(100, $totales['pesos']['cuenta_corriente'],
            'A cuenta corriente va solo la venta con cliente y sin omitir_en_cuenta_corriente.');

        $this->assertEquals(15, $totales['dolares']['total']);
        $this->assertEquals(8,  $totales['dolares']['costos']);
        $this->assertEquals(7,  $totales['dolares']['ganancia']);
        $this->assertEquals(10, $totales['dolares']['cuenta_corriente']);

        $this->assertNull($totales['metodo_de_pago'], 'Sin metodo de pago elegido, metodo_de_pago es null.');
    }

    /**
     * Sin ventas en el dia, los totales vuelven en cero (no null) y el paginador vacio.
     *
     * El tipo se mira sobre el helper y no sobre el JSON: `json_encode` escribe `0.0` como `0`
     * (sin JSON_PRESERVE_ZERO_FRACTION no hay forma de distinguirlos), y a la SPA le llega un
     * `Number` igual. Lo que importa es que no llegue `null`, que en el navegador suma como NaN.
     *
     * @group sales
     * @test
     */
    public function sin_ventas_los_totales_vuelven_en_cero()
    {
        $response = $this->getJson('api/sale/from-date/ventas/2037-06-16?per_page=25');
        $response->assertStatus(200);

        $response->assertJsonPath('models.total', 0);
        $response->assertJsonPath('totales.cantidad', 0);
        $this->assertSame([], $response->json('models.data'));
        $this->assertNotNull($response->json('totales.pesos.total'));
        $this->assertEquals(0, $response->json('totales.pesos.total'));
        $this->assertNotNull($response->json('totales.dolares.cuenta_corriente'));
        $this->assertEquals(0, $response->json('totales.dolares.cuenta_corriente'));
        $this->assertSame([], $response->json('totales.por_sucursal'));
        $this->assertSame([], $response->json('totales.por_empleado'));
        $this->assertSame(0, $response->json('totales.sin_empleado'));

        /* En PHP los SUM vuelven como float (NULL de SQL casteado a 0.0), nunca como null ni string. */
        $totales = ListadoVentasHelper::totales(
            Sale::where('user_id', $this->user_id)->enRangoDeFechas('2037-06-16', null, $this->user_id),
            new Request()
        );
        $this->assertSame(0, $totales['cantidad']);
        foreach (['pesos', 'dolares'] as $moneda) {
            foreach (['total', 'costos', 'ganancia', 'cuenta_corriente'] as $clave) {
                $this->assertIsFloat($totales[$moneda][$clave], $moneda . '.' . $clave . ' tiene que ser float.');
                $this->assertSame(0.0, $totales[$moneda][$clave]);
            }
        }
    }

    /**
     * Solapa de sucursal: `address_id` deja solo las ventas de esa sucursal, en filas y en cantidad.
     *
     * @group sales
     * @test
     */
    public function la_solapa_de_sucursal_filtra_filas_y_cantidad()
    {
        $principal_1 = $this->crear_venta(['address_id' => $this->address_principal_id])->id;
        $principal_2 = $this->crear_venta(['address_id' => $this->address_principal_id])->id;
        $secundaria  = $this->crear_venta(['address_id' => $this->address_secundaria_id])->id;

        $response = $this->paginado(['address_id' => $this->address_principal_id]);
        $this->assertEqualsCanonicalizing([$principal_1, $principal_2], $this->ids($response));
        $response->assertJsonPath('totales.cantidad', 2);

        $response = $this->paginado(['address_id' => $this->address_secundaria_id]);
        $this->assertSame([$secundaria], $this->ids($response));
        $response->assertJsonPath('totales.cantidad', 1);
    }

    /**
     * Solapa de empleado: `employee_id` deja las del empleado; `only_owner=1` deja las sin empleado
     * (NULL o 0), que es el caso "dueño" del front.
     *
     * @group sales
     * @test
     */
    public function la_solapa_de_empleado_y_el_dueno_filtran_filas_y_cantidad()
    {
        $del_empleado = $this->crear_venta(['employee_id' => $this->empleado_id])->id;
        $sin_empleado = $this->crear_venta(['employee_id' => null])->id;
        $con_cero     = $this->crear_venta(['employee_id' => 0])->id;

        $response = $this->paginado(['employee_id' => $this->empleado_id]);
        $this->assertSame([$del_empleado], $this->ids($response));
        $response->assertJsonPath('totales.cantidad', 1);

        $response = $this->paginado(['only_owner' => 1]);
        $this->assertEqualsCanonicalizing([$sin_empleado, $con_cero], $this->ids($response));
        $response->assertJsonPath('totales.cantidad', 2);
    }

    /**
     * Show option con / sin factura, decidida por la existencia de un AfipTicket de la venta.
     *
     * @group sales
     * @test
     */
    public function la_show_option_de_factura_filtra_filas_y_cantidad()
    {
        $con_factura = $this->crear_venta()->id;
        $sin_factura = $this->crear_venta()->id;

        AfipTicket::create([
            'sale_id'     => $con_factura,
            'cbte_numero' => '1',
            'cbte_letra'  => 'B',
            'resultado'   => 'A',
        ]);

        $response = $this->paginado(['afip_ticket_show_option' => 'solo-con-factura']);
        $this->assertSame([$con_factura], $this->ids($response));
        $response->assertJsonPath('totales.cantidad', 1);

        $response = $this->paginado(['afip_ticket_show_option' => 'solo-sin-factura']);
        $this->assertSame([$sin_factura], $this->ids($response));
        $response->assertJsonPath('totales.cantidad', 1);

        $response = $this->paginado(['afip_ticket_show_option' => 'con-y-sin-factura']);
        $response->assertJsonPath('totales.cantidad', 2);
    }

    /**
     * Show option cobradas / sin cobrar, espejo de `venta_cobrada` del front: cobrada si no tiene
     * cliente, si esta omitida de cuenta corriente, o si su cuenta corriente esta `pagado`.
     *
     * @group sales
     * @test
     */
    public function la_show_option_de_cobradas_filtra_filas_y_cantidad()
    {
        $sin_cliente = $this->crear_venta(['client_id' => null])->id;
        $pagada      = $this->crear_venta(['client_id' => $this->client_id, 'omitir_en_cuenta_corriente' => 0])->id;
        $sin_pagar   = $this->crear_venta(['client_id' => $this->client_id, 'omitir_en_cuenta_corriente' => 0])->id;
        $omitida     = $this->crear_venta(['client_id' => $this->client_id, 'omitir_en_cuenta_corriente' => 1])->id;

        $this->crear_cuenta_corriente($pagada, 'pagado');
        $this->crear_cuenta_corriente($sin_pagar, 'sin_pagar');

        $response = $this->paginado(['ventas_cobradas_show_option' => 'solo-cobradas']);
        $this->assertEqualsCanonicalizing([$sin_cliente, $pagada, $omitida], $this->ids($response));
        $response->assertJsonPath('totales.cantidad', 3);

        $response = $this->paginado(['ventas_cobradas_show_option' => 'solo-sin-cobrar']);
        $this->assertSame([$sin_pagar], $this->ids($response));
        $response->assertJsonPath('totales.cantidad', 1);

        $response = $this->paginado(['ventas_cobradas_show_option' => 'cobradas-y-no-cobradas']);
        $response->assertJsonPath('totales.cantidad', 4);
    }

    /**
     * Show option metodo de pago: deja las ventas pagadas (al menos en parte) con ese metodo, y
     * `totales.metodo_de_pago.total` suma solo lo pagado CON ESE METODO, no el total de la venta.
     *
     * @group sales
     * @test
     */
    public function la_show_option_de_metodo_de_pago_filtra_y_suma_lo_pagado_con_ese_metodo()
    {
        $mixta          = $this->crear_venta(['total' => 100])->id;
        $transferencia  = $this->crear_venta(['total' => 50])->id;
        $otra_efectivo  = $this->crear_venta(['total' => 20])->id;

        $this->pagar_con($mixta, $this->efectivo_id, 70);
        $this->pagar_con($mixta, $this->transferencia_id, 30);
        $this->pagar_con($transferencia, $this->transferencia_id, 50);
        $this->pagar_con($otra_efectivo, $this->efectivo_id, 20);

        $response = $this->paginado(['payment_method_show_option' => $this->efectivo_id]);
        $this->assertEqualsCanonicalizing([$mixta, $otra_efectivo], $this->ids($response));
        $response->assertJsonPath('totales.cantidad', 2);
        $response->assertJsonPath('totales.metodo_de_pago.id', $this->efectivo_id);
        $this->assertEquals(90, $response->json('totales.metodo_de_pago.total'),
            'El sub-total del metodo suma los pivots de ese metodo (70 + 20), no los totales de las ventas (100 + 20).');

        $response = $this->paginado(['payment_method_show_option' => $this->transferencia_id]);
        $this->assertEqualsCanonicalizing([$mixta, $transferencia], $this->ids($response));
        $this->assertEquals(80, $response->json('totales.metodo_de_pago.total'));

        $response = $this->paginado(['payment_method_show_option' => 'todos']);
        $response->assertJsonPath('totales.cantidad', 3);
        $this->assertNull($response->json('totales.metodo_de_pago'));
    }

    /**
     * Consolidadas: la contenedora de facturacion no aparece por defecto y si con
     * `mostrar_consolidadas=1`; en ese caso pasa la solapa de sucursal aunque no tenga `address_id`
     * y pasa el filtro de metodo de pago aunque no tenga pagos (espejo de
     * `_mostrarContenedorConSucursal` y del filtro de metodo de pago del mixin).
     *
     * @group sales
     * @test
     */
    public function las_consolidadas_solo_aparecen_si_se_piden_y_pasan_las_solapas_sin_sucursal()
    {
        $real = $this->crear_venta(['address_id' => $this->address_principal_id])->id;
        $consolidada = $this->crear_venta([
            'is_consolidacion_facturacion' => 1,
            'address_id'                   => null,
            'employee_id'                  => null,
        ])->id;

        $this->pagar_con($real, $this->efectivo_id, 100);

        $response = $this->paginado([]);
        $this->assertSame([$real], $this->ids($response));
        $response->assertJsonPath('totales.cantidad', 1);

        $response = $this->paginado(['mostrar_consolidadas' => 1]);
        $this->assertEqualsCanonicalizing([$real, $consolidada], $this->ids($response));
        $response->assertJsonPath('totales.cantidad', 2);

        $response = $this->paginado(['mostrar_consolidadas' => 1, 'address_id' => $this->address_principal_id]);
        $this->assertEqualsCanonicalizing([$real, $consolidada], $this->ids($response),
            'Con ver consolidadas, la contenedora sin address_id pasa la solapa de sucursal igual.');

        $response = $this->paginado(['mostrar_consolidadas' => 1, 'only_owner' => 1]);
        $this->assertEqualsCanonicalizing([$real, $consolidada], $this->ids($response));

        $response = $this->paginado(['mostrar_consolidadas' => 1, 'payment_method_show_option' => $this->efectivo_id]);
        $this->assertEqualsCanonicalizing([$real, $consolidada], $this->ids($response),
            'Con ver consolidadas, la contenedora pasa el filtro de metodo de pago aunque no tenga pagos.');
        $this->assertEquals(100, $response->json('totales.metodo_de_pago.total'));

        $response = $this->paginado(['mostrar_consolidadas' => 0, 'address_id' => $this->address_principal_id]);
        $this->assertSame([$real], $this->ids($response));
    }

    /**
     * Las ventas en revision (`to_check` / `checked`) no aparecen ni cuentan, espejo de
     * `pasaFilaVenta` del front.
     *
     * @group sales
     * @test
     */
    public function las_ventas_en_revision_no_aparecen_ni_cuentan()
    {
        $normal = $this->crear_venta()->id;
        $this->crear_venta(['to_check' => 1]);
        $this->crear_venta(['checked' => 1]);

        $response = $this->paginado([]);
        $this->assertSame([$normal], $this->ids($response));
        $response->assertJsonPath('totales.cantidad', 1);
        $response->assertJsonPath('totales.sin_empleado', 1);
    }

    /**
     * Los "(N)" de las solapas se calculan sobre la BASE del dia, sin las show options ni la solapa
     * activa: elegir "solo con factura" cambia las filas y la cantidad pero NO los contadores. Y
     * vuelven como listas, no como objetos indexados por id.
     *
     * @group sales
     * @test
     */
    public function los_contadores_de_solapa_no_cambian_con_las_show_options()
    {
        $con_factura = $this->crear_venta(['address_id' => $this->address_principal_id, 'employee_id' => $this->empleado_id])->id;
        $this->crear_venta(['address_id' => $this->address_principal_id]);
        $this->crear_venta(['address_id' => $this->address_principal_id]);
        $this->crear_venta(['address_id' => $this->address_secundaria_id]);
        $this->crear_venta(['address_id' => $this->address_secundaria_id, 'employee_id' => 0]);
        $this->crear_venta(['address_id' => null]);

        AfipTicket::create(['sale_id' => $con_factura, 'cbte_numero' => '2', 'cbte_letra' => 'B', 'resultado' => 'A']);

        $esperado_sucursal = [
            ['address_id' => $this->address_principal_id, 'cantidad' => 3],
            ['address_id' => $this->address_secundaria_id, 'cantidad' => 2],
        ];
        $esperado_empleado = [
            ['employee_id' => $this->empleado_id, 'cantidad' => 1],
        ];

        $sin_filtro = $this->paginado([]);
        $sin_filtro->assertJsonPath('totales.cantidad', 6);
        $this->assertEqualsCanonicalizing($esperado_sucursal, $sin_filtro->json('totales.por_sucursal'));
        $this->assertSame($esperado_empleado, $sin_filtro->json('totales.por_empleado'));
        $sin_filtro->assertJsonPath('totales.sin_empleado', 5);

        $filtrado = $this->paginado([
            'afip_ticket_show_option' => 'solo-con-factura',
            'address_id'              => $this->address_secundaria_id,
        ]);
        $filtrado->assertJsonPath('totales.cantidad', 0);
        $this->assertEqualsCanonicalizing($esperado_sucursal, $filtrado->json('totales.por_sucursal'),
            'Los (N) de las solapas se calculan sobre la base: no pueden cambiar con la show option ni con la solapa activa.');
        $this->assertSame($esperado_empleado, $filtrado->json('totales.por_empleado'));
        $filtrado->assertJsonPath('totales.sin_empleado', 5);

        /* Listas (claves 0..n), no objetos indexados por id. */
        $por_sucursal = $filtrado->json('totales.por_sucursal');
        $this->assertSame(range(0, count($por_sucursal) - 1), array_keys($por_sucursal));
    }

    /**
     * `per_page` fuera de rango: <1 cae al default de 25 y >200 se acota al techo de 200.
     *
     * @group sales
     * @test
     */
    public function per_page_fuera_de_rango_cae_al_default_o_al_techo()
    {
        for ($i = 0; $i < 27; $i++) {
            $this->crear_venta();
        }

        $response = $this->getJson('api/sale/from-date/ventas/' . $this->dia . '?per_page=0');
        $response->assertStatus(200);
        $response->assertJsonPath('models.per_page', 25);
        $response->assertJsonPath('models.total', 27);
        $response->assertJsonPath('models.last_page', 2);
        $this->assertCount(25, $response->json('models.data'));

        $response = $this->getJson('api/sale/from-date/ventas/' . $this->dia . '?per_page=999');
        $response->assertStatus(200);
        $response->assertJsonPath('models.per_page', 200);
        $this->assertCount(27, $response->json('models.data'));

        $response = $this->getJson('api/sale/from-date/ventas/' . $this->dia . '?per_page=no-es-un-numero');
        $response->assertStatus(200);
        $response->assertJsonPath('models.per_page', 25);
    }

    /**
     * La pagina lleva el mismo `withAll()` que el listado sin paginar: la SPA lee relaciones
     * (articulos, medios de pago, tickets) de cada fila y no puede quedarse sin ellas.
     *
     * @group sales
     * @test
     */
    public function las_filas_de_la_pagina_traen_las_relaciones_del_listado()
    {
        $id = $this->crear_venta()->id;
        $this->pagar_con($id, $this->efectivo_id, 100);

        $response = $this->paginado([]);
        $venta = $response->json('models.data.0');

        $this->assertSame($id, $venta['id']);
        $this->assertArrayHasKey('articles', $venta);
        $this->assertArrayHasKey('afip_tickets', $venta);
        $this->assertArrayHasKey('current_acount_payment_methods', $venta);
        $this->assertCount(1, $venta['current_acount_payment_methods']);
        $this->assertArrayHasKey('sale_modifications_count', $venta);
    }

    /**
     * Venta minima, terminada, del dia fijo del test. `$extra` pisa lo que haga falta.
     *
     * @param  array $extra
     * @return Sale
     */
    function crear_venta($extra = [])
    {
        return Sale::create(array_merge([
            'user_id'                      => $this->user_id,
            'address_id'                   => $this->address_principal_id,
            'moneda_id'                    => 1,
            'total'                        => 100,
            'sub_total'                    => 100,
            'total_cost'                   => 60,
            'ganancia'                     => 40,
            'terminada'                    => 1,
            'confirmed'                    => 1,
            'omitir_en_cuenta_corriente'   => 1,
            'is_consolidacion_facturacion' => 0,
            'created_at'                   => $this->dia . ' 12:00:00',
            'terminada_at'                 => $this->dia . ' 12:00:00',
        ], $extra));
    }

    /**
     * Fila minima de cuenta corriente para la venta, con el estado pedido.
     *
     * @param  int    $sale_id
     * @param  string $status  `pagado` / `sin_pagar` / ...
     * @return CurrentAcount
     */
    function crear_cuenta_corriente($sale_id, $status)
    {
        return CurrentAcount::create([
            'user_id'   => $this->user_id,
            'client_id' => $this->client_id,
            'sale_id'   => $sale_id,
            'status'    => $status,
            'debe'      => 100,
            'saldo'     => $status === 'pagado' ? 0 : 100,
        ]);
    }

    /**
     * Pivot de pago de la venta con un metodo, tal cual la deja el modulo de vender.
     *
     * @param  int   $sale_id
     * @param  int   $metodo_id
     * @param  float $amount
     * @return void
     */
    function pagar_con($sale_id, $metodo_id, $amount)
    {
        DB::table('current_acount_payment_method_sale')->insert([
            'sale_id'                          => $sale_id,
            'current_acount_payment_method_id' => $metodo_id,
            'amount'                           => $amount,
            'created_at'                       => now(),
            'updated_at'                       => now(),
        ]);
    }

    /**
     * GET paginado del dia fijo con los filtros dados en la query string.
     *
     * @param  array $filtros
     * @return \Illuminate\Testing\TestResponse
     */
    function paginado($filtros)
    {
        $query = array_merge(['per_page' => 25, 'page' => 1], $filtros);

        $response = $this->getJson('api/sale/from-date/ventas/' . $this->dia . '?' . http_build_query($query));
        $response->assertStatus(200);

        return $response;
    }

    /**
     * Ids de las filas de la pagina.
     *
     * @param  \Illuminate\Testing\TestResponse $response
     * @return array
     */
    function ids($response)
    {
        return array_column($response->json('models.data'), 'id');
    }
}
