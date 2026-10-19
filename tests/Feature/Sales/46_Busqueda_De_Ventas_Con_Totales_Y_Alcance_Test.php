<?php

namespace Tests\Feature\Sales;

use App\Models\PermissionEmpresa;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión alcance-de-ventas-caminos-faltantes (16/10/2026): la BÚSQUEDA de la pantalla de Ventas
 * (buscador general `POST global-search/sale` y filtros de columna `POST search/sale/null/1`) ya no
 * confía en la SPA: con la marca `ventas_pantalla` en el cuerpo, el servidor acota lo que el empleado
 * puede ver y calcula el Total del mismo conjunto.
 *
 * Lo que se protege acá, en orden de importancia:
 *
 *   1. Con la marca, un empleado acotado ("solo sus ventas" / "solo su sucursal") recibe filas,
 *      `models.total` y `totales` de su alcance, y NO lo amplía pidiendo la solapa de otro, la del
 *      dueño o ninguna.
 *   2. `totales` es de TODAS las páginas del resultado, no de la que se ve.
 *   3. Texto + filtro de columna + solapa + show options acotan juntos filas y totales.
 *   4. Dueño y administrador no se acotan.
 *   5. SIN la marca la respuesta es la de siempre (sin `totales`, sin acotar): es la ruta que comparten
 *      Depósito, Por entregar y Por estado. Y con la marca pero OTRO modelo, tampoco cambia nada.
 *   6. Contenedoras consolidadas fuera del acotado; ventas en revisión fuera para todos.
 *   7. Los contadores "(N)" de las solapas salen acotados.
 *   8. `search/sale/null/1` trae `totales` al lado del paginador.
 *   9. Con `ordenar_de` activo no revienta (ONLY_FULL_GROUP_BY).
 *
 * Todas las ventas del test llevan "T46" en las observaciones y cada pedido busca ese texto: la
 * búsqueda no tiene día, y la base del slot tiene ventas del dueño de otros tests.
 *
 * @group sales
 */
class Busqueda_De_Ventas_Con_Totales_Y_Alcance_Test extends EmpresaTestCase
{
    /** Día fijo y lejano, que ningún otro test usa. */
    public $dia = '2037-09-14';

    /** Texto que llevan las observaciones de TODAS las ventas del test: aísla la búsqueda del resto de la base. */
    const TEXTO = 'T46';

    /** Sucursal "Principal" del fixture (id 1, del usuario 500): la única que `alcance_permitido()` reconoce como existente. */
    public $sucursal_1 = 1;
    public $sucursal_2 = 2;

    /** @var \App\Models\User */
    protected $owner;

    /** @var \App\Models\User */
    protected $empleado_a;

    /** @var \App\Models\User */
    protected $empleado_b;

    /** @var array Ventas del test, por nombre. */
    protected $ventas = [];

    /**
     * Seis ventas del dueño, en dos sucursales y de tres vendedores (dueño sin empleado, A y B):
     *
     *   dueño_s1 100 · dueño_s2 200 · A_s1 300 · A_s2 400 · B_s1 500 · B_s2 600
     *
     * Total 2100 · A 700 · B 1100 · solo dueño 300 · sucursal 1 = 900 · sucursal 2 = 1200.
     * Las de sucursal 1 dicen "alfa" y las de la 2 dicen "beta".
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->empleado_a = $this->crear_empleado([], $this->sucursal_1);
        $this->empleado_b = $this->crear_empleado([], $this->sucursal_2);

        $this->ventas['dueno_s1'] = $this->crear_venta(100, null, $this->sucursal_1);
        $this->ventas['dueno_s2'] = $this->crear_venta(200, null, $this->sucursal_2);
        $this->ventas['a_s1'] = $this->crear_venta(300, $this->empleado_a->id, $this->sucursal_1);
        $this->ventas['a_s2'] = $this->crear_venta(400, $this->empleado_a->id, $this->sucursal_2);
        $this->ventas['b_s1'] = $this->crear_venta(500, $this->empleado_b->id, $this->sucursal_1);
        $this->ventas['b_s2'] = $this->crear_venta(600, $this->empleado_b->id, $this->sucursal_2);
    }

    // ---------------------------------------------------------------------------------------
    // 1. Un empleado acotado ve solo su alcance, y no lo amplía con el pedido
    // ---------------------------------------------------------------------------------------

    /**
     * "Solo sus propias ventas": con la marca recibe solo las suyas -filas, `models.total` y `totales`-
     * pida la solapa que pida (la de otro empleado, la del dueño, la suya o ninguna).
     *
     * @test
     */
    public function con_la_marca_el_empleado_con_solo_las_suyas_recibe_solo_las_suyas_y_no_puede_ampliarlo()
    {
        $this->actuar_como($this->crear_empleado(['sale.index.employees.only_your']));
        $yo = Auth::user();
        $mia = $this->crear_venta(70, $yo->id, $this->sucursal_1);

        foreach ([[], ['employee_id' => $this->empleado_b->id], ['only_owner' => 1], ['employee_id' => $yo->id]] as $solapa) {
            $r = $this->buscar_global($solapa);

            $this->assertSame([$mia->id], $r['ids'], 'Pedido: '.json_encode($solapa));
            $this->assertSame(1, $r['total'], 'models.total. Pedido: '.json_encode($solapa));
            $this->assertSame(1, $r['totales']['cantidad'], 'Pedido: '.json_encode($solapa));
            $this->assertEquals(70, $r['totales']['pesos']['total'], 'Pedido: '.json_encode($solapa));
        }
    }

    /**
     * "Solo las de su sucursal": con la marca recibe solo esa sucursal, pida la que pida.
     *
     * @test
     */
    public function con_la_marca_el_empleado_con_solo_su_sucursal_recibe_solo_esa_y_no_puede_ampliarlo()
    {
        $this->actuar_como($this->crear_empleado(['sale.index.addresses.only_your'], $this->sucursal_1));

        foreach ([[], ['address_id' => $this->sucursal_2]] as $solapa) {
            $r = $this->buscar_global($solapa);

            $this->assertEqualsCanonicalizing($this->ids(['dueno_s1', 'a_s1', 'b_s1']), $r['ids'], 'Pedido: '.json_encode($solapa));
            $this->assertSame(3, $r['total'], 'models.total. Pedido: '.json_encode($solapa));
            $this->assertSame(3, $r['totales']['cantidad'], 'Pedido: '.json_encode($solapa));
            $this->assertEquals(900, $r['totales']['pesos']['total'], 'Pedido: '.json_encode($solapa));
        }
    }

    /**
     * Los dos ejes a la vez acotan juntos: solo sus ventas Y solo en su sucursal.
     *
     * @test
     */
    public function con_la_marca_los_dos_ejes_acotan_juntos()
    {
        $this->actuar_como($this->crear_empleado(
            ['sale.index.employees.only_your', 'sale.index.addresses.only_your'],
            $this->sucursal_1
        ));
        $yo = Auth::user();
        $mia_s1 = $this->crear_venta(30, $yo->id, $this->sucursal_1);
        $this->crear_venta(40, $yo->id, $this->sucursal_2);
        $this->crear_venta(50, $this->empleado_a->id, $this->sucursal_1);

        $r = $this->buscar_global([]);

        $this->assertSame([$mia_s1->id], $r['ids']);
        $this->assertSame(1, $r['totales']['cantidad']);
        $this->assertEquals(30, $r['totales']['pesos']['total']);
    }

    // ---------------------------------------------------------------------------------------
    // 2. `totales` es de todas las páginas
    // ---------------------------------------------------------------------------------------

    /**
     * Más ventas que `per_page`: cada página trae su porción de filas, pero `totales.cantidad` y
     * `totales.pesos.total` son los de TODO el resultado, y la unión de las páginas es el conjunto
     * acotado. También los dólares, que no se mezclan con los pesos.
     *
     * @test
     */
    public function totales_es_de_todas_las_paginas_del_resultado_acotado()
    {
        $this->actuar_como($this->crear_empleado(['sale.index.employees.only_your']));
        $yo = Auth::user();
        $esperadas = [];
        for ($i = 0; $i < 5; $i++) {
            $esperadas[] = $this->crear_venta(10 * ($i + 1), $yo->id, $this->sucursal_1)->id;
        }
        $esperadas[] = $this->crear_venta(40, $yo->id, $this->sucursal_1, ['moneda_id' => 2])->id;
        $this->crear_venta(99, $this->empleado_a->id, $this->sucursal_1, ['moneda_id' => 2]);

        $vistas = [];
        foreach ([1, 2, 3, 4] as $pagina) {
            $r = $this->buscar_global([], ['per_page' => 2], $pagina);

            $vistas = array_merge($vistas, $r['ids']);

            $this->assertSame(6, $r['total'], 'models.total, página '.$pagina);
            $this->assertSame(6, $r['totales']['cantidad'], 'Página '.$pagina);
            $this->assertEquals(150, $r['totales']['pesos']['total'], 'Página '.$pagina);
            $this->assertEquals(40, $r['totales']['dolares']['total'], 'Los dólares de otro empleado no cuentan. Página '.$pagina);
        }

        $this->assertEqualsCanonicalizing($esperadas, $vistas);
        $this->assertCount(6, array_unique($vistas), 'Una fila no puede repetirse entre páginas.');
    }

    // ---------------------------------------------------------------------------------------
    // 3. Texto + columna + solapa + show options acotan juntos
    // ---------------------------------------------------------------------------------------

    /**
     * El dueño (que elige libremente la solapa) combina texto ("alfa": las de la sucursal 1), filtro
     * de columna (total mayor a 150), solapa (empleado A) y show options: en cada paso las filas,
     * `models.total` y `totales` son el MISMO conjunto.
     *
     * @test
     */
    public function texto_columna_solapa_y_show_options_acotan_juntos_filas_y_totales()
    {
        $this->actuar_como($this->owner);

        // Texto: "T46 alfa" -> dueño_s1 100, A_s1 300, B_s1 500.
        $r = $this->buscar_global([], ['query_value' => self::TEXTO.' alfa']);
        $this->assertEqualsCanonicalizing($this->ids(['dueno_s1', 'a_s1', 'b_s1']), $r['ids']);
        $this->assertEquals(900, $r['totales']['pesos']['total']);

        // + columna: total mayor a 150 -> A_s1 300, B_s1 500.
        $columna = ['filters' => [['key' => 'total', 'type' => 'number', 'mayor_que' => 150]]];
        $r = $this->buscar_global([], array_merge(['query_value' => self::TEXTO.' alfa'], $columna));
        $this->assertEqualsCanonicalizing($this->ids(['a_s1', 'b_s1']), $r['ids']);
        $this->assertSame(2, $r['total']);
        $this->assertEquals(800, $r['totales']['pesos']['total']);

        // + solapa: empleado A -> A_s1.
        $r = $this->buscar_global(['employee_id' => $this->empleado_a->id], array_merge(['query_value' => self::TEXTO.' alfa'], $columna));
        $this->assertSame($this->ids(['a_s1']), $r['ids']);
        $this->assertSame(1, $r['total']);
        $this->assertSame(1, $r['totales']['cantidad']);
        $this->assertEquals(300, $r['totales']['pesos']['total']);

        // + show option "solo sin factura": ninguna tiene comprobante, no cambia.
        $r = $this->buscar_global(
            ['employee_id' => $this->empleado_a->id, 'afip_ticket_show_option' => 'solo-sin-factura'],
            array_merge(['query_value' => self::TEXTO.' alfa'], $columna)
        );
        $this->assertSame($this->ids(['a_s1']), $r['ids']);
        $this->assertEquals(300, $r['totales']['pesos']['total']);

        // ... y "solo con factura": ninguna -> todo en cero, filas incluidas.
        $r = $this->buscar_global(
            ['employee_id' => $this->empleado_a->id, 'afip_ticket_show_option' => 'solo-con-factura'],
            array_merge(['query_value' => self::TEXTO.' alfa'], $columna)
        );
        $this->assertSame([], $r['ids']);
        $this->assertSame(0, $r['total']);
        $this->assertSame(0, $r['totales']['cantidad']);
        $this->assertEquals(0, $r['totales']['pesos']['total']);
    }

    /**
     * Las show options de cobranza llegan al conjunto: ninguna de las ventas del test tiene cliente,
     * así que "solo sin cobrar" las deja a todas afuera y "solo cobradas" las deja a todas adentro.
     *
     * @test
     */
    public function las_show_options_de_cobranza_llegan_al_conjunto_y_a_los_totales()
    {
        $this->actuar_como($this->owner);

        $cobradas = $this->buscar_global(['ventas_cobradas_show_option' => 'solo-cobradas']);
        $this->assertSame(6, $cobradas['total']);
        $this->assertEquals(2100, $cobradas['totales']['pesos']['total']);

        $sin_cobrar = $this->buscar_global(['ventas_cobradas_show_option' => 'solo-sin-cobrar']);
        $this->assertSame([], $sin_cobrar['ids']);
        $this->assertSame(0, $sin_cobrar['total']);
        $this->assertSame(0, $sin_cobrar['totales']['cantidad']);
    }

    /**
     * Con empleado acotado, el texto y la solapa de otro no lo sacan de su alcance: "alfa" + solapa de B
     * le deja SU venta de la sucursal 1 y nada de B.
     *
     * @test
     */
    public function texto_y_solapa_ajena_no_sacan_al_empleado_acotado_de_su_alcance()
    {
        $this->actuar_como($this->empleado_acotado_a_sus_ventas($this->empleado_a));

        $r = $this->buscar_global(['employee_id' => $this->empleado_b->id], ['query_value' => self::TEXTO.' alfa']);

        $this->assertSame($this->ids(['a_s1']), $r['ids']);
        $this->assertEquals(300, $r['totales']['pesos']['total']);
    }

    // ---------------------------------------------------------------------------------------
    // 4. Dueño y administrador no se acotan
    // ---------------------------------------------------------------------------------------

    /**
     * El dueño ve todo y sus solapas siguen funcionando; aunque tenga "solo las mías" tildado.
     *
     * @test
     */
    public function el_dueno_no_se_acota_ni_con_solo_las_mias_tildado()
    {
        $this->dar_permisos($this->owner, ['sale.index.employees.only_your', 'sale.index.addresses.only_your']);
        $this->actuar_como($this->owner->fresh());

        $todo = $this->buscar_global([]);
        $this->assertSame(6, $todo['total']);
        $this->assertEquals(2100, $todo['totales']['pesos']['total']);

        $de_a = $this->buscar_global(['employee_id' => $this->empleado_a->id]);
        $this->assertSame(2, $de_a['totales']['cantidad']);
        $this->assertEquals(700, $de_a['totales']['pesos']['total']);

        $de_la_sucursal_2 = $this->buscar_global(['address_id' => $this->sucursal_2]);
        $this->assertEquals(1200, $de_la_sucursal_2['totales']['pesos']['total']);

        $solo_dueno = $this->buscar_global(['only_owner' => 1]);
        $this->assertEquals(300, $solo_dueno['totales']['pesos']['total']);
    }

    /**
     * Un empleado administrador (`admin_access`) lo ve todo aunque tenga "solo las mías" tildado.
     *
     * @test
     */
    public function el_administrador_no_se_acota_aunque_tenga_solo_las_mias_tildado()
    {
        $this->actuar_como($this->crear_empleado(
            ['sale.index.employees.only_your', 'sale.index.addresses.only_your'],
            $this->sucursal_1,
            ['admin_access' => 1]
        ));

        $r = $this->buscar_global([]);

        $this->assertSame(6, $r['total']);
        $this->assertEquals(2100, $r['totales']['pesos']['total']);
    }

    /**
     * Un empleado con "todos" (o sin ningún permiso de alcance) se ve todo, y con "todos" elige la
     * solapa de otro.
     *
     * @test
     */
    public function con_todos_o_sin_permisos_de_alcance_ve_todo_y_elige_solapa()
    {
        $this->actuar_como($this->crear_empleado(['sale.index.employees.all', 'sale.index.employees.only_your', 'sale.index.addresses.all']));
        $this->assertEquals(2100, $this->buscar_global([])['totales']['pesos']['total']);
        $this->assertEquals(1100, $this->buscar_global(['employee_id' => $this->empleado_b->id])['totales']['pesos']['total']);

        $this->actuar_como($this->crear_empleado([], $this->sucursal_1));
        $this->assertEquals(2100, $this->buscar_global([])['totales']['pesos']['total']);
    }

    // ---------------------------------------------------------------------------------------
    // 5. Sin la marca / con otro modelo: nada cambia
    // ---------------------------------------------------------------------------------------

    /**
     * Sin `ventas_pantalla` la respuesta no trae `totales` y el empleado acotado recibe lo de siempre:
     * todas las filas (es la ruta que comparten Depósito, Por entregar y Por estado, que no se acotan).
     *
     * @test
     */
    public function sin_la_marca_no_hay_totales_y_el_empleado_acotado_ve_lo_de_siempre()
    {
        $this->actuar_como($this->empleado_acotado_a_sus_ventas($this->empleado_a));

        $r = $this->buscar_global(null);

        $this->assertArrayNotHasKey('totales', $r['json']);
        $this->assertSame(['models', 'matches', 'saldos'], array_keys($r['json']), 'Las claves de la respuesta son las de siempre.');
        $this->assertEqualsCanonicalizing($this->todas_las_ids(), $r['ids']);
        $this->assertSame(6, $r['total']);
    }

    /**
     * `ventas_pantalla` que no es un objeto (null, número, texto) no cuenta como marca: se comporta
     * como sin ella.
     *
     * @test
     */
    public function una_marca_que_no_es_un_objeto_no_es_marca()
    {
        $this->actuar_como($this->empleado_acotado_a_sus_ventas($this->empleado_a));

        foreach ([null, 1, 'si'] as $valor) {
            $response = $this->postJson('api/global-search/sale?page=1', array_merge($this->cuerpo_global(), ['ventas_pantalla' => $valor]));
            $response->assertStatus(200);

            $this->assertArrayNotHasKey('totales', $response->json(), 'ventas_pantalla = '.json_encode($valor));
            $this->assertSame(6, $response->json('models.total'), 'ventas_pantalla = '.json_encode($valor));
        }
    }

    /**
     * Depósito, Por entregar y Por estado no se acotan por sucursal ni por empleado: sin la marca, un
     * empleado "solo sus ventas" encuentra también las de los demás (incluida una sin terminar, que
     * es justo lo que vive en Depósito).
     *
     * @test
     */
    public function deposito_sin_la_marca_no_se_acota()
    {
        $sin_terminar = $this->crear_venta(80, $this->empleado_b->id, $this->sucursal_2, ['terminada' => 0, 'terminada_at' => null]);

        $this->actuar_como($this->empleado_acotado_a_sus_ventas($this->empleado_a));

        $r = $this->buscar_global(null);

        $this->assertContains($sin_terminar->id, $r['ids']);
        $this->assertContains($this->ventas['b_s1']->id, $r['ids']);
        $this->assertContains($this->ventas['dueno_s1']->id, $r['ids']);
        $this->assertSame(7, $r['total']);
    }

    /**
     * La marca en el cuerpo de OTRO modelo no cambia NADA: la misma respuesta, byte a byte, con y sin
     * ella, y sin la clave `totales`. Se prueba con artículos y un empleado acotado.
     *
     * @test
     */
    public function la_marca_en_otro_modelo_no_cambia_la_respuesta_del_buscador_general()
    {
        $this->actuar_como($this->empleado_acotado_a_sus_ventas($this->empleado_a));

        $cuerpo = ['query_value' => '', 'props' => [], 'relation_props' => [], 'extra_filters' => [], 'filters' => [], 'conector' => 'or', 'per_page' => 50];

        $sin = $this->postJson('api/global-search/article?page=1', $cuerpo);
        $sin->assertStatus(200);

        $con = $this->postJson('api/global-search/article?page=1', array_merge($cuerpo, ['ventas_pantalla' => $this->marca([])]));
        $con->assertStatus(200);

        $this->assertGreaterThan(1, $sin->json('models.total'), 'Precondición: el fixture tiene artículos.');
        $this->assertArrayNotHasKey('totales', $con->json());
        $this->assertSame($sin->getContent(), $con->getContent());
    }

    /**
     * Lo mismo para `search/{modelo}/null/1` (el paginador pelado): con la marca y otro modelo, el
     * JSON es idéntico y no trae `totales`.
     *
     * @test
     */
    public function la_marca_en_otro_modelo_no_cambia_la_respuesta_del_paginador_de_search()
    {
        $this->actuar_como($this->empleado_acotado_a_sus_ventas($this->empleado_a));

        $cuerpo = ['filters' => [], 'per_page' => 50];

        $sin = $this->postJson('api/search/article/null/1?page=1', $cuerpo);
        $sin->assertStatus(200);

        $con = $this->postJson('api/search/article/null/1?page=1', array_merge($cuerpo, ['ventas_pantalla' => $this->marca([])]));
        $con->assertStatus(200);

        $this->assertGreaterThan(1, $sin->json('total'), 'Precondición: el fixture tiene artículos.');
        $this->assertArrayNotHasKey('totales', $con->json());
        $this->assertSame($sin->getContent(), $con->getContent());
    }

    // ---------------------------------------------------------------------------------------
    // 6. Consolidadas y ventas en revisión
    // ---------------------------------------------------------------------------------------

    /**
     * Las contenedoras de facturación consolidada (sin sucursal ni empleado, con el total de ventas de
     * cualquiera) no entran al acotado ni con "ver consolidadas"; el dueño solo las ve si las pide.
     *
     * @test
     */
    public function las_contenedoras_consolidadas_no_se_suman_al_empleado_acotado()
    {
        $this->crear_venta(900, null, null, ['is_consolidacion_facturacion' => 1]);

        $this->actuar_como($this->empleado_acotado_a_sus_ventas($this->empleado_a));
        $acotado = $this->buscar_global(['mostrar_consolidadas' => 1]);
        $this->assertSame(2, $acotado['totales']['cantidad']);
        $this->assertEquals(700, $acotado['totales']['pesos']['total']);

        $this->actuar_como($this->crear_empleado(['sale.index.addresses.only_your'], $this->sucursal_1));
        $de_sucursal = $this->buscar_global(['mostrar_consolidadas' => 1]);
        $this->assertSame(3, $de_sucursal['totales']['cantidad']);
        $this->assertEquals(900, $de_sucursal['totales']['pesos']['total']);

        $this->actuar_como($this->owner);
        $this->assertEquals(3000, $this->buscar_global(['mostrar_consolidadas' => 1])['totales']['pesos']['total']);
        $this->assertSame(7, $this->buscar_global(['mostrar_consolidadas' => 1])['total']);
        $this->assertEquals(2100, $this->buscar_global([])['totales']['pesos']['total'], 'Sin "ver consolidadas" la contenedora no entra para nadie.');
    }

    /**
     * Las ventas en revisión (`to_check` / `checked`) las saca la pantalla de Ventas para todos, el
     * dueño incluido; sin la marca (Depósito) se encuentran.
     *
     * @test
     */
    public function las_ventas_en_revision_quedan_fuera_con_la_marca_y_dentro_sin_ella()
    {
        $para_chequear = $this->crear_venta(50, null, $this->sucursal_1, ['to_check' => 1, 'terminada' => 0, 'terminada_at' => null]);
        $chequeada = $this->crear_venta(60, null, $this->sucursal_1, ['checked' => 1]);

        $this->actuar_como($this->owner);

        $con = $this->buscar_global([]);
        $this->assertNotContains($para_chequear->id, $con['ids']);
        $this->assertNotContains($chequeada->id, $con['ids']);
        $this->assertSame(6, $con['total']);
        $this->assertEquals(2100, $con['totales']['pesos']['total']);

        $sin = $this->buscar_global(null);
        $this->assertContains($para_chequear->id, $sin['ids']);
        $this->assertContains($chequeada->id, $sin['ids']);
    }

    // ---------------------------------------------------------------------------------------
    // 7. Contadores por solapa
    // ---------------------------------------------------------------------------------------

    /**
     * Los contadores "(N)" de las solapas salen acotados: un empleado "solo sus ventas" no recibe
     * cuántas ventas tiene el dueño ni su compañero, y uno "solo su sucursal" no recibe las de la otra
     * sucursal. El dueño recibe todos.
     *
     * @test
     */
    public function los_contadores_por_solapa_salen_acotados()
    {
        // Dueño: todos.
        $this->actuar_como($this->owner);
        $dueno = $this->buscar_global([])['totales'];
        $this->assertEqualsCanonicalizing(
            [['address_id' => $this->sucursal_1, 'cantidad' => 3], ['address_id' => $this->sucursal_2, 'cantidad' => 3]],
            $dueno['por_sucursal']
        );
        $this->assertEqualsCanonicalizing(
            [['employee_id' => $this->empleado_a->id, 'cantidad' => 2], ['employee_id' => $this->empleado_b->id, 'cantidad' => 2]],
            $dueno['por_empleado']
        );
        $this->assertSame(2, $dueno['sin_empleado']);

        // "Solo sus ventas": solo lo suyo.
        $this->actuar_como($this->empleado_acotado_a_sus_ventas($this->empleado_a));
        $propio = $this->buscar_global([])['totales'];
        $this->assertEqualsCanonicalizing(
            [['address_id' => $this->sucursal_1, 'cantidad' => 1], ['address_id' => $this->sucursal_2, 'cantidad' => 1]],
            $propio['por_sucursal']
        );
        $this->assertSame([['employee_id' => $this->empleado_a->id, 'cantidad' => 2]], $propio['por_empleado']);
        $this->assertSame(0, $propio['sin_empleado'], 'No recibe cuántas ventas tiene el dueño.');

        // "Solo su sucursal": solo esa sucursal, con todos los vendedores de esa sucursal.
        $this->actuar_como($this->crear_empleado(['sale.index.addresses.only_your'], $this->sucursal_1));
        $sucursal = $this->buscar_global([])['totales'];
        $this->assertSame([['address_id' => $this->sucursal_1, 'cantidad' => 3]], $sucursal['por_sucursal']);
        $this->assertEqualsCanonicalizing(
            [['employee_id' => $this->empleado_a->id, 'cantidad' => 1], ['employee_id' => $this->empleado_b->id, 'cantidad' => 1]],
            $sucursal['por_empleado']
        );
        $this->assertSame(1, $sucursal['sin_empleado']);
    }

    /**
     * Los contadores salen de UNA sola pasada (`GROUP BY address_id, employee_id` plegado en PHP): lo
     * que NULL y 0 significan tiene que seguir igual. Sucursal NULL o 0 no cuenta para ninguna
     * sucursal; empleado NULL o 0 es «sin empleado»; y una misma sucursal / empleado repartida en
     * varias filas del GROUP BY se suma.
     *
     * @test
     */
    public function los_contadores_suman_las_combinaciones_y_tratan_null_y_cero_como_siempre()
    {
        $this->actuar_como($this->owner);

        // Sin sucursal (NULL y 0) y sin empleado (0): no suman a ninguna sucursal; el 0 de empleado es «sin empleado».
        $this->crear_venta(1, null, null);
        $this->crear_venta(1, 0, 0);
        // Mismo empleado A en una tercera combinación de sucursal ya existente.
        $this->crear_venta(1, $this->empleado_a->id, $this->sucursal_1);

        $totales = $this->buscar_global([])['totales'];

        $this->assertSame(9, $totales['cantidad']);
        $this->assertSame(
            [['address_id' => $this->sucursal_1, 'cantidad' => 4], ['address_id' => $this->sucursal_2, 'cantidad' => 3]],
            $totales['por_sucursal'],
            'Sucursal NULL o 0 no cuenta; sucursal 1 = 3 base + 1 de A; ordenadas por id.'
        );
        $this->assertSame(
            [['employee_id' => $this->empleado_a->id, 'cantidad' => 3], ['employee_id' => $this->empleado_b->id, 'cantidad' => 2]],
            $totales['por_empleado']
        );
        $this->assertSame(4, $totales['sin_empleado'], 'Los 2 del dueño de la base + empleado NULL + empleado 0.');
    }

    /**
     * 🔴 La búsqueda NO calcula el IVA de los totales (`total_sin_iva`, `costos_sin_iva`,
     * `ventas_con_iva_sin_medir`): esa consulta agrupa los comprobantes de todo el historial entre la
     * primera y la última fecha del conjunto, y una búsqueda de todas las fechas la pagaría en cada
     * clic. Si alguien la agrega «para que se parezca al listado del día», este test se pone rojo.
     *
     * @test
     */
    public function la_busqueda_no_calcula_el_iva_de_los_totales()
    {
        $this->actuar_como($this->owner);

        $totales = $this->buscar_global([])['totales'];

        $this->assertArrayHasKey('total', $totales['pesos']);
        foreach (['total_sin_iva', 'costos_sin_iva', 'ventas_con_iva_sin_medir'] as $clave) {
            $this->assertArrayNotHasKey($clave, $totales['pesos'], $clave);
        }
    }

    // ---------------------------------------------------------------------------------------
    // 8. search/sale/null/1 (el paginador pelado)
    // ---------------------------------------------------------------------------------------

    /**
     * Con la marca, `search/sale/null/1` devuelve el MISMO JSON del paginador más `totales` al lado de
     * `data` / `total` / `last_page`; el empleado acotado recibe solo lo suyo, en filas, `total` y
     * `totales`.
     *
     * @test
     */
    public function search_con_la_marca_trae_totales_junto_al_paginador_y_acota()
    {
        $this->actuar_como($this->empleado_acotado_a_sus_ventas($this->empleado_a));

        $r = $this->buscar_filtrado(['employee_id' => $this->empleado_b->id]);

        $this->assertArrayHasKey('data', $r['json']);
        $this->assertArrayHasKey('last_page', $r['json']);
        $this->assertArrayHasKey('totales', $r['json']);
        $this->assertEqualsCanonicalizing($this->ids(['a_s1', 'a_s2']), $r['ids']);
        $this->assertSame(2, $r['json']['total']);
        $this->assertSame(2, $r['json']['totales']['cantidad']);
        $this->assertEquals(700, $r['json']['totales']['pesos']['total']);
    }

    /**
     * `search/sale/null/1` con la marca y el dueño: las claves son las del paginador de siempre más
     * `totales`, y las filas las mismas que sin la marca.
     *
     * @test
     */
    public function search_con_la_marca_solo_suma_la_clave_totales_al_paginador()
    {
        $this->actuar_como($this->owner);

        $sin = $this->buscar_filtrado(null);
        $con = $this->buscar_filtrado([]);

        $claves_con = array_keys($con['json']);
        sort($claves_con);
        $claves_sin = array_keys($sin['json']);
        $claves_sin[] = 'totales';
        sort($claves_sin);

        $this->assertSame($claves_sin, $claves_con);
        $this->assertSame($sin['ids'], $con['ids']);
        $this->assertSame($sin['json']['total'], $con['json']['total']);
        $this->assertSame($sin['json']['last_page'], $con['json']['last_page']);
        $this->assertEquals(2100, $con['json']['totales']['pesos']['total']);
    }

    /**
     * Sin `ventas_pantalla` el paginador de `search/sale/null/1` es el de siempre: sin `totales` y, para
     * un empleado acotado, sin acotar.
     *
     * @test
     */
    public function search_sin_la_marca_es_el_paginador_de_siempre()
    {
        $this->actuar_como($this->empleado_acotado_a_sus_ventas($this->empleado_a));

        $r = $this->buscar_filtrado(null);

        $this->assertArrayNotHasKey('totales', $r['json']);
        $this->assertSame(6, $r['json']['total']);
        $this->assertEqualsCanonicalizing($this->todas_las_ids(), $r['ids']);
    }

    // ---------------------------------------------------------------------------------------
    // 9. Con orden activo (ordenar_de) no revienta ONLY_FULL_GROUP_BY
    // ---------------------------------------------------------------------------------------

    /**
     * Un `ordenar_de` de una columna propia deja un `ORDER BY total` en el builder de la búsqueda: los
     * agregados y los contadores tienen que correr sobre una copia SIN orden (si no, MySQL con
     * ONLY_FULL_GROUP_BY responde 500), y las filas salen en el orden pedido.
     *
     * @test
     */
    public function con_ordenar_de_de_una_columna_propia_no_revienta_y_conserva_el_orden()
    {
        $this->actuar_como($this->owner);

        $r = $this->buscar_global([], ['filters' => [['key' => 'total', 'type' => 'number', 'ordenar_de' => 'desc']]]);
        $this->assertSame($this->ids(['b_s2', 'b_s1', 'a_s2', 'a_s1', 'dueno_s2', 'dueno_s1']), $r['ids'], 'Las filas salen en el orden pedido.');
        $this->assertEquals(2100, $r['totales']['pesos']['total']);
        $this->assertCount(2, $r['totales']['por_sucursal']);

        $this->actuar_como($this->empleado_acotado_a_sus_ventas($this->empleado_a));

        $r = $this->buscar_global([], ['filters' => [['key' => 'total', 'type' => 'number', 'ordenar_de' => 'asc']]]);
        $this->assertSame($this->ids(['a_s1', 'a_s2']), $r['ids']);
        $this->assertEquals(700, $r['totales']['pesos']['total']);

        $r = $this->buscar_filtrado([], [['key' => 'total', 'type' => 'number', 'ordenar_de' => 'desc']]);
        $this->assertSame($this->ids(['a_s2', 'a_s1']), $r['ids']);
        $this->assertEquals(700, $r['json']['totales']['pesos']['total']);
    }

    /**
     * Un `ordenar_de` de una columna de RELACIÓN ordena por una subconsulta correlacionada (con su
     * propio `ORDER BY (SELECT ...)`): también se descarta para los agregados y no rompe nada.
     *
     * @test
     */
    public function con_ordenar_de_de_una_relacion_no_revienta()
    {
        $this->actuar_como($this->owner);

        $r = $this->buscar_global([], ['filters' => [['key' => 'employee_id', 'type' => 'select', 'ordenar_de' => 'asc']]]);

        $this->assertSame(6, $r['total']);
        $this->assertEquals(2100, $r['totales']['pesos']['total']);
        $this->assertSame(2, $r['totales']['sin_empleado']);

        $r = $this->buscar_filtrado([], [['key' => 'employee_id', 'type' => 'select', 'ordenar_de' => 'desc']]);
        $this->assertSame(6, $r['json']['total']);
        $this->assertEquals(2100, $r['json']['totales']['pesos']['total']);
    }

    // ---------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------

    /**
     * Cuerpo de `POST global-search/sale` como lo arma la pantalla de Ventas: busca el texto de aislamiento
     * en las observaciones.
     *
     * @return array
     */
    protected function cuerpo_global()
    {
        return [
            'query_value'    => self::TEXTO,
            'props'          => [['key' => 'observations', 'keyword_mode' => 'todas']],
            'relation_props' => [],
            'extra_filters'  => [],
            'filters'        => [],
            'conector'       => 'or',
            'per_page'       => 50,
        ];
    }

    /**
     * Valores de `ventas_pantalla` con los mismos nombres y valores por defecto que la query string del
     * listado paginado. `$valores` pisa a los de siempre.
     *
     * @param  array $valores
     * @return array
     */
    protected function marca($valores)
    {
        return array_merge([
            'only_owner'                  => 0,
            'ventas_cobradas_show_option' => 'cobradas-y-no-cobradas',
            'afip_ticket_show_option'     => 'con-y-sin-factura',
            'payment_method_show_option'  => 'todos',
            'mostrar_consolidadas'        => 0,
        ], $valores);
    }

    /**
     * Pega a `POST global-search/sale` con o sin la marca.
     *
     * @param  array|null $marca  Valores de `ventas_pantalla`; null = el pedido NO lleva la marca.
     * @param  array      $extra  Claves del cuerpo que pisan a `cuerpo_global()` (texto, filtros, per_page).
     * @param  int        $pagina Página, en la query string.
     * @return array ['json', 'ids', 'total', 'totales'(null si no vino)]
     */
    protected function buscar_global($marca, $extra = [], $pagina = 1)
    {
        $cuerpo = array_merge($this->cuerpo_global(), $extra);

        if (!is_null($marca)) {
            $cuerpo['ventas_pantalla'] = $this->marca($marca);
        }

        $response = $this->postJson('api/global-search/sale?page='.$pagina, $cuerpo);
        $response->assertStatus(200);

        $json = $response->json();

        return [
            'json'    => $json,
            'ids'     => array_column($json['models']['data'], 'id'),
            'total'   => $json['models']['total'],
            'totales' => isset($json['totales']) ? $json['totales'] : null,
        ];
    }

    /**
     * Pega a `POST search/sale/null/1` (filtros de columna, paginador pelado) con o sin la marca. Siempre
     * lleva el filtro de texto sobre las observaciones que aísla las ventas del test.
     *
     * @param  array|null $marca    Valores de `ventas_pantalla`; null = sin marca.
     * @param  array      $filtros  Filtros de columna extra.
     * @param  int        $pagina
     * @return array ['json', 'ids']
     */
    protected function buscar_filtrado($marca, $filtros = [], $pagina = 1)
    {
        $cuerpo = [
            'filters'  => array_merge([['key' => 'observations', 'type' => 'text', 'que_contenga' => self::TEXTO]], $filtros),
            'per_page' => 50,
        ];

        if (!is_null($marca)) {
            $cuerpo['ventas_pantalla'] = $this->marca($marca);
        }

        $response = $this->postJson('api/search/sale/null/1?page='.$pagina, $cuerpo);
        $response->assertStatus(200);

        $json = $response->json();

        return [
            'json' => $json,
            'ids'  => array_column($json['data'], 'id'),
        ];
    }

    /**
     * Cambia el usuario autenticado (el guard cachea al primero: mismo recurso que usa el test 45).
     *
     * @param  \App\Models\User $user
     * @return void
     */
    protected function actuar_como($user)
    {
        Auth::forgetGuards();

        $this->actingAs($user, 'web');
    }

    /**
     * Empleado del dueño del fixture con los permisos pedidos. SIEMPRE lleva "Ver ventas de días
     * anteriores": este test no es de días (eso es el 47) y las ventas son de un día lejano, que sin ese
     * permiso la búsqueda recortaría a hoy.
     *
     * @param  array    $slugs
     * @param  int|null $address_id Sucursal asignada.
     * @param  array    $extra      Campos extra del usuario (`admin_access`, ...).
     * @return \App\Models\User
     */
    protected function crear_empleado($slugs = [], $address_id = null, $extra = [])
    {
        $user = User::create(array_merge([
            'name'         => 'Empleado '.uniqid(),
            'company_name' => 'Ferreteria busqueda de ventas',
            'email'        => 'busqueda-'.uniqid().'@test.local',
            'password'     => Hash::make('x'),
            'owner_id'     => $this->owner->id,
            'address_id'   => $address_id,
        ], $extra));

        $this->dar_permisos($user, array_merge($slugs, ['sale.index.previus_days']));

        return $user->fresh();
    }

    /**
     * Le da "solo sus propias ventas" a un empleado que ya tiene ventas cargadas (A o B).
     *
     * @param  \App\Models\User $empleado
     * @return \App\Models\User
     */
    protected function empleado_acotado_a_sus_ventas($empleado)
    {
        $this->dar_permisos($empleado, ['sale.index.employees.only_your', 'sale.index.previus_days']);

        return $empleado->fresh();
    }

    /**
     * Deja al usuario con exactamente esos permisos (crea el permiso si la base no lo tiene).
     *
     * @param  \App\Models\User $user
     * @param  array            $slugs
     * @return void
     */
    protected function dar_permisos($user, $slugs)
    {
        $ids = [];
        foreach ($slugs as $slug) {
            $permiso = PermissionEmpresa::where('slug', $slug)->first();
            if (is_null($permiso)) {
                $permiso = PermissionEmpresa::forceCreate(['slug' => $slug, 'name' => $slug, 'model_name' => 'Ventas']);
            }
            $ids[] = $permiso->id;
        }
        if (count($ids)) {
            $user->permissions()->sync($ids);
        }
    }

    /**
     * Venta terminada del dueño, en pesos, el día del test, con el texto de aislamiento.
     *
     * @param  float    $total
     * @param  int|null $employee_id
     * @param  int|null $address_id
     * @param  array    $extra       Campos que pisan a los de arriba.
     * @return \App\Models\Sale
     */
    protected function crear_venta($total, $employee_id, $address_id, $extra = [])
    {
        return Sale::create(array_merge([
            'user_id'                    => $this->owner->id,
            'employee_id'                => $employee_id,
            'address_id'                 => $address_id,
            'moneda_id'                  => 1,
            'total'                      => $total,
            'sub_total'                  => $total,
            'total_cost'                 => 0,
            'ganancia'                   => $total,
            'terminada'                  => 1,
            'confirmed'                  => 1,
            'omitir_en_cuenta_corriente' => 1,
            'observations'               => self::TEXTO.($address_id == $this->sucursal_1 ? ' alfa' : ' beta'),
            'created_at'                 => $this->dia.' 12:00:00',
            'terminada_at'               => $this->dia.' 12:00:00',
        ], $extra));
    }

    /**
     * Ids de las ventas del fixture por nombre.
     *
     * @param  array $nombres
     * @return array
     */
    protected function ids($nombres)
    {
        return array_map(function ($nombre) {
            return $this->ventas[$nombre]->id;
        }, $nombres);
    }

    /**
     * Ids de las seis ventas base del test.
     *
     * @return array
     */
    protected function todas_las_ids()
    {
        return $this->ids(array_keys($this->ventas));
    }
}
