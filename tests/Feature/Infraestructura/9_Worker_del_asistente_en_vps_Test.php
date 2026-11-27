<?php

namespace Tests\Feature\Infraestructura;

use App\Console\Kernel;
use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use App\Jobs\ProcessArticleExportJob;
use App\Jobs\ResponderMensajeChatIaJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\LuaScripts;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\Feature\AsistenteWhatsapp\AsistenteWhatsappTestCase;

/**
 * Misión asistente-cola-vps (S4 de la fase 2 de colas, CA4.1 a CA4.7): la cola propia del turno
 * del asistente también en el VPS, APAGADA por defecto (`QUEUE_ASISTENTE_WORKER_VPS`).
 *
 * El problema: en el VPS el turno del asistente va a `default`, que consume un solo worker de
 * supervisor con `--timeout=3600` y sin `--queue`. Un export de hasta 1 h lo deja esperando. La
 * solución es la que el shared ya tiene (misión asistente-whatsapp-velocidad, Enmienda 8): el turno
 * va a `<cola>_asistente` y el scheduler del cliente deja un worker por minuto sin mutex y con
 * `--max-time=65`. En el VPS la puerta es SÓLO `queue.asistente_worker_vps` (+ el interruptor
 * `asistente_worker`): `queue.scheduler_worker` vale `false` a propósito en el VPS y NO se mira; y
 * `cache.default` tampoco, porque el evento no tiene mutex (alineación P-QA3 con la Enmienda 8).
 *
 * 🔴 Cada test "no pasa X" lleva su control en positivo: un "no se programa el worker" pasa en
 * verde antes de construir nada, así que sólo vale si el mismo escenario con la condición corregida
 * SÍ lo programa.
 *
 * 🔴 `Queue::fake()` queda activo en todos los tests (como en el test 7): contar pendientes por la
 * fachada `Queue` devolvería 0 aunque haya filas en `jobs`, y rompería
 * `7_Worker_del_turno_de_whatsapp_Test::test_con_jobs_huerfanos_el_worker_igual_corre`.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types ni enum.
 *
 * @group asistente-whatsapp
 */
class Worker_del_asistente_en_vps_Test extends AsistenteWhatsappTestCase
{
    /** Cola que el turno usa cuando QUEUE_NAME no está seteada. */
    const COLA_POR_DEFECTO = 'default_asistente';

    /** Comando del worker del asistente (Enmienda 8): vivo, sleep 1, tope de 65 s, un solo intento. */
    const COMANDO_DEL_WORKER = 'queue:work --queue=default_asistente --sleep=1 --max-time=65 --tries=1';

    protected function setUp(): void
    {
        parent::setUp();

        // Las colas no se procesan: sólo se mira a qué cola se despacha cada job.
        Queue::fake();
    }

    // ---------------------------------------------------------------------------------------
    // Ayudantes
    // ---------------------------------------------------------------------------------------

    /**
     * Deja la config como el VPS real con la opción nueva prendida: `app.VPS=true`,
     * `queue.scheduler_worker=false` (en el VPS vale false a propósito: el supervisor consume
     * `default`), el interruptor del asistente prendido, la conexión por defecto `database` y
     * `cache.default` sin tocar (`phpunit.xml` trae `array`). Cada test pisa lo que necesite.
     *
     * @param  array  $cambios  Claves de config que pisan el escenario.
     * @return void
     */
    protected function escenario_vps(array $cambios = [])
    {
        config(array_merge([
            'app.VPS'                          => true,
            'queue.scheduler_worker'           => false,
            'queue.asistente_worker'           => true,
            'queue.asistente_worker_vps'       => true,
            'queue.default'                    => 'database',
            'queue.connections.database.queue' => 'default',
            'app.USER_ID'                      => $this->comercio->id,
        ], $cambios));
    }

    /**
     * Deja la config en el shared con todo prendido (el caso que ya existe hoy).
     *
     * @return void
     */
    protected function escenario_shared()
    {
        config([
            'app.VPS'                          => false,
            'queue.scheduler_worker'           => true,
            'queue.asistente_worker'           => true,
            'queue.asistente_worker_vps'       => false,
            'queue.default'                    => 'database',
            'queue.connections.database.queue' => 'default',
            'app.USER_ID'                      => $this->comercio->id,
        ]);
    }

    /**
     * Manda un mensaje por el POST real del canal y devuelve la cola del job de respuesta que
     * quedó despachado (null = la cola por defecto).
     *
     * @return string|null
     */
    protected function cola_del_job_despachado_por_whatsapp()
    {
        Queue::fake();

        $this->mandar(['texto' => 'Cuánto vendí hoy'])->assertStatus(202);

        $jobs = Queue::pushed(ResponderMensajeChatIaJob::class);

        $this->assertCount(1, $jobs, 'El POST tiene que despachar exactamente un job de respuesta.');

        return $jobs->first()->queue;
    }

    /**
     * Corre el schedule() del Kernel contra un Schedule limpio y devuelve sus eventos (por
     * reflexión, igual que el test 7).
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
     * Los eventos `queue:work` del schedule que atienden una cola del asistente (`…_asistente`).
     *
     * @return \Illuminate\Console\Scheduling\Event[]
     */
    protected function eventos_del_worker_del_asistente()
    {
        return array_values(array_filter($this->eventos_programados(), function ($evento) {
            $comando = (string) $evento->command;

            return strpos($comando, 'queue:work') !== false && strpos($comando, '_asistente') !== false;
        }));
    }

    /**
     * ¿Programa el schedule el worker del asistente, con la config actual?
     *
     * @return bool
     */
    protected function hay_worker_del_asistente()
    {
        return count($this->eventos_del_worker_del_asistente()) > 0;
    }

    /**
     * Deja una fila pendiente en la tabla `jobs` de la cola indicada: un turno despachado que
     * todavía nadie levantó (la misma inserción que usa el test 7).
     *
     * @param  string  $cola
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
     * Prepara el escenario del paso (a) de la vuelta atrás con Redis: VPS, opción prendida,
     * interruptor del asistente apagado, conexión `redis` con la cola `ferre`. El worker sólo
     * queda programado si hay pendientes en `ferre_asistente`.
     *
     * @return void
     */
    protected function escenario_vps_redis_con_el_interruptor_apagado()
    {
        $this->escenario_vps([
            'queue.default'                 => 'redis',
            'queue.connections.redis.queue' => 'ferre',
            'queue.asistente_worker'        => false,
        ]);
    }

    /**
     * Doble de la conexión Redis que sólo acepta el `eval` del conteo de `RedisQueue::size` sobre
     * las tres claves de `ferre_asistente`, y se instala en la fachada. Con `atLeast()->once()` el
     * test falla si el helper nunca lo llama con esos argumentos exactos.
     *
     * @param  mixed  $respuesta  Número a devolver, o una \Throwable para simular Redis caído.
     * @return void
     */
    protected function doble_de_redis_para_ferre_asistente($respuesta)
    {
        $conexion = \Mockery::mock();
        $expectativa = $conexion->shouldReceive('eval')
            ->with(LuaScripts::size(), 3, 'queues:ferre_asistente', 'queues:ferre_asistente:delayed', 'queues:ferre_asistente:reserved')
            ->atLeast()->once();

        if ($respuesta instanceof \Throwable) {
            $expectativa->andThrow($respuesta);
        } else {
            $expectativa->andReturn($respuesta);
        }

        Redis::shouldReceive('connection')->andReturn($conexion);
    }

    /**
     * Lee `config/queue.php` tal como lo evaluaría Laravel con las variables de entorno dadas, y
     * restaura el entorno al terminar. Sirve para probar el default de la opción nueva y la cola
     * derivada de `REDIS_QUEUE` sin depender del `.env.testing`.
     *
     * @param  array  $entorno  Nombre => valor; null borra la variable.
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

    // ---------------------------------------------------------------------------------------
    // La opción nace apagada
    // ---------------------------------------------------------------------------------------

    /**
     * `config/queue.php` declara `asistente_worker_vps` apagada por defecto; `"false"` y `"0"` la
     * apagan y sólo un valor verdadero la prende (filter_var, como las otras dos opciones). Y el
     * `.env.example` la documenta comentada y en false.
     *
     * @test
     */
    public function test_la_opcion_vps_nace_apagada_en_la_config()
    {
        $this->assertNull(env('QUEUE_ASISTENTE_WORKER_VPS'), 'Precondición: el entorno de test no declara la opción.');

        $sin_variable = $this->config_de_cola_con_entorno(['QUEUE_ASISTENTE_WORKER_VPS' => null]);
        $this->assertArrayHasKey('asistente_worker_vps', $sin_variable, 'config/queue.php tiene que declarar asistente_worker_vps.');
        $this->assertSame(false, $sin_variable['asistente_worker_vps'], 'Sin la variable la opción está apagada.');

        $this->assertSame(false, $this->config_de_cola_con_entorno(['QUEUE_ASISTENTE_WORKER_VPS' => 'false'])['asistente_worker_vps']);
        $this->assertSame(false, $this->config_de_cola_con_entorno(['QUEUE_ASISTENTE_WORKER_VPS' => '0'])['asistente_worker_vps']);
        $this->assertSame(true, $this->config_de_cola_con_entorno(['QUEUE_ASISTENTE_WORKER_VPS' => 'true'])['asistente_worker_vps']);

        $this->assertStringContainsString(
            '# QUEUE_ASISTENTE_WORKER_VPS=false',
            file_get_contents(base_path('.env.example')),
            'El .env.example documenta la opción comentada y en false.'
        );
    }

    // ---------------------------------------------------------------------------------------
    // CA4.1 — apagada = como hoy
    // ---------------------------------------------------------------------------------------

    /**
     * CA4.1. Con `app.VPS=true` y la opción VPS apagada (como está hoy en todo el VPS) el turno va
     * a la cola por defecto y el schedule no lanza ningún worker del asistente.
     *
     * Control en positivo: el shared con todo prendido SÍ usa la cola del asistente y SÍ programa
     * el worker (si no, "queda en null" pasaría en verde antes de construir nada).
     *
     * @test
     */
    public function test_con_la_opcion_vps_apagada_todo_queda_como_hoy()
    {
        $this->dar_extension();

        // Control en positivo (shared).
        $this->escenario_shared();
        $this->assertSame(self::COLA_POR_DEFECTO, $this->cola_del_job_despachado_por_whatsapp(), 'Control: el shared va a la cola del asistente.');
        $this->assertTrue($this->hay_worker_del_asistente(), 'Control: el shared programa el worker del asistente.');

        // VPS con la opción apagada, tanto con scheduler_worker=false (el VPS real) como true.
        foreach ([false, true] as $scheduler_worker) {
            $this->escenario_vps([
                'queue.asistente_worker_vps' => false,
                'queue.scheduler_worker'     => $scheduler_worker,
            ]);

            $this->assertNull(
                $this->cola_del_job_despachado_por_whatsapp(),
                'VPS con la opción apagada (scheduler_worker=' . var_export($scheduler_worker, true) . '): el turno queda en la cola por defecto.'
            );
            $this->assertFalse(
                $this->hay_worker_del_asistente(),
                'VPS con la opción apagada (scheduler_worker=' . var_export($scheduler_worker, true) . '): sin worker del asistente.'
            );
        }
    }

    // ---------------------------------------------------------------------------------------
    // CA4.2 — prendida en VPS con scheduler_worker=false
    // ---------------------------------------------------------------------------------------

    /**
     * CA4.2. VPS con `scheduler_worker=false` (el valor real del VPS), interruptor del asistente
     * prendido, opción prendida y extensión `agentes_ia`: el turno va a `default_asistente` y el
     * schedule tiene UN evento con el comando exacto del worker del asistente, sin mutex y sin el
     * worker `--stop-when-empty` de `default` (ese sigue gobernado por `scheduler_worker`).
     * `cache.default` se deja como lo trae phpunit.xml (`array`): la puerta no mira la caché.
     * Con las dos conexiones que simulan el VPS (`redis` y `database`).
     *
     * @test
     */
    public function test_prendida_en_vps_con_scheduler_worker_apagado_el_turno_va_a_la_cola_del_asistente()
    {
        $this->dar_extension();

        $this->assertSame('array', config('cache.default'), 'Precondición: phpunit.xml deja la caché en array (la puerta no la mira).');

        foreach (['redis', 'database'] as $conexion) {
            $this->escenario_vps(['queue.default' => $conexion]);

            $this->assertSame(
                self::COLA_POR_DEFECTO,
                $this->cola_del_job_despachado_por_whatsapp(),
                'VPS (' . $conexion . ') con la opción prendida: el turno va a default_asistente.'
            );

            $eventos = $this->eventos_del_worker_del_asistente();
            $this->assertCount(1, $eventos, 'VPS (' . $conexion . '): tiene que haber exactamente UN worker del asistente.');

            $evento = $eventos[0];
            $this->assertSame(
                AsistenteCanalHelper::comando_del_worker_del_turno_whatsapp(self::COLA_POR_DEFECTO),
                (string) $evento->command,
                'VPS (' . $conexion . '): el comando es exactamente el del helper para default_asistente.'
            );
            $this->assertStringContainsString(self::COMANDO_DEL_WORKER, (string) $evento->command);
            $this->assertFalse($evento->withoutOverlapping, 'Sin withoutOverlapping (Enmienda 8).');
            $this->assertTrue($evento->runInBackground);

            foreach ($this->eventos_programados() as $programado) {
                $comando_programado = (string) $programado->command;

                // Misión embeddings-lote-cola (CA12): en VPS con `agentes_ia` el schedule programa además el
                // worker de la cola propia de embeddings (`queue:work --queue=<cola>_embeddings
                // --stop-when-empty`), que es legítimo y lo exige el test 10_. La prohibición sigue en pie
                // para cualquier OTRO evento `--stop-when-empty` (en particular el worker de `default`).
                if (strpos($comando_programado, 'queue:work') !== false && strpos($comando_programado, '_embeddings') !== false) {
                    continue;
                }

                $this->assertStringNotContainsString(
                    '--stop-when-empty',
                    $comando_programado,
                    'Con scheduler_worker=false el worker de default (--stop-when-empty) sigue sin programarse.'
                );
            }
        }
    }

    // ---------------------------------------------------------------------------------------
    // CA4.3 — los pesados no entran
    // ---------------------------------------------------------------------------------------

    /**
     * CA4.3. Con la opción prendida el export pesado sigue en la cola por defecto del VPS
     * (`queue` null), nunca en `_asistente`. Control: en el shared va a `excel`.
     *
     * @test
     */
    public function test_los_jobs_pesados_no_entran_en_la_cola_del_asistente()
    {
        $this->escenario_shared();
        $en_shared = new ProcessArticleExportJob($this->comercio->id, $this->comercio->id, [], 1);
        $this->assertSame('excel', $en_shared->queue, 'Control: en shared el export va a la cola excel.');

        $this->escenario_vps();
        $en_vps = new ProcessArticleExportJob($this->comercio->id, $this->comercio->id, [], 1);
        $this->assertNull($en_vps->queue, 'En VPS el export sigue en la cola por defecto.');
        $this->assertStringNotContainsString('_asistente', (string) $en_vps->queue);
    }

    // ---------------------------------------------------------------------------------------
    // CA4.4 — vuelta atrás en dos pasos, sin huérfanos
    // ---------------------------------------------------------------------------------------

    /**
     * CA4.4 (a), (b) y (c), con `database`, `scheduler_worker=false` y `Queue::fake()` activo.
     *
     *  (a) opción prendida, interruptor apagado, 1 pendiente en `default_asistente`: el turno nuevo
     *      va a la cola por defecto (null) y el schedule SÍ lanza el worker que drena el huérfano.
     *      El fake está vacío: prueba que el conteo no pasa por `Queue::size`.
     *  (b) lo mismo con 0 pendientes: sin worker.
     *  (c) opción quitada con 1 pendiente: sin worker (lo que fija `7_…:535-536`; por eso el orden
     *      de la vuelta atrás es obligatorio: primero el interruptor, después la opción).
     *
     * @test
     */
    public function test_vuelta_atras_en_dos_pasos_con_database()
    {
        $this->dar_extension();

        // (b) Sin pendientes: sin worker.
        $this->escenario_vps(['queue.asistente_worker' => false]);
        $this->assertNull(AsistenteCanalHelper::cola_del_turno_whatsapp(), '(b) interruptor apagado: el turno nuevo va a default.');
        $this->assertFalse($this->hay_worker_del_asistente(), '(b) Sin pendientes no hay worker.');

        // (a) Con un pendiente en default_asistente: el worker lo drena.
        $this->dejar_job_pendiente_en(self::COLA_POR_DEFECTO);
        Queue::assertNothingPushed();
        $this->assertNull(AsistenteCanalHelper::cola_del_turno_whatsapp(), '(a) el turno nuevo va a la cola por defecto.');
        $eventos = $this->eventos_del_worker_del_asistente();
        $this->assertCount(1, $eventos, '(a) Con un huérfano en default_asistente el worker tiene que programarse.');
        $this->assertSame(
            AsistenteCanalHelper::comando_del_worker_del_turno_whatsapp(self::COLA_POR_DEFECTO),
            (string) $eventos[0]->command
        );

        // Una fila de OTRA cola no cuenta.
        DB::table('jobs')->delete();
        $this->dejar_job_pendiente_en('default');
        $this->assertFalse($this->hay_worker_del_asistente(), '(a) Una fila de la cola default no hace correr al worker del asistente.');

        // (c) Opción quitada con el huérfano: sin worker (paso 2 hecho antes que el 1).
        $this->dejar_job_pendiente_en(self::COLA_POR_DEFECTO);
        $this->escenario_vps([
            'queue.asistente_worker'     => false,
            'queue.asistente_worker_vps' => false,
        ]);
        $this->assertFalse($this->hay_worker_del_asistente(), '(c) Con la opción VPS quitada no se programa worker, ni para huérfanos.');

        // Control en positivo: con la opción prendida el mismo huérfano SÍ levanta el worker.
        $this->escenario_vps(['queue.asistente_worker' => false]);
        $this->assertTrue($this->hay_worker_del_asistente(), 'Control: opción prendida + huérfano = worker.');
    }

    /**
     * CA4.4 (d) — Redis, con pendientes: el conteo es el `eval` del script de `RedisQueue::size`
     * sobre `queues:ferre_asistente`, `:delayed` y `:reserved`. Devuelve 1 → worker de
     * `ferre_asistente`, y el helper no avisa nada.
     *
     * @test
     */
    public function test_vuelta_atras_con_redis_y_pendientes_programa_el_worker()
    {
        Log::spy();
        $this->escenario_vps_redis_con_el_interruptor_apagado();
        $this->doble_de_redis_para_ferre_asistente(1);

        $eventos = $this->eventos_del_worker_del_asistente();

        $this->assertCount(1, $eventos, 'Con 1 job en Redis el worker de ferre_asistente tiene que programarse.');
        $this->assertSame(
            AsistenteCanalHelper::comando_del_worker_del_turno_whatsapp('ferre_asistente'),
            (string) $eventos[0]->command
        );
        Log::shouldNotHaveReceived('warning');
    }

    /**
     * CA4.4 (d) — Redis, sin pendientes: el `eval` devuelve 0 → sin worker. El control en positivo
     * es el test anterior (misma config, el doble devuelve 1).
     *
     * @test
     */
    public function test_vuelta_atras_con_redis_sin_pendientes_no_programa_worker()
    {
        $this->escenario_vps_redis_con_el_interruptor_apagado();
        $this->doble_de_redis_para_ferre_asistente(0);

        $this->assertFalse($this->hay_worker_del_asistente(), 'Con 0 jobs en Redis no hay worker.');
    }

    /**
     * CA4.4 (d) — Redis caído: la excepción no tumba el schedule; no se programa worker y queda un
     * `Log::warning` (el huérfano lo vería la herramienta de colas).
     *
     * @test
     */
    public function test_vuelta_atras_con_redis_caido_no_programa_worker_y_avisa()
    {
        Log::spy();
        $this->escenario_vps_redis_con_el_interruptor_apagado();
        $this->doble_de_redis_para_ferre_asistente(new \RuntimeException('Connection refused'));

        $this->assertFalse($this->hay_worker_del_asistente(), 'Con Redis caído no se programa worker y el schedule no se rompe.');
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    /**
     * CA4.4 (d) — un driver que no sabemos contar (ni `database` ni `redis`): sin worker y con
     * `Log::warning`.
     *
     * @test
     */
    public function test_vuelta_atras_con_otro_driver_no_programa_worker_y_avisa()
    {
        Log::spy();
        $this->escenario_vps([
            'queue.default'          => 'beanstalkd',
            'queue.asistente_worker' => false,
        ]);

        $this->assertFalse($this->hay_worker_del_asistente(), 'Un driver desconocido no programa worker.');
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    // ---------------------------------------------------------------------------------------
    // CA4.5 — REDIS_QUEUE
    // ---------------------------------------------------------------------------------------

    /**
     * CA4.5. En el VPS con `REDIS_QUEUE=ferre` y sin `QUEUE_NAME`, la cola de la conexión `redis`
     * que arma `config/queue.php` es `ferre` y el turno va a `ferre_asistente`.
     *
     * @test
     */
    public function test_con_redis_queue_el_turno_va_a_la_cola_derivada()
    {
        $this->dar_extension();
        $this->assertNull(env('QUEUE_NAME'), 'Precondición: el entorno de test no declara QUEUE_NAME.');

        $config = $this->config_de_cola_con_entorno(['REDIS_QUEUE' => 'ferre']);
        $this->assertSame('ferre', $config['connections']['redis']['queue'], 'config/queue.php toma REDIS_QUEUE cuando no hay QUEUE_NAME.');

        $this->escenario_vps([
            'queue.default'                 => 'redis',
            'queue.connections.redis.queue' => $config['connections']['redis']['queue'],
        ]);

        $this->assertSame('ferre_asistente', $this->cola_del_job_despachado_por_whatsapp());
    }

    // ---------------------------------------------------------------------------------------
    // CA4.6 — mismo evento que el shared, sin mutex
    // ---------------------------------------------------------------------------------------

    /**
     * CA4.6. Con la opción prendida en VPS (`scheduler_worker=false`) y la caché en `array` (el de
     * phpunit.xml) y en `file`:
     *   (a) exactamente un evento del worker de `default_asistente`, `* * * * *`, en segundo plano,
     *       sin `withoutOverlapping`, con el mismo comando que arma el shared para esa cola;
     *   (b) con el mutex del evento tomado en la caché igual pasa los filtros (arranca aunque el
     *       anterior siga, como `7_…:424-441`);
     *   (c) ningún `Log::warning` por la caché.
     *
     * @test
     */
    public function test_el_evento_vps_es_el_del_shared_y_no_tiene_mutex()
    {
        $this->dar_extension();

        // El comando del shared para la misma cola, como referencia (control en positivo).
        $this->escenario_shared();
        $del_shared = $this->eventos_del_worker_del_asistente();
        $this->assertCount(1, $del_shared, 'Control: el shared tiene su worker del asistente.');
        $comando_del_shared = (string) $del_shared[0]->command;

        foreach (['array', 'file'] as $cache) {
            Log::spy();
            $this->escenario_vps(['cache.default' => $cache]);

            $eventos = $this->eventos_del_worker_del_asistente();
            $this->assertCount(1, $eventos, 'Caché ' . $cache . ': tiene que haber exactamente UN worker del asistente en VPS.');
            $evento = $eventos[0];

            $this->assertSame($comando_del_shared, (string) $evento->command, 'Caché ' . $cache . ': mismo comando que el shared.');
            $this->assertStringContainsString(self::COMANDO_DEL_WORKER, (string) $evento->command);
            $this->assertStringContainsString(AsistenteCanalHelper::PREFIJO_CIERRE_DE_CANDADOS_HEREDADOS, (string) $evento->command);
            $this->assertSame('* * * * *', $evento->expression);
            $this->assertTrue($evento->runInBackground);
            $this->assertFalse($evento->withoutOverlapping, 'Caché ' . $cache . ': sin mutex.');

            // (b) El worker anterior sigue ocupado: el mutex del evento está tomado.
            $evento->expiresAt = 10;
            $this->assertTrue($evento->mutex->create($evento), 'Precondición: se pudo tomar el mutex del evento.');
            $this->assertTrue($evento->mutex->exists($evento), 'Precondición: el mutex quedó tomado en la caché.');
            $this->assertTrue($evento->filtersPass($this->app), 'Caché ' . $cache . ': con el anterior ocupado el evento igual arranca.');
            $evento->mutex->forget($evento);

            // (c) La caché no influye: ningún aviso.
            Log::shouldNotHaveReceived('warning');
        }

        Cache::store('file')->flush();
    }

    // ---------------------------------------------------------------------------------------
    // CA4.7 — nunca encolar en `_asistente` sin worker
    // ---------------------------------------------------------------------------------------

    /**
     * CA4.7. Matriz de 32 casos: `app.VPS` × `scheduler_worker` × `asistente_worker` ×
     * `asistente_worker_vps` × `cache.default` {file, array}, con `queue.default=database`, la
     * extensión, `USER_ID` y 0 filas en `jobs`.
     *
     * Con cola (8): VPS con aw=true y vps=true (4: ignora `scheduler_worker` y la caché) y shared
     * con sw=true y aw=true (4: idéntico a hoy, no mira la opción VPS ni la caché). Sin cola (24):
     * el resto. Por caso: `cola_del_turno_whatsapp()` = C ⇔ el schedule tiene EXACTAMENTE un evento
     * del worker de C (filtersPass, sin withoutOverlapping, mismo comando); null ⇔ 0 eventos.
     *
     * @test
     */
    public function test_matriz_nunca_se_encola_en_la_cola_del_asistente_sin_worker()
    {
        $this->dar_extension();
        $this->assertSame(0, DB::table('jobs')->count(), 'Precondición: sin filas en jobs (no hay huérfanos que drenar).');

        $con_cola = 0;
        $sin_cola = 0;
        $comando_esperado = AsistenteCanalHelper::comando_del_worker_del_turno_whatsapp(self::COLA_POR_DEFECTO);

        foreach ([true, false] as $vps) {
            foreach ([true, false] as $scheduler_worker) {
                foreach ([true, false] as $asistente_worker) {
                    foreach ([true, false] as $opcion_vps) {
                        foreach (['file', 'array'] as $cache) {
                            config([
                                'app.VPS'                          => $vps,
                                'queue.scheduler_worker'           => $scheduler_worker,
                                'queue.asistente_worker'           => $asistente_worker,
                                'queue.asistente_worker_vps'       => $opcion_vps,
                                'cache.default'                    => $cache,
                                'queue.default'                    => 'database',
                                'queue.connections.database.queue' => 'default',
                                'app.USER_ID'                      => $this->comercio->id,
                            ]);

                            $caso = 'VPS=' . var_export($vps, true)
                                . ' sw=' . var_export($scheduler_worker, true)
                                . ' aw=' . var_export($asistente_worker, true)
                                . ' vps=' . var_export($opcion_vps, true)
                                . ' cache=' . $cache;

                            $esperada = ($vps ? ($asistente_worker && $opcion_vps) : ($scheduler_worker && $asistente_worker))
                                ? self::COLA_POR_DEFECTO
                                : null;

                            $cola = AsistenteCanalHelper::cola_del_turno_whatsapp();
                            $eventos = $this->eventos_del_worker_del_asistente();

                            $this->assertSame($esperada, $cola, 'Cola del turno en el caso ' . $caso);

                            if ($esperada === null) {
                                $sin_cola++;
                                $this->assertCount(0, $eventos, 'Sin cola no hay worker del asistente en el caso ' . $caso);
                                continue;
                            }

                            $con_cola++;
                            $this->assertCount(1, $eventos, 'Con cola tiene que haber exactamente un worker en el caso ' . $caso);
                            $this->assertSame($comando_esperado, (string) $eventos[0]->command, 'Comando del worker en el caso ' . $caso);
                            $this->assertFalse($eventos[0]->withoutOverlapping, 'Sin mutex en el caso ' . $caso);
                            $this->assertTrue($eventos[0]->filtersPass($this->app), 'El evento pasa sus filtros en el caso ' . $caso);
                        }
                    }
                }
            }
        }

        $this->assertSame(8, $con_cola, 'La matriz tiene 8 casos con cola (4 VPS + 4 shared).');
        $this->assertSame(24, $sin_cola, 'La matriz tiene 24 casos sin cola.');
    }
}
