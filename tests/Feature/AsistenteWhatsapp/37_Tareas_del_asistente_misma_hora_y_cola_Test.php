<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use App\Http\Controllers\Helpers\asistente_ia\TareasDelAsistenteIaHelper;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiMessage;
use App\Models\AiTareaDelAsistenteEjecucion;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * Misión agente-tareas-programadas, ENMIENDA 1 / E2, E3 y E4 (22/11/2026) — lo que dejó la ronda 1 de revisión.
 *
 *   E2. «No interrumpir al dueño» mide sólo actividad REAL del dueño (mensajes `user` suyos, no sintéticos, de los
 *       últimos 10 minutos), no `last_message_at` —que escribe el propio despertar, y los recordatorios, avisos y
 *       sugerencias—. Dos o tres tareas que vencen a la misma hora salen en el MISMO barrido, y cada turno sabe
 *       cuál es SU tarea (contexto_del_turno no cruza títulos).
 *   E3. El turno de la tarea va por la misma cola que los demás turnos de WhatsApp.
 *   E4. Un error de base que no es el duplicado del unique no avanza la tarea en silencio; el caché estático
 *       `hay_tablas` no deja un «false» pegado.
 *
 * PHP 7.4: sin match, sin ?->, sin str_contains, sin argumentos nombrados.
 */
class Tareas_del_asistente_misma_hora_y_cola_Test extends TareasDelAsistenteConModeloTestCase
{
    /**
     * Crea `$cuantas` tareas de una sola vez a las 10:01 de hoy (sábado 21/11, reloj a las 10:00: dentro del
     * anticipo), cada una con su título.
     *
     * @param  int  $cuantas
     * @return array<int,\App\Models\AiTareaDelAsistente>
     */
    protected function tareas_de_las_diez_y_uno($cuantas)
    {
        $titulos = ['Cobranzas del día', 'Stock crítico', 'Pedidos de la tienda'];
        $tareas = [];

        for ($i = 0; $i < $cuantas; $i++) {
            $tareas[] = $this->tarea_del_asistente([
                'titulo'               => $titulos[$i],
                'instruccion'          => 'Revisá ' . strtolower($titulos[$i]),
                'fecha_inicio'         => '2026-11-21',
                'hora'                 => '10:01',
                'franja'               => null,
                'proxima_ejecucion_at' => '2026-11-21 10:01:00',
            ]);
        }

        return $tareas;
    }

    /**
     * 🔴 E2. Dos tareas a la misma hora salen en el MISMO barrido (dos ejecuciones, dos jobs) y cada turno trae
     * el título de SU tarea en el system: el job de la primera no se entera de la segunda. Antes la segunda
     * esperaba ~10 minutos (el barrido de la primera movía last_message_at) y los dos turnos mostraban el mismo
     * título.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function dos_tareas_a_la_misma_hora_salen_juntas_y_cada_turno_sabe_la_suya()
    {
        $tareas = $this->tareas_de_las_diez_y_uno(2);

        $this->despertar();

        $this->assertCount(1, $this->ejecuciones($tareas[0]->id));
        $this->assertCount(1, $this->ejecuciones($tareas[1]->id), 'La segunda tarea tiene que salir en el mismo barrido.');
        Queue::assertPushed(ResponderMensajeChatIaJob::class, 2);

        $this->turnos_con_su_titulo($tareas);
    }

    /**
     * 🔴 E2. Con tres (el tope por barrido) pasa lo mismo.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function tres_tareas_a_la_misma_hora_salen_juntas()
    {
        $tareas = $this->tareas_de_las_diez_y_uno(3);

        $this->despertar();

        foreach ($tareas as $tarea) {
            $this->assertCount(1, $this->ejecuciones($tarea->id), 'Las tres tienen que salir en el mismo barrido.');
        }

        Queue::assertPushed(ResponderMensajeChatIaJob::class, 3);

        $this->turnos_con_su_titulo($tareas);
    }

    /**
     * Corre el job de cada tarea (en orden) y verifica que el system de ese turno nombre SU título y ninguno
     * de los otros.
     *
     * @param  array<int,\App\Models\AiTareaDelAsistente>  $tareas
     * @return void
     */
    protected function turnos_con_su_titulo(array $tareas)
    {
        $this->modelo_contesta('Listo, esto es lo que vi.');

        foreach ($tareas as $indice => $tarea) {

            $ejecucion = $this->ejecuciones($tarea->id)[0];

            (new ResponderMensajeChatIaJob((int) $ejecucion->ai_message_id))->handle();

            $pedidos = $this->pedidos_al_modelo();

            $this->assertCount($indice + 1, $pedidos, 'Cada turno hace un pedido al modelo.');

            $system = $this->system_sin_cache($pedidos[$indice]);

            $this->assertStringContainsString('«' . $tarea->titulo . '»', $system, 'El turno tiene que saber cuál es SU tarea.');

            foreach ($tareas as $otra) {
                if ($otra->id !== $tarea->id) {
                    $this->assertStringNotContainsString('«' . $otra->titulo . '»', $system, 'El turno de «' . $tarea->titulo . '» nombró la tarea «' . $otra->titulo . '».');
                }
            }

            $this->assertSame('listo', AiMessage::find((int) $ejecucion->ai_message_id)->estado);
        }
    }

    /**
     * 🔴 E2 (CA10 sigue en pie). Con un mensaje REAL del dueño de hace 3 minutos no se despierta; con uno de hace
     * 11 minutos sí.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function la_espera_de_diez_minutos_sigue_con_un_mensaje_real_del_dueno()
    {
        $tareas = $this->tareas_de_las_diez_y_uno(1);

        $this->ahora('2026-11-21 09:57:00');

        $conversacion = $this->conversacion_whatsapp();
        $this->mensaje($conversacion, 'user', 'listo', ['contenido' => 'Che, ¿cuánto vendimos hoy?']);

        $this->ahora('2026-11-21 10:00:00');

        $this->despertar();

        $this->assertSame([], $this->ejecuciones($tareas[0]->id), 'No se despierta al asistente en medio de la charla del dueño.');
        Queue::assertNothingPushed();

        // 11 minutos después de su último mensaje el dueño ya no está escribiendo.
        $this->ahora('2026-11-21 10:09:00');

        $this->despertar();

        $this->assertCount(1, $this->ejecuciones($tareas[0]->id));
    }

    /**
     * 🔴 E2. Lo que NO es el dueño escribiendo no frena: un recordatorio o aviso del asistente (mensaje
     * `assistant` reciente, con la conversación recién movida), un mensaje sintético de otra tarea y un mensaje
     * de otro dueño de la base no cuentan.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function un_recordatorio_un_aviso_o_un_mensaje_sintetico_reciente_no_frenan()
    {
        $tareas = $this->tareas_de_las_diez_y_uno(1);

        $this->ahora('2026-11-21 09:58:00');

        // El asistente le mandó un recordatorio y un aviso hace dos minutos (mueven last_message_at).
        $conversacion = $this->conversacion_whatsapp();
        $this->mensaje($conversacion, 'assistant', 'listo', ['contenido' => 'Recordatorio: llamar a Juan hoy a las 11.']);
        $this->mensaje($conversacion, 'assistant', 'listo', ['contenido' => 'Aviso: el escaneo de la factura terminó.']);

        // Un mensaje sintético de otra tarea que despertó recién.
        $this->mensaje($conversacion, 'user', 'listo', ['tipo' => 'tarea_programada', 'contenido' => '[Tarea programada] Otra: revisá algo']);

        // Un mensaje reciente de OTRO dueño de la base compartida.
        $otro = $this->otro_dueno();
        $de_otro = $this->conversacion_whatsapp(null, $otro);
        $this->mensaje($de_otro, 'user', 'listo', ['contenido' => 'Hola, soy de otro comercio']);

        $this->ahora('2026-11-21 10:00:00');

        $this->despertar();

        $this->assertCount(1, $this->ejecuciones($tareas[0]->id), 'Nada de eso es el dueño escribiendo: la tarea tiene que salir.');
        Queue::assertPushed(ResponderMensajeChatIaJob::class, 1);
    }

    /**
     * 🔴 E3. El turno de la tarea va por la misma cola que los demás turnos de WhatsApp: con el interruptor
     * encendido en hosting compartido, la cola propia del asistente (control: no es null).
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_turno_va_por_la_cola_del_asistente_cuando_esta_habilitada()
    {
        config([
            'app.VPS'                          => false,
            'queue.scheduler_worker'           => true,
            'queue.asistente_worker'           => true,
            'queue.connections.database.queue' => 'default',
            'app.USER_ID'                      => $this->comercio->id,
        ]);

        $cola = AsistenteCanalHelper::cola_del_turno_whatsapp();

        $this->assertSame('default_asistente', $cola, 'Control: con el interruptor encendido la cola es la propia del asistente.');

        $this->tareas_de_las_diez_y_uno(1);

        $this->despertar();

        Queue::assertPushedOn($cola, ResponderMensajeChatIaJob::class);
    }

    /**
     * 🔴 E3. Con el interruptor apagado (o en el VPS) la cola es null y el turno cae a la cola por defecto.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function sin_el_interruptor_el_turno_va_por_la_cola_por_defecto()
    {
        config([
            'app.VPS'                => false,
            'queue.scheduler_worker' => true,
            'queue.asistente_worker' => false,
            'app.USER_ID'            => $this->comercio->id,
        ]);

        $this->assertNull(AsistenteCanalHelper::cola_del_turno_whatsapp(), 'Control: con el interruptor apagado no hay cola propia.');

        $this->tareas_de_las_diez_y_uno(1);

        $this->despertar();

        Queue::assertPushedOn(null, ResponderMensajeChatIaJob::class);
    }

    /**
     * Hace que crear una ejecución falle con un error de base que NO es el duplicado del unique (un timeout de
     * lock, una base caída a mitad del barrido) y devuelve la lista donde se juntan los avisos del Log.
     *
     * @return \ArrayObject  Los mensajes de Log de nivel warning o superior (texto + contexto).
     */
    protected function fallar_el_reclamo_con_un_error_de_base()
    {
        $registro = new \ArrayObject();

        Event::listen(MessageLogged::class, function ($evento) use ($registro) {
            if (in_array($evento->level, ['warning', 'error', 'critical', 'alert', 'emergency'], true)) {
                $registro[] = $evento->message . ' ' . json_encode($evento->context);
            }
        });

        AiTareaDelAsistenteEjecucion::creating(function () {
            throw new QueryException('insert into `ai_tareas_del_asistente_ejecuciones`', [], new \PDOException('Lock wait timeout exceeded', 1205));
        });

        return $registro;
    }

    /**
     * 🔴 E4. Un QueryException al reclamar que no es el duplicado del unique no avanza la tarea en silencio: queda
     * registrado en el Log y la ocurrencia sigue pendiente (se reintenta el minuto siguiente).
     *
     * @group asistente-whatsapp
     * @test
     */
    public function un_error_de_base_al_reclamar_no_avanza_la_tarea()
    {
        $tareas = $this->tareas_de_las_diez_y_uno(1);

        $registro = $this->fallar_el_reclamo_con_un_error_de_base();

        $this->despertar();

        $fila = $this->fila_de($tareas[0]->id);

        $this->assertSame('2026-11-21 10:01:00', $fila->proxima_ejecucion_at, 'La ocurrencia tiene que seguir pendiente.');
        $this->assertSame('activa', $fila->estado);
        $this->assertSame(0, (int) $fila->ejecuciones);
        $this->assertSame([], $this->ejecuciones($tareas[0]->id));
        Queue::assertNothingPushed();

        $texto = implode("\n", $registro->getArrayCopy());

        $this->assertStringContainsString('Lock wait timeout exceeded', $texto, 'El error de base tiene que quedar en el Log.');
    }

    /**
     * 🔴 E4. Lo mismo al OMITIR una ocurrencia vencida (hace más de 3 horas): si el error no es el duplicado, la
     * tarea no avanza ni se termina.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function un_error_de_base_al_omitir_no_termina_la_tarea()
    {
        $tarea = $this->tarea_del_asistente([
            'fecha_inicio'         => '2026-11-21',
            'hora'                 => '05:00',
            'franja'               => null,
            'proxima_ejecucion_at' => '2026-11-21 05:00:00',
        ]);

        $registro = $this->fallar_el_reclamo_con_un_error_de_base();

        $this->despertar();

        $fila = $this->fila_de($tarea->id);

        $this->assertSame('2026-11-21 05:00:00', $fila->proxima_ejecucion_at, 'La tarea de una vez no puede terminar por un error de base.');
        $this->assertSame('activa', $fila->estado);
        $this->assertStringContainsString('Lock wait timeout exceeded', implode("\n", $registro->getArrayCopy()));
    }

    /**
     * 🔴 E4. El caché estático de `hay_tablas` no deja un «false» pegado: un worker vivo que arrancó antes de
     * las migraciones tiene que ver las tablas apenas existen. Sólo se cachea el «true».
     *
     * @group asistente-whatsapp
     * @test
     */
    public function hay_tablas_no_deja_un_false_pegado()
    {
        $propiedad = new \ReflectionProperty(TareasDelAsistenteIaHelper::class, 'hay_tablas');
        $propiedad->setAccessible(true);

        try {
            // El estado en que quedaría un worker que preguntó antes de que existieran las tablas.
            $propiedad->setValue(null, false);

            $this->assertTrue(TareasDelAsistenteIaHelper::hay_tablas(), 'Las tablas existen: no puede responder el false viejo.');
        } finally {
            $propiedad->setValue(null, null);
        }
    }
}
