<?php

namespace Tests\Feature\Empleados;

use App\Http\Controllers\Helpers\PermisosCatalogoHelper;
use App\Http\Controllers\Helpers\sale\ListadoVentasHelper;
use App\Models\PermissionEmpresa;
use App\Models\User;
use Database\Seeders\EmployeeSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PermissionVentasDiasAnterioresSeeder;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión seeder-dias-anteriores-de-ventas (17/10/2026) — `sale.index.previus_days` para quien hoy
 * tiene `sale.index`.
 *
 * Hasta el 16/10/2026 el permiso «Ver ventas de días anteriores» solo escondía la tira de días de
 * Ventas: el calendario le quedaba a la vista a cualquiera y un empleado sin el permiso elegía igual
 * cualquier día. Desde la misión alcance-de-ventas-caminos-faltantes el servidor lo hace valer
 * (`ListadoVentasHelper::solo_hoy_permitido`): sin el permiso, solo ve las ventas de hoy. Para que los
 * empleados que hoy miran otros días no lo pierdan de golpe con el release, el seeder suelto
 * `PermissionVentasDiasAnterioresSeeder` se lo da a todo el que tenga `sale.index` (decisión de Lucas,
 * 17/10/2026: ningún otro permiso lo hereda). El dueño se lo saca después a quien no corresponda.
 *
 * Lo que estos tests cuidan:
 *  - que las bases nuevas lo reciban por el catálogo, con su nombre y su grupo;
 *  - que el seeder suelto se lo dé exactamente a quien corresponde (y a nadie más), sin duplicar nada
 *    si corre dos veces o si el catálogo ya lo había creado;
 *  - las bases viejas con slugs repetidos (los pivots cuelgan de cualquiera de los ids) y los pivots
 *    huérfanos de usuarios que ya no existen;
 *  - que el efecto sea el buscado: el empleado con `sale.index` pasa de «solo hoy» a ver otros días;
 *  - que los empleados de ejemplo de las demos lo traigan (la demo no corre el seeder suelto).
 *
 * @group empleados
 */
class Permiso_dias_anteriores_de_ventas_Test extends EmpresaTestCase
{
    const SLUG = 'sale.index.previus_days';

    /**
     * @var \App\Models\User
     */
    protected $owner;

    protected function setUp(): void
    {
        parent::setUp();

        // Igual que el test del catálogo: se parte de la tabla de permisos vacía (otra suite puede
        // haber dejado filas). DatabaseTransactions lo revierte al terminar.
        DB::table('permission_empresa_user')->delete();
        PermissionEmpresa::query()->delete();

        $this->owner = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    /**
     * Base nueva: `PermissionSeeder` (lo llaman `DatabaseSeeder`, `UserSetupHelper` y
     * `DemoSetupHelper`) deja el permiso con el nombre y el grupo del catálogo.
     *
     * @return void
     */
    public function test_una_base_nueva_recibe_el_permiso_por_el_catalogo()
    {
        $this->seed(PermissionSeeder::class);

        $permisos = PermissionEmpresa::where('slug', self::SLUG)->get();

        $this->assertCount(1, $permisos);
        $this->assertEquals('Ver ventas de días anteriores (sin esto solo ve las de hoy)', $permisos->first()->name);
        $this->assertEquals('Ventas', $permisos->first()->model_name);
    }

    /**
     * Base existente sin la fila: el seeder suelto la crea con el nombre y el grupo del catálogo
     * (no con unos propios que se desalineen).
     *
     * @return void
     */
    public function test_el_seeder_suelto_crea_el_permiso_si_la_base_no_lo_tiene()
    {
        $this->seed(PermissionVentasDiasAnterioresSeeder::class);

        $permisos = PermissionEmpresa::where('slug', self::SLUG)->get();
        $fila = PermisosCatalogoHelper::filas()[self::SLUG];

        $this->assertCount(1, $permisos);
        $this->assertEquals($fila['nombre'], $permisos->first()->name);
        $this->assertEquals($fila['grupo'], $permisos->first()->model_name);
        $this->assertEquals('Ventas', $permisos->first()->model_name);
    }

    /**
     * El criterio de Lucas: lo hereda quien tiene `sale.index`, y NADIE más. El que tiene otros
     * permisos (incluidos los parientes de Ventas y los que heredan `comprobantes.index`) o ninguno no
     * recibe nada.
     *
     * @return void
     */
    public function test_se_lo_da_solo_a_quien_tiene_ver_ventas()
    {
        $sale_index = $this->permiso('sale.index');
        $article_index = $this->permiso('article.index');
        $client_index = $this->permiso('client.index');
        $devolucion_store = $this->permiso('devolucion.store');
        $sale_total = $this->permiso('sale.index.total');
        $solo_las_suyas = $this->permiso('sale.index.employees.only_your');
        $comprobantes = $this->permiso('comprobantes.index');

        $con_ventas = $this->empleado([$sale_index->id]);
        $con_ventas_y_mas = $this->empleado([$sale_index->id, $article_index->id, $solo_las_suyas->id]);
        $con_otros = $this->empleado([$article_index->id]);
        $con_clientes = $this->empleado([$client_index->id]);
        $con_devoluciones = $this->empleado([$devolucion_store->id]);
        $con_parientes = $this->empleado([$sale_total->id, $solo_las_suyas->id, $comprobantes->id]);
        $sin_permisos = $this->empleado([]);

        $resultado = (new PermissionVentasDiasAnterioresSeeder())->aplicar();

        $this->assertEquals(1, $resultado['permisos_creados']);
        $this->assertEquals(2, $resultado['empleados']);

        $dias = PermissionEmpresa::where('slug', self::SLUG)->first();

        $this->assertEquals(1, $this->filas_de_dias($con_ventas, [$dias->id]));
        $this->assertEquals(1, $this->filas_de_dias($con_ventas_y_mas, [$dias->id]));
        $this->assertEquals(0, $this->filas_de_dias($con_otros, [$dias->id]));
        $this->assertEquals(0, $this->filas_de_dias($con_clientes, [$dias->id]), 'client.index no lo hereda');
        $this->assertEquals(0, $this->filas_de_dias($con_devoluciones, [$dias->id]), 'devolucion.store no lo hereda');
        $this->assertEquals(0, $this->filas_de_dias($con_parientes, [$dias->id]), 'Los permisos parientes de Ventas (total, solo las suyas) no lo heredan');
        $this->assertEquals(0, $this->filas_de_dias($sin_permisos, [$dias->id]));

        // Lo que ya tenían queda como estaba.
        $this->assertEquals(
            collect([$sale_index->id, $article_index->id, $solo_las_suyas->id, $dias->id])->sort()->values()->all(),
            $con_ventas_y_mas->permissions()->pluck('permission_empresas.id')->sort()->values()->all()
        );
        $this->assertEquals([$article_index->id], $con_otros->permissions()->pluck('permission_empresas.id')->all());

        // El pivot lleva sus fechas, como el resto de las filas.
        $pivot = DB::table('permission_empresa_user')
                    ->where('user_id', $con_ventas->id)
                    ->where('permission_empresa_id', $dias->id)
                    ->first();
        $this->assertNotNull($pivot->created_at);
        $this->assertNotNull($pivot->updated_at);
    }

    /**
     * Un empleado administrador con `sale.index` también recibe la fila: no tiene ningún efecto para
     * él (el administrador nunca se recorta por días) y el criterio queda uno solo, sin excepciones.
     *
     * @return void
     */
    public function test_el_administrador_con_ver_ventas_tambien_recibe_la_fila_sin_efectos()
    {
        $sale_index = $this->permiso('sale.index');

        $administrador = $this->empleado([$sale_index->id], ['admin_access' => 1]);

        $resultado = (new PermissionVentasDiasAnterioresSeeder())->aplicar();

        $this->assertEquals(1, $resultado['empleados']);
        $this->assertFalse(ListadoVentasHelper::solo_hoy_permitido(User::find($administrador->id)));
    }

    /**
     * El efecto buscado, con la regla real del servidor: el empleado con `sale.index` que NO tenía el
     * permiso estaba en «solo hoy» (`ListadoVentasHelper::solo_hoy_permitido`) y después del seeder ya
     * no; el que no tiene `sale.index` sigue como estaba.
     *
     * @return void
     */
    public function test_el_empleado_con_ver_ventas_deja_de_estar_en_solo_hoy()
    {
        $sale_index = $this->permiso('sale.index');
        $article_index = $this->permiso('article.index');

        $con_ventas = $this->empleado([$sale_index->id]);
        $sin_ventas = $this->empleado([$article_index->id]);

        $this->assertTrue(ListadoVentasHelper::solo_hoy_permitido(User::find($con_ventas->id)), 'Antes del seeder estaba en solo hoy');
        $this->assertTrue(ListadoVentasHelper::solo_hoy_permitido(User::find($sin_ventas->id)));

        $this->seed(PermissionVentasDiasAnterioresSeeder::class);

        $this->assertFalse(ListadoVentasHelper::solo_hoy_permitido(User::find($con_ventas->id)), 'Después del seeder ve otros días');
        $this->assertTrue(ListadoVentasHelper::solo_hoy_permitido(User::find($sin_ventas->id)), 'Sin sale.index no se le regala nada');
    }

    /**
     * Sin ningún usuario con `sale.index` no hay a quién dárselo: devuelve 0 (y crea el permiso).
     *
     * @return void
     */
    public function test_sin_nadie_con_ver_ventas_no_da_nada()
    {
        $article_index = $this->permiso('article.index');
        $this->empleado([$article_index->id]);

        $resultado = (new PermissionVentasDiasAnterioresSeeder())->aplicar();

        $this->assertEquals(1, $resultado['permisos_creados']);
        $this->assertEquals(0, $resultado['empleados']);
    }

    /**
     * Correrlo dos veces no cambia nada: ni otra fila del permiso ni otra fila del pivot.
     *
     * @return void
     */
    public function test_correrlo_dos_veces_no_duplica_nada()
    {
        $sale_index = $this->permiso('sale.index');
        $article_index = $this->permiso('article.index');

        $this->empleado([$sale_index->id]);
        $this->empleado([$sale_index->id, $article_index->id]);

        $this->seed(PermissionVentasDiasAnterioresSeeder::class);

        $pivots_antes = $this->pivots();
        $this->assertCount(5, $pivots_antes, '3 filas de origen + 2 de días anteriores');

        $this->seed(PermissionVentasDiasAnterioresSeeder::class);
        $resultado = (new PermissionVentasDiasAnterioresSeeder())->aplicar();

        $this->assertEquals(0, $resultado['permisos_creados']);
        $this->assertEquals(0, $resultado['empleados']);
        $this->assertEquals(1, PermissionEmpresa::where('slug', self::SLUG)->count());
        $this->assertEquals($pivots_antes, $this->pivots());
    }

    /**
     * El caso de producción en que el catálogo ya creó la fila (por `PermisosOrdenarYCompletarSeeder`
     * o `PermissionSeeder`) antes que el seeder suelto: no crea otra y se lo da igual a los empleados.
     *
     * @return void
     */
    public function test_si_el_catalogo_ya_creo_el_permiso_no_crea_otro_y_se_lo_da_igual()
    {
        $this->seed(PermissionSeeder::class);

        $sale_index = PermissionEmpresa::where('slug', 'sale.index')->first();
        $dias = PermissionEmpresa::where('slug', self::SLUG)->first();

        $empleado = $this->empleado([$sale_index->id]);

        $resultado = (new PermissionVentasDiasAnterioresSeeder())->aplicar();

        $this->assertEquals(0, $resultado['permisos_creados']);
        $this->assertEquals(1, $resultado['empleados']);
        $this->assertEquals(1, PermissionEmpresa::where('slug', self::SLUG)->count());
        $this->assertEquals(1, $this->filas_de_dias($empleado, [$dias->id]));
    }

    /**
     * Base vieja con slugs repetidos (seeders viejos que usaban `create()`): los pivots pueden
     * colgar de cualquiera de los ids. Todo se resuelve por slug.
     *
     * @return void
     */
    public function test_base_vieja_con_slugs_repetidos()
    {
        $sale_index_1 = $this->permiso('sale.index');
        $sale_index_2 = $this->permiso('sale.index');
        $dias_1 = $this->permiso(self::SLUG);
        $dias_2 = $this->permiso(self::SLUG);

        // Colgado SOLO del segundo id de `sale.index`: igual tiene que recibirlo.
        $del_segundo_id = $this->empleado([$sale_index_2->id]);

        // Ya lo tiene, colgado del id duplicado de `sale.index.previus_days`: no recibe otra fila.
        $ya_lo_tiene = $this->empleado([$sale_index_1->id, $dias_2->id]);

        $resultado = (new PermissionVentasDiasAnterioresSeeder())->aplicar();

        $this->assertEquals(0, $resultado['permisos_creados'], 'Con el slug ya presente (aunque repetido) no se crea otra fila');
        $this->assertEquals(1, $resultado['empleados']);
        $this->assertEquals(2, PermissionEmpresa::where('slug', self::SLUG)->count());

        $ids_dias = [$dias_1->id, $dias_2->id];

        $this->assertEquals(1, $this->filas_de_dias($del_segundo_id, $ids_dias));
        // Se cuelga del id más bajo de `sale.index.previus_days`.
        $this->assertEquals(1, $this->filas_de_dias($del_segundo_id, [min($ids_dias)]));

        $this->assertEquals(1, $this->filas_de_dias($ya_lo_tiene, $ids_dias));
        $this->assertEquals(1, $this->filas_de_dias($ya_lo_tiene, [$dias_2->id]), 'Conserva la fila que ya tenía');
    }

    /**
     * Un pivot de `sale.index` que cuelga de un usuario que ya no existe (huérfano) no recibe fila
     * nueva ni cuenta como empleado.
     *
     * @return void
     */
    public function test_no_toca_a_los_usuarios_huerfanos()
    {
        $sale_index = $this->permiso('sale.index');
        $dias = $this->permiso(self::SLUG);

        $existente = $this->empleado([$sale_index->id]);

        // `user_id` de un usuario que no existe.
        $user_id_huerfano = (int) User::max('id') + 100000;
        DB::table('permission_empresa_user')->insert([
            'permission_empresa_id' => $sale_index->id,
            'user_id'               => $user_id_huerfano,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        $resultado = (new PermissionVentasDiasAnterioresSeeder())->aplicar();

        $this->assertEquals(1, $resultado['empleados'], 'Solo el que existe');
        $this->assertEquals(1, $this->filas_de_dias($existente, [$dias->id]));
        $this->assertEquals(
            0,
            DB::table('permission_empresa_user')
                ->where('user_id', $user_id_huerfano)
                ->where('permission_empresa_id', $dias->id)
                ->count(),
            'Al huérfano no se le suma ninguna fila'
        );
    }

    /**
     * Los empleados de ejemplo de las demos (`EmployeeSeeder`, que corre `DemoSetupHelper`) que tienen
     * `sale.index` lo traen: la demo no corre el seeder suelto, y sin esto no verían otros días en
     * Ventas desde que el servidor lo exige. Cuidado con el contrato: el que NO tiene `sale.index`
     * (Patricio, administrador) no lo necesita y no se le agrega.
     *
     * @return void
     */
    public function test_los_empleados_de_ejemplo_de_las_demos_con_ver_ventas_lo_traen()
    {
        $this->seed(PermissionSeeder::class);

        (new EmployeeSeeder())->empleados();

        $emails = [
            'miguel@example.com',
            'franco@example.com',
            'santiago@example.com',
        ];

        $empleados = User::whereIn('email', $emails)->get();
        $this->assertCount(3, $empleados);

        foreach ($empleados as $empleado) {
            $slugs = $empleado->permissions()->pluck('slug')->all();

            $this->assertContains('sale.index', $slugs, $empleado->email.' tiene que ver el listado de ventas');
            $this->assertContains(self::SLUG, $slugs, $empleado->email.' tiene que ver ventas de días anteriores');
            $this->assertFalse(
                ListadoVentasHelper::solo_hoy_permitido(User::find($empleado->id)),
                $empleado->email.' no tiene que quedar en solo hoy'
            );
        }

        $patricio = User::where('email', 'patricio@example.com')->first();
        $this->assertNotContains(self::SLUG, $patricio->permissions()->pluck('slug')->all(), 'Patricio no tiene sale.index: no se le regala');
    }

    /**
     * Crea una fila de `permission_empresas` (a propósito puede repetir slug, como en las bases viejas).
     *
     * @param  string  $slug
     * @return \App\Models\PermissionEmpresa
     */
    protected function permiso($slug)
    {
        return PermissionEmpresa::forceCreate([
            'slug'          => $slug,
            'name'          => 'Viejo '.$slug.' '.uniqid(),
            'model_name'    => 'viejo',
        ]);
    }

    /**
     * Crea un empleado del dueño del fixture con esos ids de permiso.
     *
     * @param  array  $permission_ids
     * @param  array  $extra  Campos del usuario que pisan los de siempre (por ejemplo `admin_access`).
     * @return \App\Models\User
     */
    protected function empleado($permission_ids, $extra = [])
    {
        $empleado = User::create(array_merge([
            'name'         => 'Empleado días anteriores',
            'company_name' => 'Ferreteria días anteriores',
            'email'        => 'dias-'.uniqid().'@test.local',
            'password'     => Hash::make('x'),
            'owner_id'     => $this->owner->id,
        ], $extra));

        if (count($permission_ids)) {
            $empleado->permissions()->sync($permission_ids);
        }

        return $empleado;
    }

    /**
     * Cuántas filas del pivot tiene el empleado hacia esos ids de `sale.index.previus_days`.
     *
     * @param  \App\Models\User  $empleado
     * @param  array  $ids_dias
     * @return int
     */
    protected function filas_de_dias($empleado, $ids_dias)
    {
        return DB::table('permission_empresa_user')
                    ->where('user_id', $empleado->id)
                    ->whereIn('permission_empresa_id', $ids_dias)
                    ->count();
    }

    /**
     * Foto del pivot completo, para comparar antes y después.
     *
     * @return array
     */
    protected function pivots()
    {
        return DB::table('permission_empresa_user')
                    ->orderBy('id')
                    ->get(['id', 'permission_empresa_id', 'user_id'])
                    ->map(function ($fila) {
                        return (array) $fila;
                    })
                    ->all();
    }
}
