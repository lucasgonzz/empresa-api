<?php

namespace Tests\Feature\Empleados;

use App\Http\Controllers\Helpers\PermisosCatalogoHelper;
use App\Models\PermissionEmpresa;
use App\Models\User;
use Database\Seeders\PermissionComprobantesIndexSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión permisos-navegacion-empleados (9/10/2026) — el permiso nuevo `comprobantes.index`.
 *
 * La pantalla Comprobantes (notas de crédito y pagos de clientes, con importes) no pedía ningún
 * permiso: cualquier empleado la veía. Lucas decidió darle uno propio en el grupo Ventas, y que los
 * empleados que hoy la usan no la pierdan con el release: el seeder suelto
 * `PermissionComprobantesIndexSeeder` se lo da a quien tenga `sale.index` o `client.index`, y también
 * a quien tenga `devolucion.store` (lo marcó el chequeo independiente: en Comprobantes está el botón
 * para reintentar con ARCA una nota de crédito guardada sin CAE, misión del 7/10/2026).
 *
 * Lo que estos tests cuidan:
 *  - que las bases nuevas lo reciban por el catálogo, con su nombre y su grupo;
 *  - que el seeder suelto se lo dé exactamente a quien corresponde, sin duplicar nada si corre dos
 *    veces o si el catálogo ya lo había creado;
 *  - las bases viejas con slugs repetidos (los pivots cuelgan de cualquiera de los ids);
 *  - que el buscador de Empleados lo encuentre por "nota de credito".
 *
 * @group empleados
 */
class Permiso_ver_comprobantes_Test extends EmpresaTestCase
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
     * `DemoSetupHelper`) deja el permiso con el nombre y el grupo que decidió Lucas.
     *
     * @return void
     */
    public function test_una_base_nueva_recibe_el_permiso_por_el_catalogo()
    {
        $this->seed(PermissionSeeder::class);

        $permisos = PermissionEmpresa::where('slug', 'comprobantes.index')->get();

        $this->assertCount(1, $permisos);
        $this->assertEquals('Ver Comprobantes (notas de crédito y pagos de clientes)', $permisos->first()->name);
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
        $this->seed(PermissionComprobantesIndexSeeder::class);

        $permisos = PermissionEmpresa::where('slug', 'comprobantes.index')->get();
        $fila = PermisosCatalogoHelper::filas()['comprobantes.index'];

        $this->assertCount(1, $permisos);
        $this->assertEquals($fila['nombre'], $permisos->first()->name);
        $this->assertEquals($fila['grupo'], $permisos->first()->model_name);
        $this->assertEquals('Ventas', $permisos->first()->model_name);
    }

    /**
     * El criterio de Lucas: lo hereda quien tiene `sale.index` o `client.index`. El que tiene los dos
     * recibe una sola fila; el que tiene otros permisos o ninguno no recibe nada. (`devolucion.store`
     * también lo hereda: tiene su propio test, abajo.)
     *
     * @return void
     */
    public function test_se_lo_da_solo_a_quien_tiene_ver_ventas_o_ver_clientes()
    {
        $sale_index = $this->permiso('sale.index');
        $client_index = $this->permiso('client.index');
        $article_index = $this->permiso('article.index');

        $con_ventas = $this->empleado([$sale_index->id]);
        $con_clientes = $this->empleado([$client_index->id]);
        $con_los_dos = $this->empleado([$sale_index->id, $client_index->id, $article_index->id]);
        $con_otros = $this->empleado([$article_index->id]);
        $sin_permisos = $this->empleado([]);

        $resultado = (new PermissionComprobantesIndexSeeder())->aplicar();

        $this->assertEquals(1, $resultado['permisos_creados']);
        $this->assertEquals(3, $resultado['empleados']);

        $comprobantes = PermissionEmpresa::where('slug', 'comprobantes.index')->first();

        $this->assertEquals(1, $this->filas_de_comprobantes($con_ventas, [$comprobantes->id]));
        $this->assertEquals(1, $this->filas_de_comprobantes($con_clientes, [$comprobantes->id]));
        $this->assertEquals(1, $this->filas_de_comprobantes($con_los_dos, [$comprobantes->id]), 'Con los dos permisos tiene que quedar una sola fila');
        $this->assertEquals(0, $this->filas_de_comprobantes($con_otros, [$comprobantes->id]));
        $this->assertEquals(0, $this->filas_de_comprobantes($sin_permisos, [$comprobantes->id]));

        // Lo que ya tenían queda como estaba.
        $this->assertEquals(
            collect([$sale_index->id, $client_index->id, $article_index->id, $comprobantes->id])->sort()->values()->all(),
            $con_los_dos->permissions()->pluck('permission_empresas.id')->sort()->values()->all()
        );
        $this->assertEquals([$article_index->id], $con_otros->permissions()->pluck('permission_empresas.id')->all());

        // El pivot lleva sus fechas, como el resto de las filas.
        $pivot = DB::table('permission_empresa_user')
                    ->where('user_id', $con_ventas->id)
                    ->where('permission_empresa_id', $comprobantes->id)
                    ->first();
        $this->assertNotNull($pivot->created_at);
        $this->assertNotNull($pivot->updated_at);
    }

    /**
     * El que hace devoluciones sin ver ventas ni clientes también lo recibe: en Comprobantes está el
     * botón para reintentar con ARCA una nota de crédito guardada sin CAE (misión del 7/10/2026), y
     * sin el permiso lo perdería. El que no tiene ninguno de los tres sigue sin recibirlo.
     *
     * @return void
     */
    public function test_el_que_solo_hace_devoluciones_tambien_lo_recibe()
    {
        $devolucion_store = $this->permiso('devolucion.store');
        $article_index = $this->permiso('article.index');

        $solo_devoluciones = $this->empleado([$devolucion_store->id]);
        $con_otros = $this->empleado([$article_index->id]);
        $sin_permisos = $this->empleado([]);

        $resultado = (new PermissionComprobantesIndexSeeder())->aplicar();

        $this->assertEquals(1, $resultado['empleados']);

        $comprobantes = PermissionEmpresa::where('slug', 'comprobantes.index')->first();

        $this->assertEquals(1, $this->filas_de_comprobantes($solo_devoluciones, [$comprobantes->id]), 'El que solo hace devoluciones tiene que recibirlo');
        $this->assertEquals(0, $this->filas_de_comprobantes($con_otros, [$comprobantes->id]));
        $this->assertEquals(0, $this->filas_de_comprobantes($sin_permisos, [$comprobantes->id]));
    }

    /**
     * Correrlo dos veces no cambia nada: ni otra fila del permiso ni otra fila del pivot.
     *
     * @return void
     */
    public function test_correrlo_dos_veces_no_duplica_nada()
    {
        $sale_index = $this->permiso('sale.index');
        $client_index = $this->permiso('client.index');

        $this->empleado([$sale_index->id]);
        $this->empleado([$client_index->id]);
        $this->empleado([$sale_index->id, $client_index->id]);

        $this->seed(PermissionComprobantesIndexSeeder::class);

        $pivots_antes = $this->pivots();
        $this->assertCount(7, $pivots_antes, '4 filas de origen + 3 de comprobantes');

        $this->seed(PermissionComprobantesIndexSeeder::class);
        $resultado = (new PermissionComprobantesIndexSeeder())->aplicar();

        $this->assertEquals(0, $resultado['permisos_creados']);
        $this->assertEquals(0, $resultado['empleados']);
        $this->assertEquals(1, PermissionEmpresa::where('slug', 'comprobantes.index')->count());
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
        $comprobantes = PermissionEmpresa::where('slug', 'comprobantes.index')->first();

        $empleado = $this->empleado([$sale_index->id]);

        $resultado = (new PermissionComprobantesIndexSeeder())->aplicar();

        $this->assertEquals(0, $resultado['permisos_creados']);
        $this->assertEquals(1, $resultado['empleados']);
        $this->assertEquals(1, PermissionEmpresa::where('slug', 'comprobantes.index')->count());
        $this->assertEquals(1, $this->filas_de_comprobantes($empleado, [$comprobantes->id]));
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
        $comprobantes_1 = $this->permiso('comprobantes.index');
        $comprobantes_2 = $this->permiso('comprobantes.index');

        // Colgado SOLO del segundo id de `sale.index`: igual tiene que recibirlo.
        $del_segundo_id = $this->empleado([$sale_index_2->id]);

        // Ya lo tiene, colgado del id duplicado de `comprobantes.index`: no recibe otra fila.
        $ya_lo_tiene = $this->empleado([$sale_index_1->id, $comprobantes_2->id]);

        $resultado = (new PermissionComprobantesIndexSeeder())->aplicar();

        $this->assertEquals(0, $resultado['permisos_creados'], 'Con el slug ya presente (aunque repetido) no se crea otra fila');
        $this->assertEquals(1, $resultado['empleados']);
        $this->assertEquals(2, PermissionEmpresa::where('slug', 'comprobantes.index')->count());

        $ids_comprobantes = [$comprobantes_1->id, $comprobantes_2->id];

        $this->assertEquals(1, $this->filas_de_comprobantes($del_segundo_id, $ids_comprobantes));
        // Se cuelga del id más bajo de `comprobantes.index`.
        $this->assertEquals(1, $this->filas_de_comprobantes($del_segundo_id, [min($ids_comprobantes)]));

        $this->assertEquals(1, $this->filas_de_comprobantes($ya_lo_tiene, $ids_comprobantes));
        $this->assertEquals(1, $this->filas_de_comprobantes($ya_lo_tiene, [$comprobantes_2->id]), 'Conserva la fila que ya tenía');
    }

    /**
     * El buscador de la pantalla de Empleados suma las palabras clave al nombre: el dueño que busca
     * "nota de credito" tiene que encontrar este permiso.
     *
     * @return void
     */
    public function test_el_endpoint_lo_devuelve_con_sus_palabras_clave()
    {
        $this->seed(PermissionSeeder::class);

        $response = $this->getJson('api/permission');
        $response->assertStatus(200);

        $por_slug = collect($response->json('models'))->keyBy('slug');

        $this->assertArrayHasKey('comprobantes.index', $por_slug->all());
        $this->assertEquals('Ver Comprobantes (notas de crédito y pagos de clientes)', $por_slug['comprobantes.index']['name']);
        $this->assertEquals('Ventas', $por_slug['comprobantes.index']['model_name']);
        $this->assertStringContainsString('nota de credito', $por_slug['comprobantes.index']['palabras_clave']);
        $this->assertStringContainsString('pagos', $por_slug['comprobantes.index']['palabras_clave']);

        // Va en el grupo Ventas, justo después de "Hacer devoluciones".
        $slugs = collect($response->json('models'))->pluck('slug')->values()->all();
        $this->assertEquals(
            array_search('devolucion.store', $slugs) + 1,
            array_search('comprobantes.index', $slugs)
        );
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
            'name'         => 'Empleado comprobantes',
            'company_name' => 'Ferreteria comprobantes',
            'email'        => 'comp-'.uniqid().'@test.local',
            'password'     => Hash::make('x'),
            'owner_id'     => $this->owner->id,
        ]);

        if (count($permission_ids)) {
            $empleado->permissions()->sync($permission_ids);
        }

        return $empleado;
    }

    /**
     * Cuántas filas del pivot tiene el empleado hacia esos ids de `comprobantes.index`.
     *
     * @param  \App\Models\User  $empleado
     * @param  array  $ids_comprobantes
     * @return int
     */
    protected function filas_de_comprobantes($empleado, $ids_comprobantes)
    {
        return DB::table('permission_empresa_user')
                    ->where('user_id', $empleado->id)
                    ->whereIn('permission_empresa_id', $ids_comprobantes)
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
