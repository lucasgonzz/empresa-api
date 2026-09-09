<?php

namespace Tests\Feature\Infraestructura;

use App\Console\Kernel;
use App\Http\Controllers\Helpers\DemoTrackingConfigHelper;
use App\Models\DemoTrackingConfig;
use App\Models\ExportHistory;
use App\Models\ExtencionEmpresa;
use App\Models\ImportHistory;
use App\Models\SyncToMeliArticle;
use App\Models\SyncToTNArticle;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\EmpresaTestCase;

/**
 * Misión actualizar-sin-el-vps (9/9/2026) — los gates del schedule.
 *
 * Regla del Kernel: CERO procesos nuevos cuando no hay nada que hacer, decidido adentro de
 * schedule:run con `->when()`, y CERO cambio de comportamiento cuando sí hay trabajo. Cada
 * test de acá ejercita un `->when()` real del Kernel a través de `Event::filtersPass()`, que es
 * exactamente lo que schedule:run evalúa antes de arrancar un artisan: con la base vacía el
 * filtro tiene que dar false, y con una fila de trabajo tiene que dar true.
 *
 * Y el interruptor nuevo del worker de cola (`queue.scheduler_worker`), que se suma al de VPS
 * que ya protege `1_Worker_de_cola_segun_hosting_Test.php`.
 *
 * Extiende `EmpresaTestCase` por las transacciones: las filas que se siembran para prender un
 * gate se revierten solas.
 */
class Gates_del_schedule_Test extends EmpresaTestCase
{
    /** @var \App\Models\User El dueño del fixture, que es el que el Kernel resuelve por app.USER_ID. */
    protected $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        // El Kernel resuelve el dueño de la instancia por esta config, no por el usuario logueado.
        config(['app.USER_ID' => $this->owner->id]);
    }

    /**
     * Corre el schedule() del Kernel contra un Schedule limpio y devuelve sus eventos.
     * Por reflexión, porque schedule() es protected (mismo mecanismo que el test 1).
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
     * El evento del schedule cuyo comando contiene el fragmento, o falla el test si no está.
     *
     * @param  string  $fragmento
     * @return \Illuminate\Console\Scheduling\Event
     */
    protected function evento($fragmento)
    {
        foreach ($this->eventos_programados() as $evento) {
            if (strpos((string) $evento->command, $fragmento) !== false) {
                return $evento;
            }
        }

        $this->fail('El schedule no programa "' . $fragmento . '".');
    }

    /**
     * ¿Hay algún evento cuyo comando contenga el fragmento?
     *
     * @param  string  $fragmento
     * @return bool
     */
    protected function programa($fragmento)
    {
        foreach ($this->eventos_programados() as $evento) {
            if (strpos((string) $evento->command, $fragmento) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lo que schedule:run decide para un evento que está en hora: ¿lo arranca?
     *
     * @param  \Illuminate\Console\Scheduling\Event  $evento
     * @return bool
     */
    protected function arrancaria(Event $evento)
    {
        return $evento->filtersPass($this->app);
    }

    /**
     * Le da al dueño del fixture la extensión pedida (creándola en el catálogo si no está).
     *
     * @param  string  $slug
     * @return void
     */
    protected function dar_extension($slug)
    {
        $extencion = ExtencionEmpresa::where('slug', $slug)->first();

        if (! $extencion) {
            $extencion = ExtencionEmpresa::forceCreate([
                'name' => $slug,
                'slug' => $slug,
            ]);
        }

        $this->owner->extencions()->syncWithoutDetaching([$extencion->id]);
    }

    // ---------------------------------------------------------------------------------------
    // queue:work: el interruptor nuevo
    // ---------------------------------------------------------------------------------------

    /**
     * Con QUEUE_SCHEDULER_WORKER=false el scheduler no programa el worker, aunque la instancia
     * no sea VPS. Es lo que van a tener las instancias del VPS que ya tienen supervisor.
     *
     * @return void
     */
    public function test_con_scheduler_worker_en_false_no_se_programa_el_worker_de_cola()
    {
        config(['app.VPS' => false, 'queue.scheduler_worker' => false]);

        $this->assertFalse($this->programa('queue:work'));
    }

    /**
     * Con el default (true) y fuera del VPS, el worker se programa como siempre: en el shared es
     * lo único que procesa la cola.
     *
     * @return void
     */
    public function test_con_scheduler_worker_en_true_el_shared_sigue_programando_el_worker()
    {
        config(['app.VPS' => false, 'queue.scheduler_worker' => true]);

        $this->assertTrue($this->programa('queue:work'));
    }

    /**
     * El interruptor nuevo solo puede afectar al worker de cola: el resto del schedule tiene que
     * ser idéntico con la variable en true y en false.
     *
     * @return void
     */
    public function test_el_interruptor_del_worker_no_toca_ningun_otro_comando()
    {
        config(['app.VPS' => false, 'queue.scheduler_worker' => true]);
        $con_worker = array_map(function ($evento) {
            return (string) $evento->command;
        }, $this->eventos_programados());

        config(['queue.scheduler_worker' => false]);
        $sin_worker = array_map(function ($evento) {
            return (string) $evento->command;
        }, $this->eventos_programados());

        $solo_con = array_values(array_diff($con_worker, $sin_worker));

        $this->assertCount(1, $solo_con, 'Solo puede diferir el worker de cola: ' . implode(' | ', $solo_con));
        $this->assertStringContainsString('queue:work', $solo_con[0]);
        $this->assertEmpty(array_diff($sin_worker, $con_worker));
    }

    // ---------------------------------------------------------------------------------------
    // demo:flush-eventos
    // ---------------------------------------------------------------------------------------

    /**
     * Sin canal de demo configurado (los ~40 clientes reales) schedule:run no arranca el comando.
     *
     * @return void
     */
    public function test_demo_flush_eventos_no_arranca_sin_canal_de_demo()
    {
        DemoTrackingConfig::query()->delete();
        DemoTrackingConfigHelper::olvidar_cache();

        $this->assertFalse($this->arrancaria($this->evento('demo:flush-eventos')));
    }

    /**
     * Con canal configurado (demo, demo2, demo3) el comando corre cada minuto como siempre.
     *
     * @return void
     */
    public function test_demo_flush_eventos_arranca_con_canal_de_demo()
    {
        DemoTrackingConfig::query()->delete();
        DemoTrackingConfigHelper::olvidar_cache();

        $config = DemoTrackingConfigHelper::guardar('token-gates-del-schedule', 'https://admin.test/api/demo-eventos');
        DemoTrackingConfigHelper::olvidar_cache();

        $this->assertNotNull($config, 'Precondición: el canal tiene que haberse guardado.');

        $this->assertTrue($this->arrancaria($this->evento('demo:flush-eventos')));
    }

    // ---------------------------------------------------------------------------------------
    // sync_articles_to_tienda_nube / sync_to_meli_articles
    // ---------------------------------------------------------------------------------------

    /**
     * Con la extensión de Tienda Nube pero sin ninguna sincronización pendiente del dueño, no se
     * arranca nada; con una fila en 'pendiente', sí. Una fila en otro estado no cuenta.
     *
     * @return void
     */
    public function test_sync_tienda_nube_arranca_solo_con_pendientes_del_dueno()
    {
        $this->dar_extension('usa_tienda_nube');

        SyncToTNArticle::where('user_id', $this->owner->id)->delete();

        $evento = $this->evento('sync_articles_to_tienda_nube');

        $this->assertFalse($this->arrancaria($evento), 'Sin pendientes no hay nada que sincronizar.');

        SyncToTNArticle::create([
            'article_id' => 1,
            'user_id'    => $this->owner->id,
            'status'     => 'exitosa',
        ]);

        $this->assertFalse($this->arrancaria($evento), 'Una sincronización ya hecha no es trabajo.');

        SyncToTNArticle::create([
            'article_id' => 1,
            'user_id'    => $this->owner->id,
            'status'     => 'pendiente',
        ]);

        $this->assertTrue($this->arrancaria($evento), 'Con una pendiente el comando tiene que correr.');
    }

    /**
     * Mismo contrato para Mercado Libre.
     *
     * @return void
     */
    public function test_sync_mercado_libre_arranca_solo_con_pendientes_del_dueno()
    {
        $this->dar_extension('usa_mercado_libre');

        SyncToMeliArticle::where('user_id', $this->owner->id)->delete();

        $evento = $this->evento('sync_to_meli_articles');

        $this->assertFalse($this->arrancaria($evento), 'Sin pendientes no hay nada que sincronizar.');

        SyncToMeliArticle::create([
            'article_id' => 1,
            'user_id'    => $this->owner->id,
            'status'     => 'pendiente',
        ]);

        $this->assertTrue($this->arrancaria($evento), 'Con una pendiente el comando tiene que correr.');
    }

    // ---------------------------------------------------------------------------------------
    // imports:detectar-colgadas / historiales:detectar-colgados
    // ---------------------------------------------------------------------------------------

    /**
     * El watchdog de importaciones solo arranca si hay alguna importación en estado activo.
     *
     * @return void
     */
    public function test_detectar_importaciones_colgadas_arranca_solo_con_importaciones_activas()
    {
        ImportHistory::whereIn('status', ['en_preparacion', 'en_proceso'])->delete();

        $evento = $this->evento('imports:detectar-colgadas');

        $this->assertFalse($this->arrancaria($evento), 'Sin importaciones activas no hay nada que pueda colgarse.');

        ImportHistory::create([
            'user_id'    => $this->owner->id,
            'model_name' => 'Article',
            'status'     => 'terminado',
        ]);

        $this->assertFalse($this->arrancaria($evento), 'Una importación terminada no cuenta.');

        ImportHistory::create([
            'user_id'    => $this->owner->id,
            'model_name' => 'Article',
            'status'     => 'en_proceso',
        ]);

        $this->assertTrue($this->arrancaria($evento), 'Con una en curso el watchdog tiene que correr.');
    }

    /**
     * El watchdog de historiales solo arranca si hay alguna exportación en 'pending' o 'processing'.
     *
     * @return void
     */
    public function test_detectar_historiales_colgados_arranca_solo_con_exportaciones_activas()
    {
        ExportHistory::whereIn('status', ['pending', 'processing'])->delete();

        $evento = $this->evento('historiales:detectar-colgados');

        $this->assertFalse($this->arrancaria($evento), 'Sin exportaciones activas no hay nada que pueda colgarse.');

        ExportHistory::create([
            'user_id'    => $this->owner->id,
            'model_name' => 'Article',
            'status'     => 'processing',
        ]);

        $this->assertTrue($this->arrancaria($evento), 'Con una en curso el watchdog tiene que correr.');
    }

    // ---------------------------------------------------------------------------------------
    // El paracaídas
    // ---------------------------------------------------------------------------------------

    /**
     * Si el gate no se puede evaluar (la tabla no existe todavía, la base no responde), el
     * comando se corre igual: el gate es un ahorro, no una guarda, y `filtersPass()` no atrapa
     * nada — una excepción ahí cortaría el schedule:run de ese minuto para todos los comandos
     * que vienen después.
     *
     * @return void
     */
    public function test_un_gate_que_tira_deja_correr_el_comando()
    {
        $kernel = $this->app->make(Kernel::class);
        $metodo = new \ReflectionMethod($kernel, 'gate_de_datos');
        $metodo->setAccessible(true);

        $resultado = $metodo->invoke($kernel, function () {
            throw new \RuntimeException('Base table or view not found: la tabla todavía no existe');
        });

        $this->assertTrue($resultado, 'Un gate que tira tiene que dejar correr el comando, como antes de que existiera el gate.');

        $this->assertFalse($metodo->invoke($kernel, function () {
            return false;
        }));

        $this->assertTrue($metodo->invoke($kernel, function () {
            return true;
        }));
    }
}
