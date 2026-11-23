<?php

namespace Tests\Feature\Infraestructura;

use App\Console\Kernel;
use App\Jobs\InferirTituloConversacionIaJob;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiConversation;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\AsistenteWhatsapp\AsistenteWhatsappTestCase;

/**
 * Misión asistente-whatsapp-velocidad, ETAPA 2 (empresa-api) — la cola propia del turno de WhatsApp
 * y su worker por minuto en el hosting compartido (CA9 a CA13 y CA15 del plan).
 *
 * El problema que se arregla: en el hosting compartido el turno del asistente espera al cron de cada
 * minuto (≈28 s de mediana en truvari). La solución: el turno se despacha a una cola propia
 * (`<QUEUE_NAME>_asistente`) y el schedule deja un worker vivo (`--sleep=1`, sin `--stop-when-empty`)
 * que lo levanta apenas llega. En el VPS no cambia NADA (cola null, sin evento): ahí el supervisor ya
 * tiene un worker permanente sobre `default`.
 *
 * Decisiones de Lucas del 21/11/2026 que este archivo fija:
 *   - P1 = sí: el chat de la pantalla también va a la cola del asistente (CA15).
 *   - P2 = A: el worker vive SIEMPRE que el dueño tenga la extensión `agentes_ia` (con el interruptor
 *     QUEUE_ASISTENTE_WORKER): no depende de que haya jobs pendientes. Sin extensión, o con el
 *     interruptor apagado, sólo corre si quedaron jobs huérfanos en esa cola (el turno despachado
 *     antes de apagar algo no puede quedar sin worker).
 *
 * 🔴 Cada test "no pasa X" lleva su control en positivo adentro: un "no se programa el worker" pasa en
 * verde ANTES de construir el worker, así que sólo vale si el mismo escenario, con la condición
 * corregida, SÍ lo programa.
 *
 * Reutiliza el fixture de `AsistenteWhatsappTestCase` (dueño propio con `app.USER_ID` fijado, el POST
 * real del canal con `mandar()`, `dar_extension()`) y la lectura del schedule por reflexión de
 * `1_Worker_de_cola_segun_hosting_Test` y `5_Gates_del_schedule_Test`.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types ni enum.
 *
 * @group asistente-whatsapp
 */
class Worker_del_turno_de_whatsapp_Test extends AsistenteWhatsappTestCase
{
    /** Cola que el turno de WhatsApp usa en shared cuando QUEUE_NAME no está seteada. */
    const COLA_POR_DEFECTO = 'default_asistente';

    /** Comando del worker del asistente (D7 + Enmienda 8): vivo, sleep 1 y tope de 65 s (se pisa con el siguiente). */
    const COMANDO_DEL_WORKER = 'queue:work --queue=default_asistente --sleep=1 --max-time=65';

    protected function setUp(): void
    {
        parent::setUp();

        // Las colas no se procesan: sólo se mira a qué cola se despacha cada job.
        Queue::fake();

        $this->escenario_shared();
    }

    // ---------------------------------------------------------------------------------------
    // Ayudantes
    // ---------------------------------------------------------------------------------------

    /**
     * Deja la config en el caso que esta misión acelera: hosting compartido, con el worker del
     * scheduler y el interruptor del asistente encendidos y `QUEUE_NAME` sin setear. El dueño NO
     * recibe la extensión acá: cada test decide.
     *
     * @return void
     */
    protected function escenario_shared()
    {
        config([
            'app.VPS'                          => false,
            'queue.scheduler_worker'           => true,
            'queue.asistente_worker'           => true,
            'queue.connections.database.queue' => 'default',
            'app.USER_ID'                      => $this->comercio->id,
        ]);
    }

    /**
     * Manda un mensaje por el POST real del canal y devuelve la cola del job de respuesta que
     * quedó despachado (null = la cola por defecto, que es lo de hoy). La cola de fake se limpia
     * antes, así que sirve para comparar varios escenarios dentro de un mismo test.
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
     * Corre el schedule() del Kernel contra un Schedule limpio y devuelve sus eventos. Por
     * reflexión, porque schedule() es protected (mismo mecanismo que 1_Worker_… y 5_Gates_…).
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
     * El evento `queue:work` cuyo comando contiene el fragmento, o falla el test si no está.
     *
     * @param  string  $fragmento
     * @return \Illuminate\Console\Scheduling\Event
     */
    protected function evento_con($fragmento)
    {
        foreach ($this->eventos_programados() as $evento) {
            if (strpos((string) $evento->command, $fragmento) !== false) {
                return $evento;
            }
        }

        $this->fail('El schedule no programa "' . $fragmento . '".');
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
     * Deja una fila de trabajo pendiente en la tabla `jobs` de la cola indicada: un turno que se
     * despachó y todavía nadie levantó.
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
     * Dos turnos seguidos de la misma conversación de WhatsApp, el primero todavía `pendiente`
     * (el escenario de P145_Un_turno_a_la_vez_por_conversacion_Test).
     *
     * @return \App\Models\AiMessage El assistant del SEGUNDO turno.
     */
    protected function segundo_turno_con_el_primero_en_curso()
    {
        $conversacion = $this->conversacion_whatsapp();

        $this->mensaje($conversacion, 'user', 'listo', ['contenido' => 'Llevó una caja de Mascota Malbec']);
        $this->mensaje($conversacion, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);
        $this->mensaje($conversacion, 'user', 'listo', ['contenido' => 'Sumale $1.000 de envío']);

        return $this->mensaje($conversacion, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);
    }

    // ---------------------------------------------------------------------------------------
    // CA9 — el despacho en shared con extensión
    // ---------------------------------------------------------------------------------------

    /**
     * En shared, con el worker del scheduler y el interruptor encendidos y el dueño con
     * `agentes_ia`, el turno de WhatsApp va a `default_asistente` y el título sigue en la cola
     * por defecto (no es urgente: así el worker nuevo sólo tiene turnos).
     *
     * @test
     */
    public function test_en_shared_el_turno_va_a_la_cola_del_asistente()
    {
        $this->dar_extension();

        $this->mandar(['texto' => 'Cuánto vendí hoy'])->assertStatus(202);

        Queue::assertPushedOn(self::COLA_POR_DEFECTO, ResponderMensajeChatIaJob::class);

        // Control en positivo: el job del título SÍ se despachó (el primer mensaje de la conversación
        // lo dispara), así que el "queda en default" de abajo no es vacío.
        $titulos = Queue::pushed(InferirTituloConversacionIaJob::class);
        $this->assertCount(1, $titulos, 'El primer mensaje tiene que despachar el job del título.');
        $this->assertNull($titulos->first()->queue, 'El título no es urgente: queda en la cola por defecto (queue null).');
    }

    /**
     * La cola se deriva de `QUEUE_NAME`: las instalaciones que comparten base no pueden tomar el
     * turno de otra (`config/queue.php:59-61`).
     *
     * @test
     */
    public function test_la_cola_respeta_queue_name()
    {
        $this->dar_extension();

        config(['queue.connections.database.queue' => 'fenix']);

        $this->mandar(['texto' => 'Cuánto vendí hoy'])->assertStatus(202);

        Queue::assertPushedOn('fenix_asistente', ResponderMensajeChatIaJob::class);

        // Control: sin QUEUE_NAME el mismo POST va a la cola `default_asistente` (el nombre se deriva, no es fijo).
        $this->assertSame(self::COLA_POR_DEFECTO, $this->cola_derivada_sin_queue_name());
    }

    /**
     * La cola del turno con `QUEUE_NAME` sin setear (control del test de `QUEUE_NAME`).
     *
     * @return string|null
     */
    protected function cola_derivada_sin_queue_name()
    {
        config(['queue.connections.database.queue' => 'default']);

        return $this->cola_del_job_despachado_por_whatsapp();
    }

    // ---------------------------------------------------------------------------------------
    // CA10 — VPS y el interruptor no cambian nada
    // ---------------------------------------------------------------------------------------

    /**
     * En VPS, con el interruptor del asistente apagado o con el worker del scheduler apagado, el
     * job despachado queda con `queue` null: lo de hoy, todo a `default`.
     *
     * El primer escenario es el control en positivo: la misma instancia en shared, con todo
     * encendido, SÍ usa la cola del asistente. Sin él, "queda en null" pasaría en verde antes de
     * construir nada.
     *
     * @test
     */
    public function test_en_vps_y_con_el_interruptor_apagado_no_cambia_la_cola()
    {
        $this->dar_extension();

        // Control en positivo.
        $this->assertSame(
            self::COLA_POR_DEFECTO,
            $this->cola_del_job_despachado_por_whatsapp(),
            'Control: en shared con todo encendido el turno tiene que ir a la cola del asistente.'
        );

        // VPS: el supervisor sólo consume `default`; una cola nueva dejaría el turno sin nadie.
        config(['app.VPS' => true]);
        $this->assertNull($this->cola_del_job_despachado_por_whatsapp(), 'En VPS el turno queda en la cola por defecto.');

        // Interruptor del asistente apagado: el rollback por cliente.
        $this->escenario_shared();
        config(['queue.asistente_worker' => false]);
        $this->assertNull($this->cola_del_job_despachado_por_whatsapp(), 'Con QUEUE_ASISTENTE_WORKER=false vuelve a default.');

        // Sin worker del scheduler no hay quien atienda la cola nueva.
        $this->escenario_shared();
        config(['queue.scheduler_worker' => false]);
        $this->assertNull($this->cola_del_job_despachado_por_whatsapp(), 'Sin worker del scheduler vuelve a default.');
    }

    /**
     * Sin la extensión `agentes_ia` el dueño no tiene la cola nueva, aunque la instancia sea shared
     * y todo esté encendido. En esa combinación el endpoint del canal contesta 403 (el gate del
     * módulo), así que lo que se verifica es el ayudante único que decide la cola.
     *
     * Control: con la extensión, el mismo ayudante devuelve la cola.
     *
     * @test
     */
    public function test_el_ayudante_de_la_cola_exige_la_extension_del_dueno()
    {
        $this->assertTrue(
            method_exists(\App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper::class, 'cola_del_turno_whatsapp'),
            'Falta AsistenteCanalHelper::cola_del_turno_whatsapp(): el ayudante único que decide la cola (D5).'
        );

        $this->assertNull(
            \App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper::cola_del_turno_whatsapp(),
            'Sin la extensión del dueño no hay cola del asistente.'
        );

        $this->dar_extension();

        $this->assertSame(
            self::COLA_POR_DEFECTO,
            \App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper::cola_del_turno_whatsapp(),
            'Control: con la extensión, en shared y con todo encendido, devuelve la cola.'
        );
    }

    // ---------------------------------------------------------------------------------------
    // CA11 — el redespacho conserva la cola
    // ---------------------------------------------------------------------------------------

    /**
     * Si hay un turno anterior en curso, el job se vuelve a encolar con 8 s de demora y TIENE QUE
     * quedar en la misma cola: si cayera en `default` volvería a esperar al cron del minuto. En el
     * chat de la pantalla (cola null) el redespacho sigue en null, igual que hoy.
     *
     * @test
     */
    public function test_el_redespacho_por_turno_anterior_conserva_la_cola()
    {
        $segundo = $this->segundo_turno_con_el_primero_en_curso();

        (new ResponderMensajeChatIaJob($segundo->id))->onQueue(self::COLA_POR_DEFECTO)->handle();

        Queue::assertPushedOn(self::COLA_POR_DEFECTO, ResponderMensajeChatIaJob::class, function ($job) {
            return $job->esperas === 1 && ! is_null($job->delay);
        });

        // Control: el chat de la pantalla (job sin cola) se redespacha igual, y sigue sin cola.
        Queue::fake();

        (new ResponderMensajeChatIaJob($segundo->id))->handle();

        $redespachados = Queue::pushed(ResponderMensajeChatIaJob::class);

        $this->assertCount(1, $redespachados, 'Control: con el turno anterior en curso el job se vuelve a encolar.');
        $this->assertNull($redespachados->first()->queue, 'Sin cola de origen el redespacho sigue en la cola por defecto.');
        $this->assertSame(1, $redespachados->first()->esperas);
    }

    // ---------------------------------------------------------------------------------------
    // CA12 — el schedule en shared
    // ---------------------------------------------------------------------------------------

    /**
     * Con la extensión y el interruptor encendido, el schedule programa UN worker de la cola del
     * asistente: vivo (sin `--stop-when-empty`), con tope de 65 s, SIN `withoutOverlapping`
     * (Enmienda 8: cada minuto arranca uno aunque el anterior siga ocupado) y sin bloquear el resto
     * (`runInBackground`). Y vive siempre, sin
     * depender de que haya jobs pendientes (P2 = A). Los dos workers de siempre quedan idénticos.
     *
     * @test
     */
    public function test_en_shared_el_schedule_lanza_el_worker_del_asistente()
    {
        $this->dar_extension();

        // Sin ningún job pendiente: el worker vive igual (P2 = A).
        $this->assertSame(0, DB::table('jobs')->where('queue', self::COLA_POR_DEFECTO)->count());

        $eventos = $this->eventos_del_worker_del_asistente();

        $this->assertCount(1, $eventos, 'Tiene que haber exactamente UN worker del asistente en el schedule.');

        $evento = $eventos[0];
        $comando = (string) $evento->command;

        $this->assertStringContainsString(self::COMANDO_DEL_WORKER, $comando);
        $this->assertStringNotContainsString('--stop-when-empty', $comando, 'El worker queda vivo esperando: es justo lo que lo diferencia del de default.');
        $this->assertFalse($evento->withoutOverlapping, 'Enmienda 8: sin withoutOverlapping, así un turno que cruza el minuto no deja sin worker al tick siguiente.');
        $this->assertTrue($evento->runInBackground, 'Sin runInBackground bloquearía el resto del schedule:run.');
        $this->assertTrue($evento->filtersPass($this->app), 'Sin jobs pendientes igual arranca: el worker vive siempre con la extensión.');

        // La cola sale de QUEUE_NAME.
        config(['queue.connections.database.queue' => 'fenix']);
        $con_queue_name = $this->eventos_del_worker_del_asistente();
        $this->assertCount(1, $con_queue_name);
        $this->assertStringContainsString('queue:work --queue=fenix_asistente --sleep=1 --max-time=65', (string) $con_queue_name[0]->command);
        config(['queue.connections.database.queue' => 'default']);

        // Los dos eventos queue:work existentes, idénticos (alcance de §4: no se tocan).
        $de_default = $this->evento_con('queue:work --stop-when-empty');
        $this->assertTrue($de_default->withoutOverlapping);
        $this->assertSame(75, $de_default->expiresAt);
        $this->assertTrue($de_default->runInBackground);
        $this->assertStringNotContainsString('--queue=', (string) $de_default->command);

        $de_excel = $this->evento_con('queue:work --queue=excel --stop-when-empty --memory=512 --max-time=1200');
        $this->assertTrue($de_excel->withoutOverlapping);
        $this->assertSame(75, $de_excel->expiresAt);
        $this->assertTrue($de_excel->runInBackground);
    }

    /**
     * CA17 (Enmienda 8): aunque el mutex del evento del asistente esté tomado en la caché (el
     * worker anterior todavía ocupado), el evento igual pasa sus filtros y se lanzaría. Es el hueco
     * medido el 22/11 (turno de truvari 05:23, 32 s de espera): con `withoutOverlapping` el tick
     * se salteaba y no había worker hasta el minuto siguiente.
     *
     * El mutex se toma como lo toma Laravel 8.83: `CacheEventMutex::create()` hace un
     * `cache->add(Event::mutexName(), true, expiresAt * 60)` y el filtro `skip` que agrega
     * `withoutOverlapping()` consulta `mutex->exists()`.
     *
     * @test
     */
    public function test_el_worker_arranca_aunque_el_anterior_siga_ocupado()
    {
        $this->dar_extension();

        $eventos = $this->eventos_del_worker_del_asistente();
        $this->assertCount(1, $eventos, 'Tiene que haber exactamente UN worker del asistente.');
        $evento = $eventos[0];

        // Worker anterior ocupado: se toma el mutex del evento en la caché, con el mismo
        // mecanismo que usa el schedule (tiempo de vida de sobra para que siga tomado).
        $evento->expiresAt = 10;
        $this->assertTrue($evento->mutex->create($evento), 'Precondición: se pudo tomar el mutex del evento.');
        $this->assertTrue($evento->mutex->exists($evento), 'Precondición: el mutex del evento quedó tomado en la caché.');

        $this->assertTrue($evento->filtersPass($this->app), 'Con el worker anterior ocupado el evento igual tiene que lanzarse.');

        $evento->mutex->forget($evento);
    }

    // ---------------------------------------------------------------------------------------
    // CA13 — el schedule no programa el worker cuando no corresponde
    // ---------------------------------------------------------------------------------------

    /**
     * No hay worker del asistente en VPS, sin la extensión, sin `USER_ID`, con el interruptor
     * apagado ni con el worker del scheduler apagado (todos sin jobs pendientes).
     *
     * 🔴 Control en positivo en el mismo test: el escenario completo (con la extensión) SÍ lo
     * programa. Es lo que impide que este test pase en verde antes de construir el worker.
     *
     * @test
     */
    public function test_sin_extension_o_en_vps_no_hay_worker()
    {
        // Sin la extensión y sin jobs pendientes: nada.
        $this->assertFalse($this->hay_worker_del_asistente(), 'Sin la extensión y sin jobs no hay worker.');

        // Control en positivo: con la extensión, el mismo escenario lo programa.
        $this->dar_extension();
        $this->assertTrue($this->hay_worker_del_asistente(), 'Control: con la extensión el schedule SÍ programa el worker del asistente.');

        // VPS: el bloque entero no se ejecuta.
        config(['app.VPS' => true]);
        $this->assertFalse($this->hay_worker_del_asistente(), 'En VPS no hay worker del asistente.');
        $this->escenario_shared();
        $this->assertTrue($this->hay_worker_del_asistente(), 'Control: volviendo a shared reaparece.');

        // Sin USER_ID no se sabe quién es el dueño: no se programa.
        config(['app.USER_ID' => null]);
        $this->assertFalse($this->hay_worker_del_asistente(), 'Sin USER_ID no hay worker.');
        $this->escenario_shared();
        $this->assertTrue($this->hay_worker_del_asistente(), 'Control: con USER_ID reaparece.');

        // Interruptor del asistente apagado, sin jobs pendientes.
        config(['queue.asistente_worker' => false]);
        $this->assertFalse($this->hay_worker_del_asistente(), 'Con QUEUE_ASISTENTE_WORKER=false y sin jobs no hay worker.');
        $this->escenario_shared();
        $this->assertTrue($this->hay_worker_del_asistente(), 'Control: con el interruptor encendido reaparece.');

        // Sin worker del scheduler no se programa ninguno, ni el del asistente.
        config(['queue.scheduler_worker' => false]);
        $this->assertFalse($this->hay_worker_del_asistente(), 'Con QUEUE_SCHEDULER_WORKER=false no hay worker del asistente.');
        $this->escenario_shared();
        $this->assertTrue($this->hay_worker_del_asistente(), 'Control: con el worker del scheduler encendido reaparece.');
    }

    /**
     * Un turno despachado antes de que se apagara la extensión (o el interruptor) no puede quedar
     * huérfano: si en `jobs` hay una fila de la cola del asistente, el worker corre igual y la
     * drena. Es la regla "cero procesos sin trabajo" de Kernel.php:34-40 en su forma inversa.
     *
     * Los controles: una fila en OTRA cola no cuenta; y el VPS o el worker del scheduler apagado
     * siguen mandando aunque haya filas.
     *
     * @test
     */
    public function test_con_jobs_huerfanos_el_worker_igual_corre()
    {
        // Sin extensión y sin filas: no hay worker.
        $this->assertFalse($this->hay_worker_del_asistente());

        // Una fila de OTRA cola no es trabajo del asistente.
        $this->dejar_job_pendiente_en('default');
        $this->assertFalse($this->hay_worker_del_asistente(), 'Una fila de la cola default no hace correr al worker del asistente.');

        // Sin extensión, pero con una fila de la cola del asistente: el worker la drena.
        $this->dejar_job_pendiente_en(self::COLA_POR_DEFECTO);
        $eventos = $this->eventos_del_worker_del_asistente();
        $this->assertCount(1, $eventos, 'Con un job huérfano en la cola del asistente el worker tiene que programarse.');
        $this->assertStringContainsString(self::COMANDO_DEL_WORKER, (string) $eventos[0]->command);
        $this->assertStringNotContainsString('--stop-when-empty', (string) $eventos[0]->command);
        $this->assertTrue($eventos[0]->filtersPass($this->app));

        // También con el interruptor del asistente apagado.
        config(['queue.asistente_worker' => false]);
        $this->assertTrue($this->hay_worker_del_asistente(), 'Con el interruptor apagado y un huérfano, el worker drena igual.');

        // Y sin USER_ID (no se sabe el dueño, pero hay trabajo).
        $this->escenario_shared();
        config(['app.USER_ID' => null]);
        $this->assertTrue($this->hay_worker_del_asistente(), 'Sin USER_ID y con un huérfano, el worker drena igual.');
        $this->escenario_shared();

        // Con QUEUE_NAME, la fila que cuenta es la de SU cola.
        config(['queue.connections.database.queue' => 'fenix']);
        $this->assertFalse($this->hay_worker_del_asistente(), 'La fila de default_asistente no es de la instancia fenix.');
        $this->dejar_job_pendiente_en('fenix_asistente');
        $this->assertTrue($this->hay_worker_del_asistente(), 'La fila de fenix_asistente sí.');
        config(['queue.connections.database.queue' => 'default']);

        // El VPS y el worker del scheduler apagado mandan, aunque haya filas.
        config(['app.VPS' => true]);
        $this->assertFalse($this->hay_worker_del_asistente(), 'En VPS no se programa ningún worker, ni para huérfanos.');
        $this->escenario_shared();
        config(['queue.scheduler_worker' => false]);
        $this->assertFalse($this->hay_worker_del_asistente(), 'Sin worker del scheduler no se programa ninguno, ni para huérfanos.');
        $this->escenario_shared();
        $this->assertTrue($this->hay_worker_del_asistente(), 'Control: con todo encendido y un huérfano, el worker está.');
    }

    // ---------------------------------------------------------------------------------------
    // CA15 — el chat de la pantalla también va a la cola del asistente (P1 = sí)
    // ---------------------------------------------------------------------------------------

    /**
     * El chat de la pantalla (POST api/ai-conversations/{id}/messages) despacha el turno a la cola
     * del asistente cuando el ayudante único devuelve una (shared + worker del scheduler +
     * interruptor + dueño con `agentes_ia`), y queda igual que hoy (cola null) en VPS o con el
     * interruptor apagado. Misma regla para los dos canales: no hay dos criterios.
     *
     * @test
     */
    public function test_el_chat_de_la_pantalla_tambien_va_a_la_cola_del_asistente()
    {
        $this->dar_extension();

        $conversacion = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        $this->actingAs($this->comercio, 'web');

        // En shared, con todo encendido: a la cola del asistente.
        $this->postJson('api/ai-conversations/' . $conversacion->id . '/messages', ['contenido' => '¿Cuánto vendí hoy?'])
            ->assertStatus(201);

        Queue::assertPushedOn(self::COLA_POR_DEFECTO, ResponderMensajeChatIaJob::class);

        // Control (VPS): el mismo POST, en una conversación nueva, despacha el job SIN cola.
        Queue::fake();
        config(['app.VPS' => true]);

        $otra = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        $this->postJson('api/ai-conversations/' . $otra->id . '/messages', ['contenido' => '¿Y ayer?'])
            ->assertStatus(201);

        $jobs = Queue::pushed(ResponderMensajeChatIaJob::class);

        $this->assertCount(1, $jobs, 'Control: en VPS el chat despacha el job igual.');
        $this->assertNull($jobs->first()->queue, 'En VPS el chat de la pantalla queda en la cola por defecto, como hoy.');

        // Control (interruptor apagado): también null.
        Queue::fake();
        $this->escenario_shared();
        config(['queue.asistente_worker' => false]);

        $tercera = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        $this->postJson('api/ai-conversations/' . $tercera->id . '/messages', ['contenido' => '¿Y anteayer?'])
            ->assertStatus(201);

        $jobs = Queue::pushed(ResponderMensajeChatIaJob::class);

        $this->assertCount(1, $jobs);
        $this->assertNull($jobs->first()->queue, 'Con el interruptor apagado el chat queda en la cola por defecto.');
    }
}
