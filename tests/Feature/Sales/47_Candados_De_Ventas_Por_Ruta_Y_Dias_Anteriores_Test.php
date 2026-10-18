<?php

namespace Tests\Feature\Sales;

use App\Http\Controllers\Helpers\sale\ListadoVentasHelper;
use App\Models\PermissionEmpresa;
use App\Models\Sale;
use App\Models\SaleStatus;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Maatwebsite\Excel\Facades\Excel;
use Tests\EmpresaTestCase;

/**
 * Misión alcance-de-ventas-caminos-faltantes (16/10/2026): los candados POR RUTA de la pantalla de
 * Ventas y el permiso de días anteriores, que hasta esta misión imponía solo la SPA.
 *
 * Lo que se protege acá, en orden de importancia:
 *
 *   1. `GET sale/from-date/ventas` SIN `per_page` (el camino de una SPA vieja) aplica el alcance del
 *      empleado: "solo sus ventas" / "solo su sucursal", sin contenedoras consolidadas. Dueño,
 *      administrador y quien no tiene permisos de alcance quedan intactos.
 *   2. Depósito, Por entregar y Por estado (`from-date/deposito|por_entregar|por_estado`) comparten el
 *      endpoint y NO se acotan ni por alcance ni por días: tienen permisos propios.
 *   3. Los contadores "(N)" de las solapas (`por_sucursal`, `por_empleado`, `sin_empleado`) salen
 *      acotados al alcance.
 *   4. Los CUATRO Excel de ventas responden 403 a quien no es dueño ni administrador, y 200 al dueño y
 *      al administrador. Sin usuario de sesión también es "no puede".
 *   5. Días anteriores (`sale.index.previus_days`): un empleado sin el permiso recibe SOLO las ventas de
 *      hoy pida la fecha, el rango o la falta de fecha que pida, por el listado paginado, por el listado
 *      sin `per_page` y por la búsqueda con marca. Con el permiso elige libre; dueño y administrador
 *      siempre libres; y no depende de `sale.index.total`.
 *
 * @group sales
 */
class Candados_De_Ventas_Por_Ruta_Y_Dias_Anteriores_Test extends EmpresaTestCase
{
    /** Día fijo y lejano, que ningún otro test usa, para las ventas de los candados de alcance. */
    public $dia = '2037-09-21';

    /** Texto que llevan las observaciones de las ventas de HOY/AYER: aísla la búsqueda del resto de la base. */
    const TEXTO = 'T47';

    /** Sucursal "Principal" del fixture (id 1, del usuario 500) y otra que solo existe en las ventas. */
    public $sucursal_1 = 1;
    public $sucursal_2 = 2;

    /** @var \App\Models\User */
    protected $owner;

    /** @var \App\Models\User */
    protected $empleado_a;

    /** @var \App\Models\User */
    protected $empleado_b;

    /** @var array Ventas del día fijo, por nombre. */
    protected $ventas = [];

    /**
     * Seis ventas del dueño en el día fijo, en dos sucursales y de tres vendedores:
     *
     *   dueño_s1 100 · dueño_s2 200 · A_s1 300 · A_s2 400 · B_s1 500 · B_s2 600
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
    // 1. Listado de Ventas SIN per_page: el alcance lo impone el servidor
    // ---------------------------------------------------------------------------------------

    /**
     * Sin `per_page`, un empleado "solo sus ventas" recibe solo las suyas aunque la SPA vieja (o un
     * pedido armado a mano) mande la solapa de otro, la del dueño o ninguna: esos parámetros ni se leen
     * en este camino y el alcance sale de sus permisos.
     *
     * @test
     */
    public function sin_per_page_el_empleado_con_solo_las_suyas_recibe_solo_las_suyas()
    {
        $this->actuar_como($this->empleado_acotado_a_sus_ventas($this->empleado_a));

        foreach (['', '?employee_id='.$this->empleado_b->id, '?only_owner=1', '?address_id='.$this->sucursal_2] as $query) {
            $response = $this->getJson('api/sale/from-date/ventas/'.$this->dia.$query);
            $response->assertStatus(200);

            $this->assertEqualsCanonicalizing($this->ids(['a_s1', 'a_s2']), array_column($response->json('models'), 'id'), 'Query: '.$query);
            $this->assertArrayNotHasKey('totales', $response->json(), 'Sin per_page la respuesta es la de siempre.');
        }
    }

    /**
     * Sin `per_page`, un empleado "solo su sucursal" recibe solo las ventas de esa sucursal.
     *
     * @test
     */
    public function sin_per_page_el_empleado_con_solo_su_sucursal_recibe_solo_esa()
    {
        $this->actuar_como($this->crear_empleado(['sale.index.addresses.only_your'], $this->sucursal_1));

        $response = $this->getJson('api/sale/from-date/ventas/'.$this->dia.'?address_id='.$this->sucursal_2);
        $response->assertStatus(200);

        $this->assertEqualsCanonicalizing($this->ids(['dueno_s1', 'a_s1', 'b_s1']), array_column($response->json('models'), 'id'));
    }

    /**
     * Las contenedoras de facturación consolidada (sin sucursal ni empleado) quedan afuera del
     * acotado; el dueño las sigue recibiendo en este camino, como siempre (este listado no las saca).
     *
     * @test
     */
    public function sin_per_page_el_acotado_no_recibe_contenedoras_y_el_dueno_si()
    {
        $contenedora = $this->crear_venta(900, null, null, ['is_consolidacion_facturacion' => 1]);

        $this->actuar_como($this->empleado_acotado_a_sus_ventas($this->empleado_a));
        $this->assertNotContains($contenedora->id, $this->ids_del_listado_sin_per_page());

        $this->actuar_como($this->crear_empleado(['sale.index.addresses.only_your'], $this->sucursal_1));
        $this->assertNotContains($contenedora->id, $this->ids_del_listado_sin_per_page());

        $this->actuar_como($this->owner);
        $this->assertContains($contenedora->id, $this->ids_del_listado_sin_per_page());
    }

    /**
     * Dueño, administrador (aunque tenga "solo las mías" tildado) y empleado sin permisos de alcance (o
     * con "todos") reciben todas las ventas del día sin per_page: nadie pierde lo que ya veía.
     *
     * @test
     */
    public function sin_per_page_duenio_administrador_y_sin_permisos_de_alcance_quedan_intactos()
    {
        $todas = $this->ids(array_keys($this->ventas));

        $this->actuar_como($this->owner);
        $this->assertEqualsCanonicalizing($todas, $this->ids_del_listado_sin_per_page());

        $this->actuar_como($this->crear_empleado(
            ['sale.index.employees.only_your', 'sale.index.addresses.only_your'],
            $this->sucursal_1,
            ['admin_access' => 1]
        ));
        $this->assertEqualsCanonicalizing($todas, $this->ids_del_listado_sin_per_page());

        $this->actuar_como($this->crear_empleado([], $this->sucursal_1));
        $this->assertEqualsCanonicalizing($todas, $this->ids_del_listado_sin_per_page());

        $this->actuar_como($this->crear_empleado(['sale.index.employees.all', 'sale.index.employees.only_your'], $this->sucursal_1));
        $this->assertEqualsCanonicalizing($todas, $this->ids_del_listado_sin_per_page());
    }

    // ---------------------------------------------------------------------------------------
    // 2. Depósito, Por entregar y Por estado: intactos
    // ---------------------------------------------------------------------------------------

    /**
     * `from-date/deposito|por_entregar|por_estado` comparten el endpoint y NO se acotan: un empleado
     * "solo sus ventas" y "solo su sucursal" recibe también las de los demás en cada uno.
     *
     * @test
     */
    public function deposito_por_entregar_y_por_estado_no_se_acotan()
    {
        $por_entregar = $this->crear_venta(70, $this->empleado_b->id, $this->sucursal_2, ['terminada' => 0, 'terminada_at' => null]);
        $con_estado = $this->crear_venta(80, $this->empleado_b->id, $this->sucursal_2, ['sale_status_id' => $this->crear_estado_de_venta()->id]);

        foreach ([
            $this->empleado_acotado_a_sus_ventas($this->empleado_a),
            $this->crear_empleado(['sale.index.addresses.only_your'], $this->sucursal_1),
        ] as $empleado) {
            $this->actuar_como($empleado);

            $deposito = $this->ids_de_modulo('deposito');
            $this->assertContains($this->ventas['b_s2']->id, $deposito, 'Depósito trae las ventas de todos.');
            $this->assertContains($this->ventas['dueno_s1']->id, $deposito);

            $this->assertContains($por_entregar->id, $this->ids_de_modulo('por_entregar'), 'Por entregar trae las de todos.');

            $this->assertContains($con_estado->id, $this->ids_de_modulo('por_estado'), 'Por estado trae las de todos.');
        }
    }

    // ---------------------------------------------------------------------------------------
    // 3. Contadores por solapa acotados
    // ---------------------------------------------------------------------------------------

    /**
     * En el listado paginado los contadores "(N)" de las solapas salen acotados al alcance: un empleado
     * "solo sus ventas" no recibe cuántas ventas tiene cada sucursal ni cada compañero, y uno "solo su
     * sucursal" no recibe las de la otra sucursal. El dueño los recibe todos.
     *
     * @test
     */
    public function los_contadores_por_solapa_del_listado_paginado_salen_acotados()
    {
        $this->actuar_como($this->owner);
        $dueno = $this->totales_del_dia_paginado();
        $this->assertEqualsCanonicalizing(
            [['address_id' => $this->sucursal_1, 'cantidad' => 3], ['address_id' => $this->sucursal_2, 'cantidad' => 3]],
            $dueno['por_sucursal']
        );
        $this->assertEqualsCanonicalizing(
            [['employee_id' => $this->empleado_a->id, 'cantidad' => 2], ['employee_id' => $this->empleado_b->id, 'cantidad' => 2]],
            $dueno['por_empleado']
        );
        $this->assertSame(2, $dueno['sin_empleado']);

        $this->actuar_como($this->empleado_acotado_a_sus_ventas($this->empleado_a));
        $propio = $this->totales_del_dia_paginado();
        $this->assertEqualsCanonicalizing(
            [['address_id' => $this->sucursal_1, 'cantidad' => 1], ['address_id' => $this->sucursal_2, 'cantidad' => 1]],
            $propio['por_sucursal']
        );
        $this->assertSame([['employee_id' => $this->empleado_a->id, 'cantidad' => 2]], $propio['por_empleado']);
        $this->assertSame(0, $propio['sin_empleado'], 'No recibe cuántas ventas tiene el dueño.');

        $this->actuar_como($this->crear_empleado(['sale.index.addresses.only_your'], $this->sucursal_1));
        $sucursal = $this->totales_del_dia_paginado();
        $this->assertSame([['address_id' => $this->sucursal_1, 'cantidad' => 3]], $sucursal['por_sucursal']);
        $this->assertEqualsCanonicalizing(
            [['employee_id' => $this->empleado_a->id, 'cantidad' => 1], ['employee_id' => $this->empleado_b->id, 'cantidad' => 1]],
            $sucursal['por_empleado']
        );
        $this->assertSame(1, $sucursal['sin_empleado']);
    }

    // ---------------------------------------------------------------------------------------
    // 4. Excel: solo dueño o administrador
    // ---------------------------------------------------------------------------------------

    /**
     * Los cuatro Excel (los dos POST de la pantalla y los dos GET viejos) responden 403 con mensaje a un
     * empleado que no es administrador, aunque tenga todos los permisos de ventas.
     *
     * @test
     */
    public function los_cuatro_excel_dan_403_a_un_empleado_que_no_es_administrador()
    {
        Excel::fake();

        foreach ([
            $this->crear_empleado([]),
            $this->crear_empleado(['sale.index.total', 'sale.index.employees.all', 'sale.index.addresses.all', 'sale.index.previus_days']),
        ] as $empleado) {
            foreach ($this->los_cuatro_excel() as $nombre => $pedir) {
                $this->actuar_como($empleado);

                $response = $pedir();

                $response->assertStatus(403);
                $this->assertSame(ListadoVentasHelper::MENSAJE_SIN_PERMISO_PARA_EXPORTAR, $response->json('message'), $nombre);
            }
        }
    }

    /**
     * Dueño y administrador (aunque tenga "solo las mías" tildado) exportan: los cuatro Excel dan 200.
     *
     * @test
     */
    public function los_cuatro_excel_dan_200_al_dueno_y_al_administrador()
    {
        Excel::fake();

        $administrador = $this->crear_empleado(['sale.index.employees.only_your'], $this->sucursal_1, ['admin_access' => 1]);

        foreach ([$this->owner, $administrador] as $usuario) {
            foreach ($this->los_cuatro_excel() as $nombre => $pedir) {
                $this->actuar_como($usuario);

                $pedir()->assertStatus(200);
            }
        }
    }

    /**
     * Sin usuario de sesión (las rutas GET viejas entran por su propio middleware y pueden llegar así)
     * nadie exporta, y tampoco hay "solo hoy" ni alcance que aplicar: las guardas son null-safe.
     *
     * @test
     */
    public function sin_usuario_de_sesion_las_guardas_no_revientan_y_no_dejan_exportar()
    {
        Auth::forgetGuards();

        $this->assertFalse(ListadoVentasHelper::puede_exportar());
        $this->assertFalse(ListadoVentasHelper::solo_hoy_permitido());
        $this->assertSame(['address_id' => null, 'employee_id' => null], ListadoVentasHelper::alcance_permitido());
        $this->assertSame(['2037-01-01', null], ListadoVentasHelper::acotar_fechas('2037-01-01', null));
    }

    // ---------------------------------------------------------------------------------------
    // 5. Días anteriores
    // ---------------------------------------------------------------------------------------

    /**
     * Un empleado SIN `sale.index.previus_days` pidiendo OTRA fecha por el listado paginado recibe solo
     * las ventas de hoy: filas, `models.total` y `totales` son los de hoy.
     *
     * @test
     */
    public function sin_dias_anteriores_el_listado_paginado_con_otra_fecha_devuelve_solo_hoy()
    {
        $v = $this->ventas_de_hoy_y_ayer();
        $this->actuar_como($this->crear_empleado([], null, [], false));

        $respuesta = $this->pedir_paginado($v['ayer_dia']);

        $this->assertEqualsCanonicalizing($v['hoy_ids'], $this->mias($respuesta['ids'], $v));
        $this->assertNotContains($v['ayer_a']->id, $respuesta['ids']);
        $this->assertSame($respuesta['models_total'], $respuesta['totales']['cantidad'], 'Filas y totales salen del mismo día.');
    }

    /**
     * Pidiendo un RANGO que incluye ayer y hoy, o la fecha fija de los otros tests, recibe solo hoy.
     *
     * @test
     */
    public function sin_dias_anteriores_un_rango_o_una_fecha_lejana_devuelve_solo_hoy()
    {
        $v = $this->ventas_de_hoy_y_ayer();
        $this->actuar_como($this->crear_empleado([], null, [], false));

        $rango = $this->pedir_paginado($v['ayer_dia'].'/'.$v['hoy_dia']);
        $this->assertEqualsCanonicalizing($v['hoy_ids'], $this->mias($rango['ids'], $v));
        $this->assertNotContains($v['ayer_a']->id, $rango['ids']);

        $lejana = $this->pedir_paginado($this->dia);
        $this->assertSame([], array_values(array_intersect($lejana['ids'], $this->ids(array_keys($this->ventas)))), 'Las ventas del día fijo no se ven.');
        $this->assertEqualsCanonicalizing($v['hoy_ids'], $this->mias($lejana['ids'], $v));
    }

    /**
     * Sin mandar ninguna fecha (`from_date` ausente) un empleado sin el permiso recibe SOLO hoy, tanto
     * por el listado paginado como por el listado sin `per_page`; un dueño sin fecha no filtra por día
     * (el comportamiento de siempre de ese pedido).
     *
     * @test
     */
    public function sin_fecha_un_empleado_sin_dias_anteriores_recibe_solo_hoy()
    {
        $v = $this->ventas_de_hoy_y_ayer();

        $this->actuar_como($this->crear_empleado([], null, [], false));

        $paginado = $this->getJson('api/sale/from-date/ventas?per_page=200&page=1');
        $paginado->assertStatus(200);
        $ids = array_column($paginado->json('models.data'), 'id');
        $this->assertEqualsCanonicalizing($v['hoy_ids'], $this->mias($ids, $v));
        $this->assertNotContains($v['ayer_a']->id, $ids);

        $sin_per_page = $this->getJson('api/sale/from-date/ventas');
        $sin_per_page->assertStatus(200);
        $ids = array_column($sin_per_page->json('models'), 'id');
        $this->assertEqualsCanonicalizing($v['hoy_ids'], $this->mias($ids, $v));
        $this->assertNotContains($v['ayer_a']->id, $ids);

        $this->actuar_como($this->owner);
        $dueno = $this->getJson('api/sale/from-date/ventas');
        $dueno->assertStatus(200);
        $this->assertContains($v['ayer_a']->id, array_column($dueno->json('models'), 'id'), 'El dueño sin fecha no se recorta.');
    }

    /**
     * Sin el permiso, el listado SIN `per_page` con otra fecha también devuelve solo hoy.
     *
     * @test
     */
    public function sin_dias_anteriores_el_listado_sin_per_page_con_otra_fecha_devuelve_solo_hoy()
    {
        $v = $this->ventas_de_hoy_y_ayer();
        $this->actuar_como($this->crear_empleado([], null, [], false));

        $response = $this->getJson('api/sale/from-date/ventas/'.$v['ayer_dia']);
        $response->assertStatus(200);

        $ids = array_column($response->json('models'), 'id');
        $this->assertEqualsCanonicalizing($v['hoy_ids'], $this->mias($ids, $v));
        $this->assertNotContains($v['ayer_a']->id, $ids);
    }

    /**
     * Con el permiso, el empleado elige libre: ayer, un rango, cualquier fecha. Y el dueño y el
     * administrador (aunque no lo tengan tildado) también.
     *
     * @test
     */
    public function con_dias_anteriores_dueno_y_administrador_eligen_libre()
    {
        $v = $this->ventas_de_hoy_y_ayer();

        $libres = [
            'con el permiso'  => $this->crear_empleado([]),
            'dueño'           => $this->owner,
            'administrador'   => $this->crear_empleado([], null, ['admin_access' => 1], false),
        ];

        foreach ($libres as $quien => $usuario) {
            $this->actuar_como($usuario);

            $ayer = $this->pedir_paginado($v['ayer_dia']);
            $this->assertEqualsCanonicalizing([$v['ayer_a']->id], $this->mias($ayer['ids'], $v), $quien.': ayer');

            $rango = $this->pedir_paginado($v['ayer_dia'].'/'.$v['hoy_dia']);
            $this->assertEqualsCanonicalizing(array_merge($v['hoy_ids'], [$v['ayer_a']->id]), $this->mias($rango['ids'], $v), $quien.': rango');

            $lejana = $this->pedir_paginado($this->dia);
            $this->assertEqualsCanonicalizing($this->ids(array_keys($this->ventas)), $lejana['ids'], $quien.': fecha lejana');
        }
    }

    /**
     * El "solo hoy" no depende de `sale.index.total` (ni de los permisos de alcance): un empleado CON
     * "total" pero SIN días anteriores sigue viendo solo hoy, y uno SIN "total" pero CON días
     * anteriores elige libre.
     *
     * @test
     */
    public function el_solo_hoy_no_depende_del_permiso_de_total()
    {
        $v = $this->ventas_de_hoy_y_ayer();

        $this->actuar_como($this->crear_empleado(['sale.index.total', 'sale.index.employees.all', 'sale.index.addresses.all'], null, [], false));
        $this->assertNotContains($v['ayer_a']->id, $this->pedir_paginado($v['ayer_dia'])['ids']);

        $empleado = $this->crear_empleado([]);
        $this->assertFalse($empleado->permissions->contains('slug', 'sale.index.total'));
        $this->actuar_como($empleado);
        $this->assertContains($v['ayer_a']->id, $this->pedir_paginado($v['ayer_dia'])['ids']);
    }

    /**
     * Depósito, Por entregar y Por estado NO se recortan por días: un empleado sin días anteriores
     * pidiendo otra fecha recibe lo de esa fecha en `deposito`.
     *
     * @test
     */
    public function deposito_no_se_recorta_por_dias()
    {
        $this->actuar_como($this->crear_empleado([], null, [], false));

        $this->assertContains($this->ventas['a_s1']->id, $this->ids_de_modulo('deposito'), 'Depósito de otra fecha sigue trayendo lo de esa fecha.');
    }

    /**
     * La búsqueda con la marca también recorta a hoy a quien no tiene días anteriores (buscador general
     * y filtros de columna), mientras que con el permiso, el dueño y el administrador buscan en todas las
     * fechas; y SIN la marca (Depósito) nadie se recorta.
     *
     * @test
     */
    public function la_busqueda_con_marca_recorta_a_hoy_a_quien_no_tiene_dias_anteriores()
    {
        $v = $this->ventas_de_hoy_y_ayer();
        $ambas = array_merge($v['hoy_ids'], [$v['ayer_a']->id]);

        $sin_permiso = $this->crear_empleado([], null, [], false);
        $this->actuar_como($sin_permiso);

        $global = $this->buscar_global(true);
        $this->assertEqualsCanonicalizing($v['hoy_ids'], $global['ids']);
        $this->assertSame(count($v['hoy_ids']), $global['totales']['cantidad'], 'Los totales son los de hoy.');

        $filtrada = $this->buscar_filtrada(true);
        $this->assertEqualsCanonicalizing($v['hoy_ids'], $filtrada['ids']);
        $this->assertSame(count($v['hoy_ids']), $filtrada['json']['totales']['cantidad']);

        $this->assertEqualsCanonicalizing($ambas, $this->buscar_global(false)['ids'], 'Sin la marca (Depósito) no se recorta.');

        foreach ([$this->crear_empleado([]), $this->owner, $this->crear_empleado([], null, ['admin_access' => 1], false)] as $libre) {
            $this->actuar_como($libre);
            $this->assertEqualsCanonicalizing($ambas, $this->buscar_global(true)['ids']);
            $this->assertEqualsCanonicalizing($ambas, $this->buscar_filtrada(true)['ids']);
        }
    }

    // ---------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------

    /**
     * Los cuatro Excel de ventas como funciones que hacen el pedido y devuelven la respuesta: los dos
     * POST de la pantalla y los dos GET viejos de routes/web.php.
     *
     * @return array nombre => callable
     */
    protected function los_cuatro_excel()
    {
        $body = [
            'is_filtered'                 => false,
            'filters'                     => [],
            'from_date'                   => $this->dia,
            'until_date'                  => $this->dia,
            'ventas_cobradas_show_option' => 'cobradas-y-no-cobradas',
            'afip_ticket_show_option'     => 'con-y-sin-factura',
            'payment_method_show_option'  => 'todos',
            'address_id'                  => null,
            'employee_id'                 => null,
            'only_owner'                  => false,
        ];

        return [
            'POST excel'      => function () use ($body) { return $this->post('api/sales/excel/export', $body); },
            'POST excel full' => function () use ($body) { return $this->post('api/sales/excel/breakdown-export', $body); },
            'GET excel'       => function () { return $this->get('sales/excel/export/'.$this->dia.'/'.$this->dia); },
            'GET excel full'  => function () { return $this->get('sales/excel/breakdown-export/'.$this->dia.'/'.$this->dia); },
        ];
    }

    /**
     * Ids del listado de ventas del día fijo SIN `per_page`.
     *
     * @return array
     */
    protected function ids_del_listado_sin_per_page()
    {
        $response = $this->getJson('api/sale/from-date/ventas/'.$this->dia);
        $response->assertStatus(200);

        return array_column($response->json('models'), 'id');
    }

    /**
     * Ids que devuelve un módulo (`deposito`, `por_entregar`, `por_estado`) del día fijo, sin `per_page`.
     *
     * @param  string $modulo
     * @return array
     */
    protected function ids_de_modulo($modulo)
    {
        $response = $this->getJson('api/sale/from-date/'.$modulo.'/'.$this->dia);
        $response->assertStatus(200);

        return array_column($response->json('models'), 'id');
    }

    /**
     * `totales` del listado paginado del día fijo.
     *
     * @return array
     */
    protected function totales_del_dia_paginado()
    {
        $response = $this->getJson('api/sale/from-date/ventas/'.$this->dia.'?per_page=50&page=1');
        $response->assertStatus(200);

        return $response->json('totales');
    }

    /**
     * Pega al listado paginado con el segmento de fecha que se le dé (`2037-01-01` o `A/B`).
     *
     * @param  string $segmento_de_fechas
     * @return array ['ids', 'totales', 'models_total']
     */
    protected function pedir_paginado($segmento_de_fechas)
    {
        $response = $this->getJson('api/sale/from-date/ventas/'.$segmento_de_fechas.'?per_page=200&page=1');
        $response->assertStatus(200);

        return [
            'ids'          => array_column($response->json('models.data'), 'id'),
            'totales'      => $response->json('totales'),
            'models_total' => $response->json('models.total'),
        ];
    }

    /**
     * Tres ventas de HOY (dueño, A y B) y una de AYER (de A), todas con el texto de aislamiento. La base
     * del slot puede tener ventas de hoy de otros tests o fixtures: por eso los tests comparan solo las
     * ventas propias (`mias()`).
     *
     * @return array ['hoy_dia', 'ayer_dia', 'hoy_ids', 'ayer_a', 'todas']
     */
    protected function ventas_de_hoy_y_ayer()
    {
        $hoy = Carbon::today();
        $ayer = Carbon::yesterday();

        $hoy_dueno = $this->crear_venta(10, null, $this->sucursal_1, ['created_at' => $hoy->format('Y-m-d').' 12:00:00', 'observations' => self::TEXTO]);
        $hoy_a = $this->crear_venta(20, $this->empleado_a->id, $this->sucursal_1, ['created_at' => $hoy->format('Y-m-d').' 12:00:00', 'observations' => self::TEXTO]);
        $hoy_b = $this->crear_venta(30, $this->empleado_b->id, $this->sucursal_2, ['created_at' => $hoy->format('Y-m-d').' 12:00:00', 'observations' => self::TEXTO]);
        $ayer_a = $this->crear_venta(40, $this->empleado_a->id, $this->sucursal_1, ['created_at' => $ayer->format('Y-m-d').' 12:00:00', 'observations' => self::TEXTO]);

        return [
            'hoy_dia'  => $hoy->format('Y-m-d'),
            'ayer_dia' => $ayer->format('Y-m-d'),
            'hoy_ids'  => [$hoy_dueno->id, $hoy_a->id, $hoy_b->id],
            'ayer_a'   => $ayer_a,
            'todas'    => [$hoy_dueno->id, $hoy_a->id, $hoy_b->id, $ayer_a->id],
        ];
    }

    /**
     * De una lista de ids, solo los de las ventas de `ventas_de_hoy_y_ayer()` (sin las de la base).
     *
     * @param  array $ids
     * @param  array $v   Lo que devolvió `ventas_de_hoy_y_ayer()`.
     * @return array
     */
    protected function mias($ids, $v)
    {
        return array_values(array_intersect($ids, $v['todas']));
    }

    /**
     * `POST global-search/sale` buscando el texto de aislamiento, con o sin la marca de la pantalla.
     *
     * @param  bool $con_marca
     * @return array ['ids', 'totales']
     */
    protected function buscar_global($con_marca)
    {
        $cuerpo = [
            'query_value'    => self::TEXTO,
            'props'          => [['key' => 'observations', 'keyword_mode' => 'todas']],
            'relation_props' => [],
            'extra_filters'  => [],
            'filters'        => [],
            'conector'       => 'or',
            'per_page'       => 200,
        ];

        if ($con_marca) {
            $cuerpo['ventas_pantalla'] = $this->marca();
        }

        $response = $this->postJson('api/global-search/sale?page=1', $cuerpo);
        $response->assertStatus(200);

        return [
            'ids'     => array_column($response->json('models.data'), 'id'),
            'totales' => $response->json('totales'),
        ];
    }

    /**
     * `POST search/sale/null/1` filtrando por el texto de aislamiento, con o sin la marca.
     *
     * @param  bool $con_marca
     * @return array ['ids', 'json']
     */
    protected function buscar_filtrada($con_marca)
    {
        $cuerpo = [
            'filters'  => [['key' => 'observations', 'type' => 'text', 'que_contenga' => self::TEXTO]],
            'per_page' => 200,
        ];

        if ($con_marca) {
            $cuerpo['ventas_pantalla'] = $this->marca();
        }

        $response = $this->postJson('api/search/sale/null/1?page=1', $cuerpo);
        $response->assertStatus(200);

        return [
            'ids'  => array_column($response->json('data'), 'id'),
            'json' => $response->json(),
        ];
    }

    /**
     * La marca `ventas_pantalla` con los valores por defecto de la pantalla.
     *
     * @return array
     */
    protected function marca()
    {
        return [
            'only_owner'                  => 0,
            'ventas_cobradas_show_option' => 'cobradas-y-no-cobradas',
            'afip_ticket_show_option'     => 'con-y-sin-factura',
            'payment_method_show_option'  => 'todos',
            'mostrar_consolidadas'        => 0,
        ];
    }

    /**
     * Estado de venta real del dueño (lo que lista Por estado).
     *
     * @return SaleStatus
     */
    protected function crear_estado_de_venta()
    {
        return SaleStatus::create([
            'name'        => 'Candados de ventas (test)',
            'description' => '',
            'position'    => 1,
            'user_id'     => $this->owner->id,
        ]);
    }

    /**
     * Cambia el usuario autenticado (el guard cachea al primero).
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
     * Empleado del dueño del fixture con los permisos pedidos.
     *
     * @param  array    $slugs
     * @param  int|null $address_id            Sucursal asignada.
     * @param  array    $extra                 Campos extra del usuario (`admin_access`, ...).
     * @param  bool     $con_dias_anteriores   true (default) le suma "Ver ventas de días anteriores";
     *                                         false lo deja sin él (el caso de los tests de días).
     * @return \App\Models\User
     */
    protected function crear_empleado($slugs = [], $address_id = null, $extra = [], $con_dias_anteriores = true)
    {
        $user = User::create(array_merge([
            'name'         => 'Empleado '.uniqid(),
            'company_name' => 'Ferreteria candados de ventas',
            'email'        => 'candados-'.uniqid().'@test.local',
            'password'     => Hash::make('x'),
            'owner_id'     => $this->owner->id,
            'address_id'   => $address_id,
        ], $extra));

        if ($con_dias_anteriores) {
            $slugs = array_merge($slugs, ['sale.index.previus_days']);
        }

        $this->dar_permisos($user, $slugs);

        return $user->fresh();
    }

    /**
     * Le da "solo sus propias ventas" (y días anteriores, para poder pedir el día fijo) a un empleado
     * que ya tiene ventas cargadas.
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
     * Venta terminada del dueño, en pesos. Por defecto del día fijo del test.
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
            'created_at'                 => $this->dia.' 12:00:00',
            'terminada_at'               => $this->dia.' 12:00:00',
        ], $extra));
    }

    /**
     * Ids de las ventas del día fijo por nombre.
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
}
