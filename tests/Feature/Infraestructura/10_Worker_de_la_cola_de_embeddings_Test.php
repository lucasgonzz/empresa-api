<?php

namespace Tests\Feature\Infraestructura;

use App\Console\Kernel;
use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Observers\ArticleObserver;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\LuaScripts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Misión embeddings-lote-cola (CA12) — el worker de la cola propia `<cola base>_embeddings` lo lanza el SCHEDULE de la propia
 * instancia, en shared Y en VPS, sin tocar el supervisor.
 *
 * Por qué el schedule y no supervisor: en shared el cron ya corre `schedule:run`, y en VPS cada cliente (y el pool de pruebas) ya tiene
 * el programa `_schedule` que corre `schedule:run`. Así el worker llega con el código en el update normal y no hay que editar ~23
 * configuraciones de supervisor a mano. El evento:
 *   - es un `exec` con el prefijo que cierra los descriptores heredados del cron (si no, un worker de 20 minutos retiene el `flock` del
 *     cron en shared y hace saltear el `schedule:run` entero, incluido el worker de `default`);
 *   - corre `queue:work --queue=<cola>_embeddings --stop-when-empty` con `--max-time=1200`, `withoutOverlapping(25)` (un solo worker por
 *     instancia, debajo del SIGKILL de 30 minutos de Hostinger) y `runInBackground`;
 *   - sólo arranca si hay jobs pendientes (`when`): sin eso sería un `artisan` por minuto para no hacer nada;
 *   - se registra si el interruptor está prendido Y el dueño tiene `agentes_ia`, O si quedan pendientes en la cola (vuelta atrás sin
 *     huérfanos).
 *
 * 🔴 Cada "no se programa" lleva su control en positivo: un "sin worker" pasa en verde antes de construir nada, así que sólo vale si el
 * mismo escenario con la condición corregida SÍ lo programa.
 *
 * 🔴 `Queue::fake()` queda activo (como en 9_Worker_del_asistente_en_vps_Test): contar pendientes por la fachada `Queue` devolvería 0
 * aunque haya filas en `jobs`. El conteo tiene que mirar la tabla (o Redis) directamente.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types ni enum.
 *
 * @group infraestructura
 */
class Worker_de_la_cola_de_embeddings_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que habilita la vectorización del catálogo. */
    const SLUG = 'agentes_ia';

    /** Cola propia cuando QUEUE_NAME no está seteada. */
    const COLA_POR_DEFECTO = 'default_embeddings';

    /** Fragmento del comando que identifica al worker de embeddings. */
    const FRAGMENTO_DEL_WORKER = 'queue:work --queue=default_embeddings --stop-when-empty';

    /** @var User Comercio dueño de la instancia de prueba. */
    protected $comercio;

    /**
     * Comercio sin extensión (cada test se la da si la necesita) y `Queue::fake()` activo.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'         => 'Comercio worker embeddings',
            'company_name' => 'Ferreteria worker embeddings',
            'email'        => 'embeddings-worker-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        config(['app.USER_ID' => $this->comercio->id]);

        Queue::fake();
    }

    // ─────────────────────────────── helpers ───────────────────────────────

    /**
     * Le da la extensión `agentes_ia` al comercio y olvida el memo del gate.
     *
     * @return void
     */
    protected function dar_extension()
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (! $extencion) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => self::SLUG, 'name' => 'Agentes IA']);
        }

        $this->comercio->extencions()->attach($extencion->id);
        $this->comercio->load('extencions');

        ArticleObserver::resetear_cache_gate();
    }

    /**
     * Config del shared: `app.VPS=false`, `scheduler_worker=true`, interruptor de la cola propia prendido (su default), conexión
     * `database` con la cola base `default`.
     *
     * @param array $cambios Claves de config que pisan el escenario.
     *
     * @return void
     */
    protected function escenario_shared(array $cambios = [])
    {
        config(array_merge([
            'app.VPS'                          => false,
            'queue.scheduler_worker'           => true,
            'queue.embeddings_worker'          => true,
            'queue.default'                    => 'database',
            'queue.connections.database.queue' => 'default',
            'app.USER_ID'                      => $this->comercio->id,
        ], $cambios));
    }

    /**
     * Config del VPS: `app.VPS=true` y `scheduler_worker=false` (el valor real del VPS: ahí el supervisor consume `default`).
     *
     * @param array $cambios Claves de config que pisan el escenario.
     *
     * @return void
     */
    protected function escenario_vps(array $cambios = [])
    {
        $this->escenario_shared(array_merge([
            'app.VPS'                => true,
            'queue.scheduler_worker' => false,
        ], $cambios));
    }

    /**
     * Corre el `schedule()` del Kernel contra un Schedule limpio y devuelve sus eventos (por reflexión, igual que el test 9).
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
     * Los eventos `queue:work` del schedule que atienden una cola de embeddings (`…_embeddings`).
     *
     * @return \Illuminate\Console\Scheduling\Event[]
     */
    protected function eventos_del_worker_de_embeddings()
    {
        return array_values(array_filter($this->eventos_programados(), function ($evento) {
            $comando = (string) $evento->command;

            return strpos($comando, 'queue:work') !== false && strpos($comando, '_embeddings') !== false;
        }));
    }

    /**
     * El único evento del worker de embeddings; falla con un mensaje claro (y no con un "offset" indefinido) si no hay.
     *
     * @return \Illuminate\Console\Scheduling\Event
     */
    protected function unico_evento_del_worker()
    {
        $eventos = $this->eventos_del_worker_de_embeddings();

        $this->assertCount(1, $eventos, 'Tiene que haber UN evento del worker de embeddings en el schedule.');

        return $eventos[0];
    }

    /**
     * ¿Programa el schedule el worker de embeddings con la config actual?
     *
     * @return bool
     */
    protected function hay_worker_de_embeddings()
    {
        return count($this->eventos_del_worker_de_embeddings()) > 0;
    }

    /**
     * Deja una fila pendiente en la tabla `jobs` de la cola indicada.
     *
     * @param string $cola
     *
     * @return void
     */
    protected function dejar_job_pendiente_en($cola)
    {
        DB::table('jobs')->insert([
            'queue'        => $cola,
            'payload'      => '{"displayName":"huerfano-del-test"}',
            'attempts'     => 0,
            'reserved_at'  => null,
            'available_at' => time(),
            'created_at'   => time(),
        ]);
    }

    /**
     * Doble de la conexión Redis que sólo acepta el `eval` del conteo de `RedisQueue::size` sobre las tres claves de la cola indicada, y
     * se instala en la fachada. Con `atLeast()->once()` el test falla si el código nunca lo llama con esos argumentos exactos.
     *
     * @param string $cola      Nombre completo de la cola (ej. `ferre_embeddings`).
     * @param mixed  $respuesta Número a devolver, o una \Throwable para simular Redis caído.
     *
     * @return void
     */
    protected function doble_de_redis_para($cola, $respuesta)
    {
        $conexion = \Mockery::mock();
        $expectativa = $conexion->shouldReceive('eval')
            ->with(LuaScripts::size(), 3, 'queues:' . $cola, 'queues:' . $cola . ':delayed', 'queues:' . $cola . ':reserved')
            ->atLeast()->once();

        if ($respuesta instanceof \Throwable) {
            $expectativa->andThrow($respuesta);
        } else {
            $expectativa->andReturn($respuesta);
        }

        Redis::shouldReceive('connection')->andReturn($conexion);
    }

    /**
     * Lee `config/queue.php` tal como lo evaluaría Laravel con las variables de entorno dadas, y restaura el entorno al terminar.
     *
     * @param array $entorno Nombre => valor; null borra la variable.
     *
     * @return array La config de cola.
     */
    protected function config_de_cola_con_entorno(array $entorno)
    {
        $anteriores = [];

        foreach ($entorno as $nombre => $valor) {
            $anteriores[$nombre] = getenv($nombre);
            putenv($valor === null ? $nombre : $nombre . '=' . $valor);
        }

        try {
            return require config_path('queue.php');
        } finally {
            foreach ($anteriores as $nombre => $valor) {
                putenv($valor === false ? $nombre : $nombre . '=' . $valor);
            }
        }
    }

    /**
     * Aserta que el evento es el worker de embeddings tal como lo pide el plan.
     *
     * @param \Illuminate\Console\Scheduling\Event $evento
     * @param string                               $contexto
     *
     * @return void
     */
    protected function assert_es_el_worker_de_embeddings($evento, $contexto)
    {
        $comando = (string) $evento->command;

        $this->assertSame(
            0,
            strpos($comando, AsistenteCanalHelper::PREFIJO_CIERRE_DE_CANDADOS_HEREDADOS),
            $contexto . ': el comando empieza con el cierre de los candados heredados del cron.'
        );
        $this->assertStringContainsString(self::FRAGMENTO_DEL_WORKER, $comando, $contexto . ': atiende la cola propia y termina cuando se vacía.');
        $this->assertStringContainsString('--max-time=1200', $comando, $contexto . ': se va solo a los 20 minutos, debajo del SIGKILL de Hostinger.');
        $this->assertTrue($evento->withoutOverlapping, $contexto . ': un solo worker de embeddings por instancia.');
        $this->assertSame(25, $evento->expiresAt, $contexto . ': el mutex vence a los 25 minutos (> max-time).');
        $this->assertTrue($evento->runInBackground, $contexto . ': en segundo plano, para no frenar al resto del schedule.');
    }

    // ─────────────────────────────── config ───────────────────────────────

    /**
     * El interruptor nace PRENDIDO (a diferencia del worker del asistente en VPS): `QUEUE_EMBEDDINGS_WORKER` sin la variable es true; `false`
     * y `0` lo apagan. Y el `.env.example` lo documenta.
     *
     * @test
     */
    public function test_el_interruptor_nace_prendido_en_la_config()
    {
        $this->assertNull(env('QUEUE_EMBEDDINGS_WORKER'), 'Precondición: el entorno de test no declara la opción.');

        $sin_variable = $this->config_de_cola_con_entorno(['QUEUE_EMBEDDINGS_WORKER' => null]);
        $this->assertArrayHasKey('embeddings_worker', $sin_variable, 'config/queue.php tiene que declarar embeddings_worker.');
        $this->assertSame(true, $sin_variable['embeddings_worker'], 'Sin la variable el interruptor está prendido.');

        $this->assertSame(false, $this->config_de_cola_con_entorno(['QUEUE_EMBEDDINGS_WORKER' => 'false'])['embeddings_worker']);
        $this->assertSame(false, $this->config_de_cola_con_entorno(['QUEUE_EMBEDDINGS_WORKER' => '0'])['embeddings_worker']);
        $this->assertSame(true, $this->config_de_cola_con_entorno(['QUEUE_EMBEDDINGS_WORKER' => 'true'])['embeddings_worker']);

        $this->assertStringContainsString(
            'QUEUE_EMBEDDINGS_WORKER',
            file_get_contents(base_path('.env.example')),
            'El .env.example documenta la opción.'
        );
    }

    // ─────────────────────────────── shared y VPS ───────────────────────────────

    /**
     * CA12 (shared). Dueño con `agentes_ia`, shared: hay EXACTAMENTE UN evento del worker de embeddings, con el comando, el mutex y el
     * modo de ejecución del plan; y el worker de `default` (`--stop-when-empty` sobre `default`) sigue como estaba.
     *
     * @test
     */
    public function test_en_shared_el_schedule_lanza_el_worker_de_embeddings()
    {
        $this->dar_extension();
        $this->escenario_shared();

        $eventos = $this->eventos_del_worker_de_embeddings();

        $this->assertCount(1, $eventos, 'Shared con agentes_ia: UN worker de embeddings.');
        $this->assert_es_el_worker_de_embeddings($eventos[0], 'Shared');

        // El worker de `default` del shared no se toca.
        $de_default = array_filter($this->eventos_programados(), function ($evento) {
            return strpos((string) $evento->command, 'queue:work --stop-when-empty') !== false;
        });
        $this->assertCount(1, $de_default, 'El worker de default del shared sigue programado, una sola vez.');
    }

    /**
     * CA12 (VPS). Igual en el VPS con `scheduler_worker=false` (su valor real): UN evento de embeddings, y sigue SIN haber worker de
     * `default` en el schedule (ese lo atiende el supervisor): el único `--stop-when-empty` es el de embeddings.
     *
     * @test
     */
    public function test_en_vps_el_schedule_lanza_el_worker_de_embeddings()
    {
        $this->dar_extension();
        $this->escenario_vps();

        $eventos = $this->eventos_del_worker_de_embeddings();

        $this->assertCount(1, $eventos, 'VPS con agentes_ia: UN worker de embeddings.');
        $this->assert_es_el_worker_de_embeddings($eventos[0], 'VPS');

        $con_stop = array_filter($this->eventos_programados(), function ($evento) {
            return strpos((string) $evento->command, '--stop-when-empty') !== false;
        });
        $this->assertCount(1, $con_stop, 'En el VPS el único worker --stop-when-empty del schedule es el de embeddings.');
    }

    // ─────────────────────────────── cuándo se registra ───────────────────────────────

    /**
     * CA12 (sin extensión / interruptor / vuelta atrás). El evento se registra si el interruptor está prendido Y el dueño tiene
     * `agentes_ia`, O si quedan pendientes en la cola:
     *   - sin extensión y cola vacía: ninguno (control: con la extensión, sí);
     *   - con extensión pero interruptor apagado y cola vacía: ninguno;
     *   - interruptor apagado y un job pendiente en `default_embeddings`: sí (vuelta atrás sin huérfanos);
     *   - sin extensión pero con un job pendiente: sí;
     *   - un pendiente en OTRA cola no cuenta.
     *
     * @test
     */
    public function test_sin_extension_o_con_interruptor_apagado_solo_hay_worker_si_quedan_pendientes()
    {
        $this->escenario_shared();

        // Sin extensión, cola vacía: nada.
        $this->assertFalse($this->hay_worker_de_embeddings(), 'Sin agentes_ia y sin pendientes no hay worker.');

        // Un pendiente en otra cola no cuenta.
        $this->dejar_job_pendiente_en('default');
        $this->assertFalse($this->hay_worker_de_embeddings(), 'Una fila de la cola default no hace correr al worker de embeddings.');

        // Sin extensión pero con un pendiente propio: se registra (hay que vaciar la cola).
        $this->dejar_job_pendiente_en(self::COLA_POR_DEFECTO);
        $this->assertTrue($this->hay_worker_de_embeddings(), 'Con pendientes en la cola propia el worker se registra aunque no haya extensión.');
        DB::table('jobs')->delete();

        // Control en positivo: con la extensión el mismo escenario sí lo programa.
        $this->dar_extension();
        $this->assertTrue($this->hay_worker_de_embeddings(), 'Control: con agentes_ia y el interruptor prendido, hay worker.');

        // Interruptor apagado, cola vacía: nada (aunque haya extensión).
        $this->escenario_shared(['queue.embeddings_worker' => false]);
        $this->assertFalse($this->hay_worker_de_embeddings(), 'Con el interruptor apagado y la cola vacía no hay worker.');

        // Interruptor apagado con un huérfano: el worker se registra para vaciarlo.
        $this->dejar_job_pendiente_en(self::COLA_POR_DEFECTO);
        $this->assertTrue($this->hay_worker_de_embeddings(), 'Vuelta atrás: con un job pendiente en _embeddings el worker se registra aunque el interruptor esté apagado.');
    }

    // ─────────────────────────────── el filtro `when` ───────────────────────────────

    /**
     * CA12 (when, database). El filtro del evento da false sin jobs pendientes en la cola propia (no se arranca un `artisan` por
     * minuto para nada) y true con uno pendiente; una fila de otra cola no cuenta.
     *
     * @test
     */
    public function test_el_filtro_when_mira_los_pendientes_de_la_cola_propia()
    {
        $this->dar_extension();
        $this->escenario_vps();

        $evento = $this->unico_evento_del_worker();
        $this->assertFalse($evento->filtersPass($this->app), 'Sin jobs pendientes el worker no arranca.');

        $this->dejar_job_pendiente_en('default');
        $evento = $this->unico_evento_del_worker();
        $this->assertFalse($evento->filtersPass($this->app), 'Una fila de otra cola no hace arrancar al worker.');

        $this->dejar_job_pendiente_en(self::COLA_POR_DEFECTO);
        $evento = $this->unico_evento_del_worker();
        $this->assertTrue($evento->filtersPass($this->app), 'Con un job pendiente en default_embeddings el worker arranca.');
    }

    /**
     * CA12 (when, redis con doble). Con Redis el conteo es el `eval` del script de `RedisQueue::size` sobre `queues:ferre_embeddings`,
     * `:delayed` y `:reserved`: con 1 job pendiente el filtro da true. (Con 0 pendientes, el test siguiente.)
     *
     * @test
     */
    public function test_el_filtro_when_cuenta_los_pendientes_en_redis()
    {
        $this->dar_extension();
        $this->escenario_vps([
            'queue.default'                 => 'redis',
            'queue.connections.redis.queue' => 'ferre',
        ]);

        // Con pendientes.
        $this->doble_de_redis_para('ferre_embeddings', 1);
        $eventos = $this->eventos_del_worker_de_embeddings();
        $this->assertCount(1, $eventos, 'VPS con Redis: un worker de ferre_embeddings.');
        $this->assertStringContainsString('queue:work --queue=ferre_embeddings --stop-when-empty', (string) $eventos[0]->command);
        $this->assertTrue($eventos[0]->filtersPass($this->app), 'Con 1 job en Redis el filtro da true.');
    }

    /**
     * CA12 (when, redis vacío). El `eval` devuelve 0 → el filtro da false. (El control en positivo es el test anterior: misma config, el
     * doble devuelve 1.)
     *
     * @test
     */
    public function test_el_filtro_when_con_redis_vacio_no_arranca()
    {
        $this->dar_extension();
        $this->escenario_vps([
            'queue.default'                 => 'redis',
            'queue.connections.redis.queue' => 'ferre',
        ]);

        $this->doble_de_redis_para('ferre_embeddings', 0);
        $eventos = $this->eventos_del_worker_de_embeddings();

        $this->assertCount(1, $eventos);
        $this->assertFalse($eventos[0]->filtersPass($this->app), 'Con 0 jobs en Redis el filtro da false.');
    }

    // ─────────────────────────────── QUEUE_NAME ───────────────────────────────

    /**
     * CA12 (QUEUE_NAME). Con la cola base `ferre` el worker atiende `ferre_embeddings` y NO `default_embeddings`: dos instalaciones que
     * comparten base se separan por `QUEUE_NAME`, y cada una atiende sólo la suya. Vale en shared (cola de la conexión `database`) y en
     * VPS (cola de la conexión por defecto).
     *
     * @test
     */
    public function test_con_queue_name_el_worker_atiende_la_cola_derivada()
    {
        $this->dar_extension();

        foreach (['shared' => false, 'vps' => true] as $nombre => $es_vps) {
            $this->escenario_shared([
                'app.VPS'                          => $es_vps,
                'queue.scheduler_worker'           => ! $es_vps,
                'queue.connections.database.queue' => 'ferre',
            ]);

            $eventos = $this->eventos_del_worker_de_embeddings();

            $this->assertCount(1, $eventos, $nombre . ': un worker de embeddings.');
            $this->assertStringContainsString('queue:work --queue=ferre_embeddings --stop-when-empty', (string) $eventos[0]->command, $nombre . ': atiende ferre_embeddings.');
            $this->assertStringNotContainsString('default_embeddings', (string) $eventos[0]->command, $nombre . ': no atiende la cola de otra instalación.');
        }
    }
}
