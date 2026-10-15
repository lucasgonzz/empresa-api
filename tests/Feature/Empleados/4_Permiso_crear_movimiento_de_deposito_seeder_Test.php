<?php

namespace Tests\Feature\Empleados;

use App\Http\Controllers\Helpers\PermisosCatalogoHelper;
use App\Models\PermissionEmpresa;
use App\Models\User;
use Database\Seeders\EmployeeSeeder;
use Database\Seeders\PermissionCrearMovimientoDepositoSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión permiso-crear-movimiento-deposito (14/10/2026) — `deposit_movement.store` ("Crear
 * movimientos de depósito") pasa de "Sin efecto por ahora" a "Stock y depósitos" y empieza a
 * chequearse en la API (`DepositMovementController::store` y las sugerencias de stock).
 *
 * Para que los empleados que hoy crean movimientos no lo pierdan con el release, el seeder suelto
 * `PermissionCrearMovimientoDepositoSeeder` se lo da a todo el que tenga `article.index` (decisión de
 * Lucas: es lo que piden el Listado y Alertas → Movimientos desde la 4.3.9, o sea el que hoy ve los
 * movimientos). El dueño se lo saca después a quien no corresponda.
 *
 * Lo que estos tests cuidan:
 *  - que las bases nuevas lo reciban por el catálogo, con su nombre y su grupo, justo antes de
 *    `deposit_movement.update`;
 *  - que el seeder suelto se lo dé exactamente a quien corresponde, sin duplicar nada si corre dos
 *    veces o si el catálogo ya lo había creado;
 *  - que a una base vieja (la fila ya existe, en "Sin efecto por ahora") le ponga el nombre y el
 *    grupo nuevos SIN cambiar el id (los pivots cuelgan de él);
 *  - las bases viejas con slugs repetidos (los pivots cuelgan de cualquiera de los ids) y los
 *    pivots huérfanos de usuarios que ya no existen;
 *  - que los empleados de ejemplo de las demos lo traigan (la demo no corre el seeder suelto).
 *
 * @group empleados
 */
class Permiso_crear_movimiento_de_deposito_seeder_Test extends EmpresaTestCase
{
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
     * `DemoSetupHelper`) deja el permiso en "Stock y depósitos", con el nombre nuevo y justo antes de
     * `deposit_movement.update`; y ya no queda en "Sin efecto por ahora".
     *
     * @return void
     */
    public function test_una_base_nueva_recibe_el_permiso_en_stock_y_depositos_por_el_catalogo()
    {
        $this->seed(PermissionSeeder::class);

        $permisos = PermissionEmpresa::where('slug', 'deposit_movement.store')->get();

        $this->assertCount(1, $permisos);
        $this->assertEquals('Crear movimientos de depósito (origen, destino y artículos)', $permisos->first()->name);
        $this->assertEquals('Stock y depósitos', $permisos->first()->model_name);
        $this->assertNotEquals(PermisosCatalogoHelper::GRUPO_SIN_EFECTO, $permisos->first()->model_name);

        // En el catálogo está justo antes de "Editar movimientos de depósito".
        $slugs = array_keys(PermisosCatalogoHelper::filas());
        $this->assertEquals(
            array_search('deposit_movement.store', $slugs) + 1,
            array_search('deposit_movement.update', $slugs)
        );
    }

    /**
     * El endpoint que arma la pantalla de Empleados lo manda en el grupo nuevo, ordenado antes de
     * `update`, y con palabras clave para que el buscador lo encuentre por "traslado".
     *
     * @return void
     */
    public function test_el_endpoint_lo_devuelve_en_su_grupo_y_con_palabras_clave()
    {
        $this->seed(PermissionSeeder::class);

        $response = $this->getJson('api/permission');
        $response->assertStatus(200);

        $por_slug = collect($response->json('models'))->keyBy('slug');

        $this->assertArrayHasKey('deposit_movement.store', $por_slug->all());
        $this->assertEquals('Stock y depósitos', $por_slug['deposit_movement.store']['model_name']);
        $this->assertStringContainsString('traslado', $por_slug['deposit_movement.store']['palabras_clave']);

        $slugs = collect($response->json('models'))->pluck('slug')->values()->all();
        $this->assertEquals(
            array_search('deposit_movement.store', $slugs) + 1,
            array_search('deposit_movement.update', $slugs)
        );
    }

    /**
     * Base existente sin la fila: el seeder suelto la crea con el nombre y el grupo del catálogo
     * (no con unos propios que se desalineen).
     *
     * @return void
     */
    public function test_el_seeder_suelto_crea_el_permiso_si_la_base_no_lo_tiene()
    {
        $this->seed(PermissionCrearMovimientoDepositoSeeder::class);

        $permisos = PermissionEmpresa::where('slug', 'deposit_movement.store')->get();
        $fila = PermisosCatalogoHelper::filas()['deposit_movement.store'];

        $this->assertCount(1, $permisos);
        $this->assertEquals($fila['nombre'], $permisos->first()->name);
        $this->assertEquals($fila['grupo'], $permisos->first()->model_name);
        $this->assertEquals('Stock y depósitos', $permisos->first()->model_name);
    }

    /**
     * El criterio de Lucas: lo hereda quien tiene `article.index`. El que ya lo tenía no recibe otra
     * fila. El que tiene otros permisos de movimientos pero NO `article.index` (aunque sea
     * `deposit_movement.update`) o ninguno, no recibe nada.
     *
     * @return void
     */
    public function test_se_lo_da_solo_a_quien_tiene_ver_articulos()
    {
        $article_index = $this->permiso('article.index');
        $article_store = $this->permiso('article.store');
        $movimiento_update = $this->permiso('deposit_movement.update');
        $movimiento_store = $this->permiso('deposit_movement.store');

        $con_ver_articulos = $this->empleado([$article_index->id, $article_store->id]);
        $ya_lo_tenia = $this->empleado([$article_index->id, $movimiento_store->id]);
        $solo_edita_movimientos = $this->empleado([$movimiento_update->id]);
        $con_otros = $this->empleado([$article_store->id]);
        $sin_permisos = $this->empleado([]);

        $resultado = (new PermissionCrearMovimientoDepositoSeeder())->aplicar();

        // La fila ya existía con el nombre y el grupo del catálogo viejo de este test ("Viejo ..."):
        // se alinea, no se crea otra.
        $this->assertEquals(0, $resultado['permisos_creados']);
        $this->assertEquals(1, $resultado['permisos_actualizados']);
        $this->assertEquals(1, $resultado['empleados'], 'Solo el que tiene ver artículos y todavía no lo tenía');

        $this->assertEquals(1, $this->filas_de_store($con_ver_articulos, [$movimiento_store->id]));
        $this->assertEquals(1, $this->filas_de_store($ya_lo_tenia, [$movimiento_store->id]), 'El que ya lo tenía no recibe otra fila');
        $this->assertEquals(0, $this->filas_de_store($solo_edita_movimientos, [$movimiento_store->id]), 'Editar movimientos no es ver artículos');
        $this->assertEquals(0, $this->filas_de_store($con_otros, [$movimiento_store->id]));
        $this->assertEquals(0, $this->filas_de_store($sin_permisos, [$movimiento_store->id]));

        // Lo que ya tenían queda como estaba.
        $this->assertEquals(
            collect([$article_index->id, $article_store->id, $movimiento_store->id])->sort()->values()->all(),
            $con_ver_articulos->permissions()->pluck('permission_empresas.id')->sort()->values()->all()
        );
        $this->assertEquals([$movimiento_update->id], $solo_edita_movimientos->permissions()->pluck('permission_empresas.id')->all());

        // El pivot lleva sus fechas, como el resto de las filas.
        $pivot = DB::table('permission_empresa_user')
                    ->where('user_id', $con_ver_articulos->id)
                    ->where('permission_empresa_id', $movimiento_store->id)
                    ->first();
        $this->assertNotNull($pivot->created_at);
        $this->assertNotNull($pivot->updated_at);
    }

    /**
     * La base de producción de siempre: la fila `deposit_movement.store` YA existe, en "Sin efecto
     * por ahora" y con el nombre viejo. El seeder le pone el nombre y el grupo del catálogo SIN
     * cambiar el id (los pivots de los empleados cuelgan de él) y sin crear otra fila.
     *
     * @return void
     */
    public function test_una_fila_en_sin_efecto_se_alinea_con_el_catalogo_sin_cambiar_el_id()
    {
        $vieja = PermissionEmpresa::forceCreate([
            'slug'       => 'deposit_movement.store',
            'name'       => 'Crear movimientos de depósito',
            'model_name' => PermisosCatalogoHelper::GRUPO_SIN_EFECTO,
        ]);
        $article_index = $this->permiso('article.index');

        $empleado = $this->empleado([$article_index->id]);

        $resultado = (new PermissionCrearMovimientoDepositoSeeder())->aplicar();

        $this->assertEquals(0, $resultado['permisos_creados']);
        $this->assertEquals(1, $resultado['permisos_actualizados']);
        $this->assertEquals(1, $resultado['empleados']);

        $this->assertEquals(1, PermissionEmpresa::where('slug', 'deposit_movement.store')->count());

        $alineada = PermissionEmpresa::find($vieja->id);
        $this->assertNotNull($alineada, 'La fila no se borra ni se reinserta: el id es el mismo.');
        $this->assertEquals('Crear movimientos de depósito (origen, destino y artículos)', $alineada->name);
        $this->assertEquals('Stock y depósitos', $alineada->model_name);

        // Y el empleado quedó colgado de ese mismo id.
        $this->assertEquals(1, $this->filas_de_store($empleado, [$vieja->id]));
    }

    /**
     * Correrlo dos veces no cambia nada: ni otra fila del permiso ni otra fila del pivot, ni cuenta
     * filas "actualizadas" de más.
     *
     * @return void
     */
    public function test_correrlo_dos_veces_no_duplica_nada()
    {
        $article_index = $this->permiso('article.index');

        $this->empleado([$article_index->id]);
        $this->empleado([$article_index->id]);

        $this->seed(PermissionCrearMovimientoDepositoSeeder::class);

        $pivots_antes = $this->pivots();
        $this->assertCount(4, $pivots_antes, '2 filas de article.index + 2 de deposit_movement.store');

        $this->seed(PermissionCrearMovimientoDepositoSeeder::class);
        $resultado = (new PermissionCrearMovimientoDepositoSeeder())->aplicar();

        $this->assertEquals(0, $resultado['permisos_creados']);
        $this->assertEquals(0, $resultado['permisos_actualizados']);
        $this->assertEquals(0, $resultado['empleados']);
        $this->assertEquals(1, PermissionEmpresa::where('slug', 'deposit_movement.store')->count());
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

        $article_index = PermissionEmpresa::where('slug', 'article.index')->first();
        $movimiento_store = PermissionEmpresa::where('slug', 'deposit_movement.store')->first();

        $empleado = $this->empleado([$article_index->id]);

        $resultado = (new PermissionCrearMovimientoDepositoSeeder())->aplicar();

        $this->assertEquals(0, $resultado['permisos_creados']);
        $this->assertEquals(0, $resultado['permisos_actualizados'], 'El catálogo ya lo había dejado con su nombre y su grupo');
        $this->assertEquals(1, $resultado['empleados']);
        $this->assertEquals(1, PermissionEmpresa::where('slug', 'deposit_movement.store')->count());
        $this->assertEquals(1, $this->filas_de_store($empleado, [$movimiento_store->id]));
    }

    /**
     * Base vieja con slugs repetidos (seeders viejos que usaban `create()`): los pivots pueden
     * colgar de cualquiera de los ids. Todo se resuelve por slug; las dos filas de
     * `deposit_movement.store` quedan con el nombre y el grupo del catálogo y el pivot nuevo cuelga
     * del id más bajo.
     *
     * @return void
     */
    public function test_base_vieja_con_slugs_repetidos()
    {
        $article_index_1 = $this->permiso('article.index');
        $article_index_2 = $this->permiso('article.index');
        $store_1 = $this->permiso('deposit_movement.store');
        $store_2 = $this->permiso('deposit_movement.store');

        // Colgado SOLO del segundo id de `article.index`: igual tiene que recibirlo.
        $del_segundo_id = $this->empleado([$article_index_2->id]);

        // Ya lo tiene, colgado del id duplicado de `deposit_movement.store`: no recibe otra fila.
        $ya_lo_tiene = $this->empleado([$article_index_1->id, $store_2->id]);

        $resultado = (new PermissionCrearMovimientoDepositoSeeder())->aplicar();

        $this->assertEquals(0, $resultado['permisos_creados'], 'Con el slug ya presente (aunque repetido) no se crea otra fila');
        $this->assertEquals(2, $resultado['permisos_actualizados'], 'Se alinean las dos filas repetidas');
        $this->assertEquals(1, $resultado['empleados']);
        $this->assertEquals(2, PermissionEmpresa::where('slug', 'deposit_movement.store')->count());

        // Las dos quedan con el nombre y el grupo del catálogo.
        $fila = PermisosCatalogoHelper::filas()['deposit_movement.store'];
        foreach ([$store_1->id, $store_2->id] as $id) {
            $this->assertEquals($fila['nombre'], PermissionEmpresa::find($id)->name);
            $this->assertEquals($fila['grupo'], PermissionEmpresa::find($id)->model_name);
        }

        $ids_store = [$store_1->id, $store_2->id];

        $this->assertEquals(1, $this->filas_de_store($del_segundo_id, $ids_store));
        // Se cuelga del id más bajo de `deposit_movement.store`.
        $this->assertEquals(1, $this->filas_de_store($del_segundo_id, [min($ids_store)]));

        $this->assertEquals(1, $this->filas_de_store($ya_lo_tiene, $ids_store));
        $this->assertEquals(1, $this->filas_de_store($ya_lo_tiene, [$store_2->id]), 'Conserva la fila que ya tenía');
    }

    /**
     * Un pivot de `article.index` que cuelga de un usuario que ya no existe (huérfano) no recibe
     * fila nueva ni cuenta como empleado.
     *
     * @return void
     */
    public function test_no_toca_a_los_usuarios_huerfanos()
    {
        $article_index = $this->permiso('article.index');
        $movimiento_store = $this->permiso('deposit_movement.store');

        $existente = $this->empleado([$article_index->id]);

        // `user_id` de un usuario que no existe.
        $user_id_huerfano = (int) User::max('id') + 100000;
        DB::table('permission_empresa_user')->insert([
            'permission_empresa_id' => $article_index->id,
            'user_id'               => $user_id_huerfano,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        $resultado = (new PermissionCrearMovimientoDepositoSeeder())->aplicar();

        $this->assertEquals(1, $resultado['empleados'], 'Solo el que existe');
        $this->assertEquals(1, $this->filas_de_store($existente, [$movimiento_store->id]));
        $this->assertEquals(
            0,
            DB::table('permission_empresa_user')
                ->where('user_id', $user_id_huerfano)
                ->where('permission_empresa_id', $movimiento_store->id)
                ->count(),
            'Al huérfano no se le suma ninguna fila'
        );
    }

    /**
     * Los cuatro empleados de ejemplo de las demos (`EmployeeSeeder`, que corre `DemoSetupHelper`) lo
     * traen: la demo no corre el seeder suelto, y sin esto una demo nueva no podría crear
     * movimientos con ninguno de ellos.
     *
     * @return void
     */
    public function test_los_empleados_de_ejemplo_de_las_demos_lo_traen()
    {
        $this->seed(PermissionSeeder::class);

        (new EmployeeSeeder())->empleados();

        $emails = [
            'patricio@example.com',
            'miguel@example.com',
            'franco@example.com',
            'santiago@example.com',
        ];

        $empleados = User::whereIn('email', $emails)->get();
        $this->assertCount(4, $empleados);

        foreach ($empleados as $empleado) {
            $slugs = $empleado->permissions()->pluck('slug')->all();

            $this->assertContains('article.index', $slugs, $empleado->email.' tiene que ver el listado de artículos');
            $this->assertContains('deposit_movement.store', $slugs, $empleado->email.' tiene que poder crear movimientos de depósito');
        }
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
     * @return \App\Models\User
     */
    protected function empleado($permission_ids)
    {
        $empleado = User::create([
            'name'         => 'Empleado crear movimiento',
            'company_name' => 'Ferreteria crear movimiento',
            'email'        => 'crear-mov-'.uniqid().'@test.local',
            'password'     => Hash::make('x'),
            'owner_id'     => $this->owner->id,
        ]);

        if (count($permission_ids)) {
            $empleado->permissions()->sync($permission_ids);
        }

        return $empleado;
    }

    /**
     * Cuántas filas del pivot tiene el empleado hacia esos ids de `deposit_movement.store`.
     *
     * @param  \App\Models\User  $empleado
     * @param  array  $ids_store
     * @return int
     */
    protected function filas_de_store($empleado, $ids_store)
    {
        return DB::table('permission_empresa_user')
                    ->where('user_id', $empleado->id)
                    ->whereIn('permission_empresa_id', $ids_store)
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
