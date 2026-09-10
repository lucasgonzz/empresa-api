<?php

namespace Tests\Feature;

use App\Console\Kernel;
use App\Http\Controllers\Helpers\inventoryPerformance\InventoryPerformanceHelper;
use App\Jobs\ProcessInventoryPerformanceJob;
use App\Models\InventoryPerformance;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\EmpresaTestCase;

/**
 * Misión optimizacion-vps-fase1 (10/9/2026, release 4.0.24) — el reporte de inventario se genera
 * de noche y a pedido, no en cada entrada al sistema.
 *
 * Lo que protege este archivo, en orden:
 *   1. GET inventory-performance ya NO encola por edad: un reporte de dos horas (que antes, con
 *      `duracion_reporte_inventario` en 30 minutos, disparaba una regeneración) no encola nada.
 *      Encola sólo sin ningún reporte o con uno de más de 7 días, y nunca si ya hay una en curso.
 *   2. POST inventory-performance/generate (el botón Actualizar) encola una vez y deja el candado;
 *      el segundo POST no encola otra y responde lo mismo.
 *   3. inventario:generar elige a quién: el dueño de la instancia (app.USER_ID), el de --user_id
 *      (aunque sea un empleado), o todos los dueños con actividad reciente si no hay ninguno.
 *   4. El Kernel agenda inventario:generar a las 04:00 y set_company_performances el día 1 a las
 *      06:30 sólo con USER_ID; check_stocks no se agenda.
 *
 * Los despachos se capturan con Queue::fake(); el candado es la llave de cache que comparten el
 * controller y el comando (CACHE_DRIVER=array en testing: vive en el proceso, cada test arranca
 * con una app nueva y por lo tanto con la cache vacía).
 */
class InventarioNocturnoTest extends EmpresaTestCase
{
    /** @var User El dueño del fixture, que es el usuario logueado por EmpresaTestCase. */
    protected $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        // Cada caso arranca sin reportes ni candado del dueño y siembra lo que necesita.
        InventoryPerformance::where('user_id', $this->owner->id)->delete();
        Cache::forget(InventoryPerformanceHelper::llave_generando($this->owner->id));
    }

    // ---------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------

    /**
     * Siembra un reporte del dueño con el created_at pedido. Se pasa explícito en el create():
     * Eloquent respeta un created_at que viene seteado (sólo lo pisa si no está dirty).
     *
     * @param  \Carbon\Carbon  $created_at
     * @param  int|null        $user_id  Otro comercio; null = el dueño del fixture.
     * @return InventoryPerformance
     */
    protected function reporte(Carbon $created_at, $user_id = null)
    {
        return InventoryPerformance::create([
            'cantidad_articulos' => 10,
            'stockeados'         => 10,
            'sin_stockear'       => 0,
            'stock_minimo'       => 2,
            'sin_stock'          => 0,
            'user_id'            => is_null($user_id) ? $this->owner->id : $user_id,
            'created_at'         => $created_at,
            'updated_at'         => $created_at,
        ]);
    }

    /**
     * Otro comercio (dueño, owner_id null), para probar que un job no se le encola a quien no toca.
     *
     * @param  string  $slug
     * @return User
     */
    protected function otro_dueno($slug)
    {
        return User::forceCreate([
            'name'         => 'Comercio ' . $slug,
            'company_name' => 'Ferreteria ' . $slug,
            'email'        => 'inventario-' . $slug . '-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);
    }

    /**
     * Un empleado de la cuenta de $dueno.
     *
     * @param  User    $dueno
     * @param  string  $slug
     * @return User
     */
    protected function empleado_de(User $dueno, $slug)
    {
        return User::forceCreate([
            'name'         => 'Empleado ' . $slug,
            'company_name' => $dueno->company_name,
            'email'        => 'empleado-' . $slug . '-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $dueno->id,
        ]);
    }

    /**
     * Escribe last_activity directo en la tabla (el modelo lo pisaría con sus casts al guardar).
     *
     * @param  User               $user
     * @param  \Carbon\Carbon|null $cuando
     * @return void
     */
    protected function ultima_actividad(User $user, $cuando)
    {
        DB::table('users')->where('id', $user->id)->update(['last_activity' => $cuando]);
    }

    /**
     * ¿Está tomado el candado de generación del comercio?
     *
     * @param  int  $user_id
     * @return bool
     */
    protected function candado($user_id)
    {
        return Cache::has(InventoryPerformanceHelper::llave_generando($user_id));
    }

    /**
     * Corre el schedule() del Kernel contra un Schedule limpio y devuelve sus eventos. Por
     * reflexión, porque schedule() es protected (mismo mecanismo que Infraestructura/5).
     *
     * @return \Illuminate\Console\Scheduling\Event[]
     */
    protected function eventos_programados()
    {
        $schedule = new Schedule();

        $kernel = $this->app->make(Kernel::class);
        $metodo = new \ReflectionMethod($kernel, 'schedule');
        $metodo->setAccessible(true);
        $metodo->invoke($kernel, $schedule);

        return $schedule->events();
    }

    /**
     * El evento del schedule cuyo comando contiene el fragmento, o null si no está.
     *
     * @param  string  $fragmento
     * @return \Illuminate\Console\Scheduling\Event|null
     */
    protected function evento($fragmento)
    {
        foreach ($this->eventos_programados() as $evento) {
            if (strpos((string) $evento->command, $fragmento) !== false) {
                return $evento;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------------------------------
    // 1. GET inventory-performance
    // ---------------------------------------------------------------------------------------

    /**
     * El cambio de comportamiento central: un reporte de dos horas ya no dispara nada al entrar,
     * aunque el comercio tenga duracion_reporte_inventario en 1 minuto (la columna dejó de leerse).
     *
     * @return void
     */
    public function test_un_reporte_fresco_no_encola_al_entrar_aunque_la_duracion_vieja_diga_que_si()
    {
        DB::table('users')->where('id', $this->owner->id)->update(['duracion_reporte_inventario' => 1]);

        $reporte = $this->reporte(Carbon::now()->subHours(2));

        Queue::fake();

        $response = $this->getJson('api/inventory-performance');

        $response->assertStatus(200);
        Queue::assertNotPushed(ProcessInventoryPerformanceJob::class);

        $this->assertFalse($response->json('generating'), 'Sin generación en curso, generating tiene que ser false.');
        $this->assertEquals($reporte->id, $response->json('models.0.id'), 'Se devuelve el último reporte tal cual.');
        $this->assertFalse($this->candado($this->owner->id));
    }

    /**
     * Sin ningún reporte del comercio, entrar sí encola uno (es el primer ingreso de una cuenta
     * nueva) y la respuesta lo dice con generating: true.
     *
     * @return void
     */
    public function test_sin_ningun_reporte_encola_al_entrar()
    {
        Queue::fake();

        $response = $this->getJson('api/inventory-performance');

        $response->assertStatus(200);
        Queue::assertPushed(ProcessInventoryPerformanceJob::class, 1);

        $this->assertTrue($response->json('generating'));
        $this->assertNull($response->json('models.0'));
        $this->assertTrue($this->candado($this->owner->id), 'Encolar deja el candado tomado.');
    }

    /**
     * La red de seguridad son 7 días: seis no encola, ocho sí.
     *
     * @return void
     */
    public function test_un_reporte_de_seis_dias_no_encola_y_uno_de_ocho_si()
    {
        $this->reporte(Carbon::now()->subDays(6));

        Queue::fake();
        $this->getJson('api/inventory-performance')->assertStatus(200);
        Queue::assertNotPushed(ProcessInventoryPerformanceJob::class);

        InventoryPerformance::where('user_id', $this->owner->id)->delete();
        $this->reporte(Carbon::now()->subDays(8));

        Queue::fake();
        $response = $this->getJson('api/inventory-performance');

        $response->assertStatus(200);
        Queue::assertPushed(ProcessInventoryPerformanceJob::class, 1);
        $this->assertTrue($response->json('generating'));
    }

    /**
     * Con una generación ya en curso (el candado tomado por el comando nocturno, por otro admin o
     * por el botón), entrar no encola otra, pero la respuesta avisa que se está generando.
     *
     * @return void
     */
    public function test_entrar_con_una_generacion_en_curso_no_encola_otra()
    {
        Cache::add(InventoryPerformanceHelper::llave_generando($this->owner->id), true, 60);

        Queue::fake();

        $response = $this->getJson('api/inventory-performance');

        $response->assertStatus(200);
        Queue::assertNotPushed(ProcessInventoryPerformanceJob::class);
        $this->assertTrue($response->json('generating'));
    }

    // ---------------------------------------------------------------------------------------
    // 2. POST inventory-performance/generate
    // ---------------------------------------------------------------------------------------

    /**
     * El botón Actualizar encola una vez y deja el candado; apretarlo de nuevo no encola otra y
     * responde lo mismo.
     *
     * @return void
     */
    public function test_el_boton_actualizar_encola_una_sola_vez_y_deja_el_candado()
    {
        $this->reporte(Carbon::now()->subHours(2));

        Queue::fake();

        $primero = $this->postJson('api/inventory-performance/generate');

        $primero->assertStatus(200);
        $this->assertTrue($primero->json('generating'));
        Queue::assertPushed(ProcessInventoryPerformanceJob::class, 1);
        $this->assertTrue($this->candado($this->owner->id));

        $segundo = $this->postJson('api/inventory-performance/generate');

        $segundo->assertStatus(200);
        $this->assertTrue($segundo->json('generating'), 'El segundo POST responde lo mismo: idempotente.');
        Queue::assertPushed(ProcessInventoryPerformanceJob::class, 1);

        // Y el GET que sigue ve la generación en curso sin encolar una tercera.
        $this->getJson('api/inventory-performance')->assertStatus(200)->assertJson(['generating' => true]);
        Queue::assertPushed(ProcessInventoryPerformanceJob::class, 1);
    }

    // ---------------------------------------------------------------------------------------
    // 3. inventario:generar
    // ---------------------------------------------------------------------------------------

    /**
     * Con app.USER_ID (toda instancia de cliente) se encola un job para ese comercio y ninguno
     * para otro, por más actividad que tenga el otro.
     *
     * @return void
     */
    public function test_el_comando_con_user_id_de_la_instancia_encola_solo_a_ese_comercio()
    {
        $otro = $this->otro_dueno('vecino');
        $this->ultima_actividad($otro, Carbon::now()->subHours(1));

        config(['app.USER_ID' => $this->owner->id]);

        Queue::fake();

        $exit = Artisan::call('inventario:generar');

        $this->assertSame(0, $exit);
        Queue::assertPushed(ProcessInventoryPerformanceJob::class, 1);
        $this->assertTrue($this->candado($this->owner->id));
        $this->assertFalse($this->candado($otro->id), 'Al otro comercio no le toca.');
        $this->assertStringContainsString('1 generación(es) encolada(s), 0 ya en curso', Artisan::output());

        // Segunda corrida con el candado tomado: no encola otra y lo dice.
        Artisan::call('inventario:generar');

        Queue::assertPushed(ProcessInventoryPerformanceJob::class, 1);
        $this->assertStringContainsString('0 generación(es) encolada(s), 1 ya en curso', Artisan::output());
    }

    /**
     * --user_id manda sobre app.USER_ID, y si es un empleado se genera el reporte de su dueño (el
     * reporte es por cuenta: articles.user_id es siempre el dueño).
     *
     * @return void
     */
    public function test_la_opcion_user_id_manda_y_un_empleado_resuelve_a_su_dueno()
    {
        $otro     = $this->otro_dueno('con-empleado');
        $empleado = $this->empleado_de($otro, 'cajero');

        config(['app.USER_ID' => $this->owner->id]);

        Queue::fake();

        $exit = Artisan::call('inventario:generar', ['--user_id' => $empleado->id]);

        $this->assertSame(0, $exit);
        Queue::assertPushed(ProcessInventoryPerformanceJob::class, 1);
        $this->assertTrue($this->candado($otro->id), 'El empleado resuelve al dueño de su cuenta.');
        $this->assertFalse($this->candado($empleado->id), 'Nunca un job a nombre del empleado.');
        $this->assertFalse($this->candado($this->owner->id), 'Con --user_id, app.USER_ID no cuenta.');
    }

    /**
     * Un --user_id que no existe avisa, sale con 1 y no encola nada.
     *
     * @return void
     */
    public function test_un_user_id_inexistente_avisa_y_no_encola()
    {
        Queue::fake();

        $exit = Artisan::call('inventario:generar', ['--user_id' => 999999]);

        $this->assertSame(1, $exit);
        Queue::assertNotPushed(ProcessInventoryPerformanceJob::class);
        $this->assertStringContainsString('no existe el usuario 999999', Artisan::output());
    }

    /**
     * Sin app.USER_ID ni --user_id (dev/testing, bases con varios comercios): sólo los dueños con
     * actividad propia o de un empleado en los últimos 30 días. Un comercio dormido no recibe job.
     *
     * @return void
     */
    public function test_sin_user_id_encola_solo_a_los_duenos_con_actividad_reciente()
    {
        config(['app.USER_ID' => null]);

        $dormido = $this->otro_dueno('dormido');
        $this->ultima_actividad($dormido, null);
        $this->ultima_actividad($this->owner, Carbon::now()->subDays(40));

        Queue::fake();
        Artisan::call('inventario:generar');

        Queue::assertNotPushed(ProcessInventoryPerformanceJob::class);
        $this->assertStringContainsString('0 generación(es) encolada(s)', Artisan::output());

        // El dueño del fixture vuelve a entrar: le toca a él y sólo a él.
        $this->ultima_actividad($this->owner, Carbon::now()->subDays(2));

        Queue::fake();
        Artisan::call('inventario:generar');

        Queue::assertPushed(ProcessInventoryPerformanceJob::class, 1);
        $this->assertTrue($this->candado($this->owner->id));
        $this->assertFalse($this->candado($dormido->id));

        // La actividad de un empleado también cuenta para su dueño, aunque el dueño no entre.
        Cache::forget(InventoryPerformanceHelper::llave_generando($this->owner->id));
        $empleado = $this->empleado_de($dormido, 'nocturno');
        $this->ultima_actividad($empleado, Carbon::now()->subDays(1));

        Queue::fake();
        Artisan::call('inventario:generar');

        Queue::assertPushed(ProcessInventoryPerformanceJob::class, 2);
        $this->assertTrue($this->candado($dormido->id), 'Un empleado activo despierta el reporte de su cuenta.');
        $this->assertFalse($this->candado($empleado->id));
    }

    // ---------------------------------------------------------------------------------------
    // 4. El Kernel
    // ---------------------------------------------------------------------------------------

    /**
     * inventario:generar todos los días a las 04:00 con withoutOverlapping(120), sin gate por
     * extensión (es de todos los comercios).
     *
     * @return void
     */
    public function test_el_kernel_agenda_el_reporte_de_inventario_a_las_cuatro()
    {
        config(['app.USER_ID' => $this->owner->id]);

        $evento = $this->evento('inventario:generar');

        $this->assertNotNull($evento, 'El schedule no programa inventario:generar.');
        $this->assertSame('0 4 * * *', $evento->expression);
        $this->assertTrue($evento->withoutOverlapping);
        $this->assertSame(120, $evento->expiresAt);
        $this->assertTrue($evento->filtersPass($this->app), 'No lleva ->when(): corre una vez por día, siempre.');
    }

    /**
     * set_company_performances el día 1 a las 06:30, y sólo con app.USER_ID: sin él, el comando
     * caería a su lista hardcodeada de ids.
     *
     * @return void
     */
    public function test_el_kernel_agenda_el_cierre_mensual_solo_con_user_id()
    {
        config(['app.USER_ID' => $this->owner->id]);

        $evento = $this->evento('set_company_performances');

        $this->assertNotNull($evento, 'El schedule no programa set_company_performances.');
        $this->assertSame('30 6 1 * *', $evento->expression);
        $this->assertTrue($evento->withoutOverlapping);
        $this->assertSame(120, $evento->expiresAt);
        $this->assertTrue($evento->filtersPass($this->app), 'Con USER_ID el día 1 corre.');

        config(['app.USER_ID' => null]);

        $sin_user_id = $this->evento('set_company_performances');

        $this->assertNotNull($sin_user_id, 'El evento se registra igual; lo que cambia es el filtro.');
        $this->assertFalse($sin_user_id->filtersPass($this->app), 'Sin USER_ID el filtro lo frena.');
    }

    /**
     * check_stocks recorre el catálogo entero con ->get() y le manda un mail a Lucas: no es una
     * función del cliente y no se agenda.
     *
     * @return void
     */
    public function test_check_stocks_no_se_agenda()
    {
        config(['app.USER_ID' => $this->owner->id]);

        $this->assertNull($this->evento('check_stocks'));
    }
}
