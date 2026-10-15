<?php

namespace Tests\Feature\SugerenciasStock;

use App\Http\Controllers\Helpers\DepositMovementHelper;
use App\Models\Address;
use App\Models\Article;
use App\Models\DepositMovement;
use App\Models\PermissionEmpresa;
use App\Models\StockSuggestion;
use App\Models\StockSuggestionArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Misión permiso-crear-movimiento-deposito (14/10/2026) — el segundo camino que crea movimientos.
 *
 * "Crear Movimiento/s de Deposito" de una sugerencia de stock
 * (`StockSuggestionController::create_deposit_movement`) crea `DepositMovement` igual que el alta
 * del Listado, así que Lucas decidió que lo cierra el MISMO permiso: `deposit_movement.store`. Si
 * no lo pidiera, quien no puede crear movimientos desde el Listado los crearía igual desde acá.
 *
 * Lo que estos tests cuidan:
 *  - empleado sin el permiso → 403 `{"message": ...}` (sin clave `errors`), con el mismo mensaje
 *    que el alta del Listado, y no queda ningún movimiento;
 *  - el 403 va ANTES de leer la sugerencia (con un id inexistente también es 403, no 404);
 *  - empleado con el permiso → 201, y el movimiento queda con su `employee_id` (para que lo vea en
 *    su listado) y del comercio del dueño;
 *  - el dueño y el empleado con `admin_access` crean sin tener el slug, igual que hoy.
 *
 * Mismo armado que `Endpoint_paginado_Test::create_deposit_movement_deja_el_employee_id`.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Permiso_crear_movimiento_desde_sugerencias_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User Dueño del comercio de la prueba. */
    protected $comercio;

    /** @var Address */
    protected $origen;

    /** @var Address */
    protected $destino;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        // Sin credencial de Anthropic nada sale a la API aunque algún job se despache.
        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'     => 'Comercio permiso crear movimiento',
            'email'    => 'sugerencias-permiso-crear-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->origen = Address::create([
            'street'  => 'Deposito origen permiso crear',
            'user_id' => $this->comercio->id,
        ]);

        $this->destino = Address::create([
            'street'  => 'Sucursal destino permiso crear',
            'user_id' => $this->comercio->id,
        ]);

        $this->actuar_como($this->comercio);
    }

    /**
     * Cambia el usuario autenticado para las requests que siguen.
     *
     * 🔴 El `Auth::forgetGuards()` no es decorativo: las rutas viven bajo `auth:sanctum`, cuyo
     * guard cachea el usuario que resolvió la primera vez. Sin olvidarlo, el segundo `actingAs()`
     * no cambia nada.
     *
     * @param User $user
     * @return void
     */
    protected function actuar_como($user)
    {
        Auth::forgetGuards();

        $this->actingAs($user, 'web');
    }

    /**
     * Un empleado del comercio de la prueba con exactamente estos permisos.
     *
     * @param array $slugs
     * @param int $admin_access  1 para un empleado con acceso de administrador.
     * @return User
     */
    protected function crear_empleado($slugs = [], $admin_access = 0)
    {
        $empleado = User::create([
            'name'         => 'Empleado permiso crear movimiento',
            'email'        => 'empleado-permiso-crear-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $this->comercio->id,
            'admin_access' => $admin_access,
        ]);

        $ids = [];
        foreach ($slugs as $slug) {
            $permiso = PermissionEmpresa::where('slug', $slug)->first();

            if (is_null($permiso)) {
                // `forceCreate`: el modelo no declara `$fillable`.
                $permiso = PermissionEmpresa::forceCreate([
                    'slug'       => $slug,
                    'name'       => $slug,
                    'model_name' => 'Stock y depósitos',
                ]);
            }

            $ids[] = $permiso->id;
        }

        if (count($ids) > 0) {
            $empleado->permissions()->attach($ids);
        }

        return $empleado->fresh();
    }

    /**
     * Sugerencia terminada del comercio, con una línea lista para convertir en movimiento.
     *
     * @return array  [StockSuggestion, StockSuggestionArticle]
     */
    protected function sugerencia_con_una_linea()
    {
        $suggestion = StockSuggestion::create([
            'modo'          => 'minimo',
            'origen'        => 'absoluto',
            'limite_origen' => 'minimo',
            'status'        => 'terminado',
            'user_id'       => $this->comercio->id,
        ]);

        $article = Article::create([
            'name'    => 'Articulo linea permiso crear',
            'user_id' => $this->comercio->id,
        ]);

        $linea = StockSuggestionArticle::create([
            'stock_suggestion_id' => $suggestion->id,
            'article_id'          => $article->id,
            'from_address_id'     => $this->origen->id,
            'to_address_id'       => $this->destino->id,
            'suggested_amount'    => 5,
        ]);

        return [$suggestion, $linea];
    }

    /**
     * @param StockSuggestion $suggestion
     * @param StockSuggestionArticle $linea
     * @return \Illuminate\Testing\TestResponse
     */
    protected function crear_movimientos($suggestion, $linea)
    {
        return $this->postJson(
            'api/stock-suggestion/' . $suggestion->id . '/create-deposit-movement',
            ['stock_suggestion_article_ids' => [$linea->id]]
        );
    }

    /**
     * Sin `deposit_movement.store`: 403 con el mismo mensaje del alta del Listado y ni un
     * movimiento creado, aunque el empleado tenga los permisos de EDITAR y el de ver artículos.
     *
     * @group sugerencias-stock
     * @test
     */
    public function un_empleado_sin_el_permiso_recibe_403_y_no_se_crea_nada()
    {
        list($suggestion, $linea) = $this->sugerencia_con_una_linea();

        $empleado = $this->crear_empleado([
            'article.index',
            'deposit_movement.update',
            'deposit_movement.update_articles',
        ]);
        $this->actuar_como($empleado);

        $total_antes = DepositMovement::count();

        $respuesta = $this->crear_movimientos($suggestion, $linea);

        $respuesta->assertStatus(403);
        $this->assertSame(DepositMovementHelper::MENSAJE_SIN_PERMISO_PARA_CREAR, $respuesta->json('message'));
        $this->assertSame('No tenés permiso para crear movimientos de depósito.', $respuesta->json('message'));
        $this->assertNull($respuesta->json('errors'), 'El 403 no lleva clave `errors`: la SPA la mostraría como errores de formulario.');

        $this->assertEquals(0, DepositMovement::where('stock_suggestion_id', $suggestion->id)->count());
        $this->assertEquals($total_antes, DepositMovement::count(), 'Un 403 no puede dejar ningún movimiento.');
        $this->assertEquals(0, DB::table('article_deposit_movement')->whereIn('deposit_movement_id', function ($query) use ($suggestion) {
            $query->select('id')->from('deposit_movements')->where('stock_suggestion_id', $suggestion->id);
        })->count());

        // La sugerencia y su línea quedan como estaban.
        $this->assertNotNull(StockSuggestionArticle::find($linea->id));
    }

    /**
     * El chequeo va ANTES de leer la sugerencia: un empleado sin permiso recibe 403 también con un
     * id que no existe (no el 404), o sea que no se entera de nada más que de que no puede.
     *
     * @group sugerencias-stock
     * @test
     */
    public function el_403_va_antes_de_buscar_la_sugerencia()
    {
        $empleado = $this->crear_empleado(['article.index']);
        $this->actuar_como($empleado);

        $this->postJson('api/stock-suggestion/99999999/create-deposit-movement', [
            'stock_suggestion_article_ids' => [1],
        ])->assertStatus(403);

        // Con el permiso, ese mismo id llega a la búsqueda y da el 404 de siempre.
        $con_permiso = $this->crear_empleado(['article.index', 'deposit_movement.store']);
        $this->actuar_como($con_permiso);

        $this->postJson('api/stock-suggestion/99999999/create-deposit-movement', [
            'stock_suggestion_article_ids' => [1],
        ])->assertStatus(404);
    }

    /**
     * Con `deposit_movement.store`: 201 y el movimiento queda con el `employee_id` de quien lo
     * generó (sin eso no lo vería en su listado) y en el comercio del dueño.
     *
     * @group sugerencias-stock
     * @test
     */
    public function un_empleado_con_el_permiso_crea_y_el_movimiento_queda_con_su_employee_id()
    {
        list($suggestion, $linea) = $this->sugerencia_con_una_linea();

        $empleado = $this->crear_empleado(['article.index', 'deposit_movement.store']);
        $this->actuar_como($empleado);

        $respuesta = $this->crear_movimientos($suggestion, $linea);

        $respuesta->assertStatus(201);

        $movimiento = DepositMovement::where('stock_suggestion_id', $suggestion->id)->first();

        $this->assertNotNull($movimiento);
        $this->assertEquals($empleado->id, (int) $movimiento->employee_id, 'El movimiento tiene que quedar a nombre del empleado que lo generó.');
        $this->assertEquals($this->comercio->id, (int) $movimiento->user_id, 'El movimiento es del comercio del dueño, no del empleado.');
        $this->assertCount(1, $respuesta->json('deposit_movements'));
    }

    /**
     * El dueño y el empleado con `admin_access` crean sin tener el slug tildado: no cambia nada
     * respecto de hoy para ellos.
     *
     * @group sugerencias-stock
     * @test
     */
    public function el_duenio_y_el_empleado_con_admin_access_crean_sin_el_permiso()
    {
        // El dueño (el usuario con el que arrancó el test).
        list($suggestion, $linea) = $this->sugerencia_con_una_linea();

        $this->crear_movimientos($suggestion, $linea)->assertStatus(201);

        $this->assertEquals(1, DepositMovement::where('stock_suggestion_id', $suggestion->id)->count());

        // Un empleado con acceso de administrador y sin ningún permiso tildado.
        $admin = $this->crear_empleado([], 1);
        $this->assertEquals(0, $admin->permissions()->count());
        $this->actuar_como($admin);

        list($otra_sugerencia, $otra_linea) = $this->sugerencia_con_una_linea();

        $this->crear_movimientos($otra_sugerencia, $otra_linea)->assertStatus(201);

        $movimiento = DepositMovement::where('stock_suggestion_id', $otra_sugerencia->id)->first();

        $this->assertNotNull($movimiento);
        $this->assertEquals($admin->id, (int) $movimiento->employee_id);
    }
}
