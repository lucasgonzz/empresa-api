<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\asistente_ia\DespertadorDeTareasIaHelper;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiTareaDelAsistenteEjecucion;
use App\Models\AiTokenUsage;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Misión agente-tareas-programadas (21/11/2026) — EL ASISTENTE SE DESPIERTA A LA HORA.
 *
 * Pedido de Lucas: «a tal momento del día se despierta el agente, le vuelve a preguntar. […] No sé si se puede
 * configurar con algún cron en el VPS». Lo que protege este archivo (CA3 avance, CA7, CA8, CA9, CA10, CA12 y
 * CA20 del plan): el comando `asistente:despertar-tareas`, que el Kernel agenda cada minuto,
 *   1. despierta la tarea a su hora (con 2 minutos de anticipo): arma el turno desatendido —el mensaje
 *      «[Tarea programada] …», el `assistant` pendiente SIN acciones— y despacha el job, una sola vez;
 *   2. avanza la recurrente a su próxima ocurrencia (o la termina) y respeta la zona horaria de la app;
 *   3. no se pisa a sí mismo: dos barridos, o dos procesos con la fila vieja, dan una ejecución;
 *   4. se frena solo: tope diario, tope del plan, ocurrencias de un servidor caído, un dueño que está
 *      escribiendo, un negocio sin Agentes de IA;
 *   5. cierra los turnos que despertó (lista, fallida, colgada) y pausa la tarea que falla tres veces seguidas;
 *   6. está agendado cada minuto y sólo arranca artisan si hay algo para hacer.
 *
 * 🔴 El comando corre de verdad contra la base; la cola está fakeada (no se llama al modelo) y el reloj fijo.
 *
 * PHP 7.4: sin match, sin ?->, sin str_contains, sin argumentos nombrados.
 */
class Tareas_del_asistente_despertar_Test extends TareasDelAsistenteTestCase
{
    /** Un viernes a las 16:58: dentro del anticipo de 2 minutos de la tarea de las 17:00. */
    const VIERNES_16_58 = '2026-11-27 16:58:00';

    /**
     * Las conversaciones de WhatsApp del dueño.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function conversaciones_de_whatsapp()
    {
        return AiConversation::where('user_id', $this->comercio->id)->where('origen', AiConversation::ORIGEN_WHATSAPP)->orderBy('id')->get();
    }

    /**
     * 🔴 CA7. A las 16:57 no pasa nada; a las 16:58 (anticipo de 2 minutos) se arma la ejecución de las 17:00,
     * el turno desatendido y el job; la tarea de una sola vez queda terminada. Sin conversación vigente crea una
     * de WhatsApp titulada «Tarea: …».
     *
     * @group asistente-whatsapp
     * @test
     */
    public function despierta_a_la_hora_con_anticipo()
    {
        $tarea = $this->tarea_del_asistente();

        $this->ahora('2026-11-27 16:57:00');

        $this->despertar();

        $this->assertSame([], $this->ejecuciones(), 'A las 16:57 todavía no es hora (el anticipo es de 2 minutos).');
        Queue::assertNothingPushed();

        $this->ahora(self::VIERNES_16_58);

        $salida = $this->despertar();

        $this->assertSame(0, $salida['codigo'], $salida['salida']);

        $ejecuciones = $this->ejecuciones($tarea->id);

        $this->assertCount(1, $ejecuciones);
        $this->assertSame('en_curso', $ejecuciones[0]->estado);
        $this->assertSame('2026-11-27 17:00:00', $ejecuciones[0]->ocurrencia_at);

        $conversaciones = $this->conversaciones_de_whatsapp();

        $this->assertCount(1, $conversaciones, 'Sin conversación vigente se crea una de WhatsApp.');
        $this->assertSame('Tarea: ' . self::TITULO, $conversaciones[0]->titulo);
        $this->assertSame((int) $conversaciones[0]->id, (int) $ejecuciones[0]->ai_conversation_id);

        $mensajes = AiMessage::where('ai_conversation_id', $conversaciones[0]->id)->orderBy('id')->get();

        $this->assertCount(2, $mensajes);

        $pedido = $mensajes[0];
        $this->assertSame('user', $pedido->rol);
        $this->assertSame('tarea_programada', $pedido->tipo);
        $this->assertSame('whatsapp', $pedido->canal);
        $this->assertStringStartsWith('[Tarea programada] ' . self::TITULO . ':', $pedido->contenido);
        $this->assertStringContainsString(self::INSTRUCCION, $pedido->contenido);

        $respuesta = $mensajes[1];
        $this->assertSame('assistant', $respuesta->rol);
        $this->assertSame('pendiente', $respuesta->estado);
        $this->assertSame('whatsapp', $respuesta->canal);
        $this->assertFalse((bool) $respuesta->acciones_habilitadas, 'El turno desatendido no puede cargar nada.');
        $this->assertSame((int) $respuesta->id, (int) $ejecuciones[0]->ai_message_id);

        Queue::assertPushed(ResponderMensajeChatIaJob::class, 1);

        $fila = $this->fila_de($tarea->id);

        $this->assertSame('terminada', $fila->estado);
        $this->assertNull($fila->proxima_ejecucion_at);
        $this->assertSame(1, (int) $fila->ejecuciones);
        $this->assertSame('2026-11-27 17:00:00', $fila->ultima_ejecucion_at);
    }

    /**
     * 🔴 CA7. Con una conversación de WhatsApp vigente (menos de 6 horas) el turno va en esa, no en una nueva.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function usa_la_conversacion_vigente()
    {
        $tarea = $this->tarea_del_asistente();

        $this->ahora(self::VIERNES_16_58);

        $vigente = $this->conversacion_whatsapp(Carbon::now()->subHours(2));

        $this->despertar();

        $ejecuciones = $this->ejecuciones($tarea->id);

        $this->assertCount(1, $ejecuciones, 'El comando no despertó la tarea.');
        $this->assertSame((int) $vigente->id, (int) $ejecuciones[0]->ai_conversation_id);
        $this->assertCount(1, $this->conversaciones_de_whatsapp(), 'No se crea otra conversación.');
        $this->assertSame(2, AiMessage::where('ai_conversation_id', $vigente->id)->count());
    }

    /**
     * 🔴 CA3 (avance). La diaria pasa a la ocurrencia siguiente; lunes-miércoles-viernes salta al miércoles; y
     * una diaria con fecha de fin termina después de la última.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function avanza_la_recurrente()
    {
        $diaria = $this->tarea_del_asistente([
            'titulo'               => 'Ventas',
            'recurrencia'          => 'diaria',
            'hora'                 => '09:00',
            'franja'               => 'manana',
            'fecha_inicio'         => '2026-11-22',
            'proxima_ejecucion_at' => '2026-11-22 09:00:00',
        ]);

        $this->ahora('2026-11-22 08:58:00');

        $this->despertar();

        $fila = $this->fila_de($diaria->id);

        $this->assertCount(1, $this->ejecuciones($diaria->id));
        $this->assertSame('2026-11-23 09:00:00', $fila->proxima_ejecucion_at);
        $this->assertSame('activa', $fila->estado);
        $this->assertSame(1, (int) $fila->ejecuciones);
        $this->assertSame('2026-11-22 09:00:00', $fila->ultima_ejecucion_at);

        // Lunes-miércoles-viernes a las 08:30: el lunes 23/11 corre y la próxima es el miércoles 25/11.
        $semana = $this->tarea_del_asistente([
            'titulo'               => 'Pedidos',
            'recurrencia'          => 'dias_semana',
            'dias'                 => '1,3,5',
            'hora'                 => '08:30',
            'franja'               => null,
            'fecha_inicio'         => '2026-11-23',
            'proxima_ejecucion_at' => '2026-11-23 08:30:00',
        ]);

        $this->ahora('2026-11-23 08:28:00');

        $this->despertar();

        $this->assertCount(1, $this->ejecuciones($semana->id));
        $this->assertSame('2026-11-25 08:30:00', $this->fila_de($semana->id)->proxima_ejecucion_at);
        $this->assertSame('activa', $this->fila_de($semana->id)->estado);

        // Una diaria hasta el 24/11: la ocurrencia del 24 es la última y la tarea termina.
        $con_fin = $this->tarea_del_asistente([
            'titulo'               => 'Hasta el martes',
            'recurrencia'          => 'diaria',
            'hora'                 => '10:00',
            'franja'               => null,
            'fecha_inicio'         => '2026-11-24',
            'fecha_fin'            => '2026-11-24',
            'proxima_ejecucion_at' => '2026-11-24 10:00:00',
        ]);

        $this->ahora('2026-11-24 09:58:00');

        $this->despertar();

        $fila_con_fin = $this->fila_de($con_fin->id);

        $this->assertCount(1, $this->ejecuciones($con_fin->id));
        $this->assertSame('terminada', $fila_con_fin->estado);
        $this->assertNull($fila_con_fin->proxima_ejecucion_at);
    }

    /**
     * 🔴 CA8. Dos barridos en el mismo minuto dejan una ejecución, un job y un par de mensajes; insertar a mano
     * otra ejecución con el mismo (tarea, ocurrencia) lo rechaza la base (unique), sea cual sea el proceso.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function dos_barridos_una_ejecucion()
    {
        $tarea = $this->tarea_del_asistente();

        $this->ahora(self::VIERNES_16_58);

        $this->despertar();
        $this->despertar();

        $this->assertCount(1, $this->ejecuciones($tarea->id), 'Dos barridos no pueden dar dos ejecuciones.');
        Queue::assertPushed(ResponderMensajeChatIaJob::class, 1);
        $this->assertSame(1, AiMessage::where('tipo', 'tarea_programada')->count());
        $this->assertSame(2, AiMessage::whereIn('ai_conversation_id', $this->conversaciones_de_whatsapp()->pluck('id')->all())->count());

        // El unique (tarea_id, ocurrencia_at) es la garantía de fondo.
        $duplicada = false;

        try {
            AiTareaDelAsistenteEjecucion::create([
                'tarea_id'      => $tarea->id,
                'owner_id'      => $this->comercio->id,
                'ocurrencia_at' => '2026-11-27 17:00:00',
                'estado'        => 'en_curso',
            ]);
        } catch (QueryException $e) {
            $duplicada = true;
        }

        $this->assertTrue($duplicada, 'La base tiene que rechazar una segunda ejecución de la misma ocurrencia.');
        $this->assertCount(1, $this->ejecuciones($tarea->id));
    }

    /**
     * 🔴 CA8. Dos llamadas al despertador sobre la misma tarea, y un segundo proceso que todavía ve la fila
     * como antes de que el primero la avanzara (dos frentes sobre la misma base, cron solapado): una sola
     * ejecución, un solo job.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function un_segundo_proceso_con_la_fila_vieja_no_duplica()
    {
        $tarea = $this->tarea_del_asistente();

        $this->ahora(self::VIERNES_16_58);

        DespertadorDeTareasIaHelper::despertar($this->comercio);
        DespertadorDeTareasIaHelper::despertar($this->comercio);

        $this->assertCount(1, $this->ejecuciones($tarea->id));

        // El segundo proceso había leído la fila ANTES de que el primero la avanzara: se la devolvemos así.
        DB::table('ai_tareas_del_asistente')->where('id', $tarea->id)->update([
            'estado'               => 'activa',
            'proxima_ejecucion_at' => '2026-11-27 17:00:00',
            'ejecuciones'          => 0,
        ]);

        DespertadorDeTareasIaHelper::despertar($this->comercio);

        $this->assertCount(1, $this->ejecuciones($tarea->id), 'La base frena al segundo proceso.');
        Queue::assertPushed(ResponderMensajeChatIaJob::class, 1);
        $this->assertSame(1, AiMessage::where('tipo', 'tarea_programada')->count());
    }

    /**
     * 🔴 CA9. La zona horaria es la de la app: a las 11:57 UTC (08:57 en Argentina) la diaria de las 09:00 no
     * corre; a las 11:58 UTC (08:58, dentro del anticipo) corre y su ocurrencia queda guardada a las 09:00 locales.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function zona_horaria()
    {
        $diaria = $this->tarea_del_asistente([
            'titulo'               => 'Ventas',
            'recurrencia'          => 'diaria',
            'hora'                 => '09:00',
            'franja'               => 'manana',
            'fecha_inicio'         => '2026-11-22',
            'proxima_ejecucion_at' => '2026-11-22 09:00:00',
        ]);

        $this->ahora(Carbon::create(2026, 11, 22, 11, 57, 0, 'UTC'));

        $this->despertar();

        $this->assertSame([], $this->ejecuciones($diaria->id), 'A las 08:57 de Argentina todavía no es hora.');

        $this->ahora(Carbon::create(2026, 11, 22, 11, 58, 0, 'UTC'));

        $this->despertar();

        $ejecuciones = $this->ejecuciones($diaria->id);

        $this->assertCount(1, $ejecuciones, 'A las 08:58 de Argentina la diaria de las 09:00 tiene que correr.');
        $this->assertSame('2026-11-22 09:00:00', $ejecuciones[0]->ocurrencia_at);
    }

    /**
     * 🔴 CA10. Los topes: con 6 ejecuciones no omitidas en el día la séptima queda omitida por tope diario (sin
     * job) y la tarea avanza; con el tope de interacciones del plan superado queda omitida por tope del plan.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function topes_diario_y_del_plan()
    {
        $this->ahora('2026-11-22 08:58:00');

        // Seis ejecuciones de hoy de otra tarea: el tope diario es 6 turnos programados.
        $otra = $this->tarea_del_asistente(['titulo' => 'Otra', 'estado' => 'terminada', 'proxima_ejecucion_at' => null]);

        for ($hora = 1; $hora <= 6; $hora++) {
            AiTareaDelAsistenteEjecucion::create([
                'tarea_id'      => $otra->id,
                'owner_id'      => $this->comercio->id,
                'ocurrencia_at' => '2026-11-22 0' . $hora . ':00:00',
                'estado'        => 'lista',
            ]);
        }

        $diaria = $this->tarea_del_asistente([
            'titulo'               => 'Ventas',
            'recurrencia'          => 'diaria',
            'hora'                 => '09:00',
            'franja'               => 'manana',
            'fecha_inicio'         => '2026-11-22',
            'proxima_ejecucion_at' => '2026-11-22 09:00:00',
        ]);

        $this->despertar();

        $ejecuciones = $this->ejecuciones($diaria->id);

        $this->assertCount(1, $ejecuciones);
        $this->assertSame('omitida', $ejecuciones[0]->estado);
        $this->assertSame('tope_diario', $ejecuciones[0]->motivo);
        Queue::assertNothingPushed();
        $this->assertSame('2026-11-23 09:00:00', $this->fila_de($diaria->id)->proxima_ejecucion_at, 'La tarea sigue con su próxima ocurrencia.');

        // El tope del plan: una interacción por día y ya hay una hoy.
        $this->ahora('2026-11-23 08:58:00');

        $this->comercio->plan_ia_tope_interacciones_diarias = 1;
        $this->comercio->save();

        $conversacion = $this->conversacion_whatsapp();
        $respondido = $this->mensaje($conversacion, 'assistant', 'listo', ['contenido' => 'Una respuesta de hoy.']);

        AiTokenUsage::create([
            'user_id'                     => $this->comercio->id,
            'auth_user_id'                => $this->comercio->id,
            'proceso'                     => 'chat_mensaje',
            'proveedor'                   => 'anthropic',
            'modelo'                      => 'claude-sonnet-5',
            'input_tokens'                => 1,
            'output_tokens'               => 1,
            'cache_creation_input_tokens' => 0,
            'cache_read_input_tokens'     => 0,
            'ai_conversation_id'          => $conversacion->id,
            'referencia_id'               => $respondido->id,
            'created_at'                  => Carbon::now(),
            'updated_at'                  => Carbon::now(),
        ]);

        DB::table('ai_tareas_del_asistente_ejecuciones')->where('tarea_id', $otra->id)->delete();

        $this->despertar();

        $del_plan = array_values(array_filter($this->ejecuciones($diaria->id), function ($ejecucion) {
            return $ejecucion->ocurrencia_at === '2026-11-23 09:00:00';
        }));

        $this->assertCount(1, $del_plan);
        $this->assertSame('omitida', $del_plan[0]->estado);
        $this->assertSame('tope_del_plan', $del_plan[0]->motivo);
        Queue::assertNothingPushed();
    }

    /**
     * 🔴 CA10. Una ocurrencia de hace 4 horas (el servidor estuvo caído) no se «pone al día»: queda omitida por
     * vencida, sin job, y la tarea avanza a la siguiente futura.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function la_ocurrencia_vencida_se_omite()
    {
        $diaria = $this->tarea_del_asistente([
            'titulo'               => 'Ventas',
            'recurrencia'          => 'diaria',
            'hora'                 => '09:00',
            'franja'               => 'manana',
            'fecha_inicio'         => '2026-11-22',
            'proxima_ejecucion_at' => '2026-11-22 09:00:00',
        ]);

        $this->ahora('2026-11-22 13:00:00');

        $this->despertar();

        $ejecuciones = $this->ejecuciones($diaria->id);

        $this->assertCount(1, $ejecuciones);
        $this->assertSame('omitida', $ejecuciones[0]->estado);
        $this->assertSame('vencida', $ejecuciones[0]->motivo);
        $this->assertSame('2026-11-22 09:00:00', $ejecuciones[0]->ocurrencia_at);
        Queue::assertNothingPushed();
        $this->assertSame('2026-11-23 09:00:00', $this->fila_de($diaria->id)->proxima_ejecucion_at);
        $this->assertSame('activa', $this->fila_de($diaria->id)->estado);
    }

    /**
     * 🔴 CA10. No interrumpe al dueño: con un mensaje suyo de hace 3 minutos no reclama la tarea (se reintenta el
     * minuto siguiente); una hora después de la ocurrencia, ya no espera más y corre.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function no_interrumpe_al_dueno_que_esta_escribiendo()
    {
        $tarea = $this->tarea_del_asistente();

        // El dueño escribió hace 3 minutos, en otra conversación suya.
        $this->ahora('2026-11-27 16:57:00');

        $conversacion = $this->conversacion_whatsapp();
        $this->mensaje($conversacion, 'user', 'listo', ['contenido' => 'Che, ¿cuánto vendimos hoy?']);

        $this->ahora('2026-11-27 17:00:00');

        $this->despertar();

        $this->assertSame([], $this->ejecuciones($tarea->id), 'No se despierta al asistente en medio de la charla del dueño.');
        Queue::assertNothingPushed();
        $this->assertSame('2026-11-27 17:00:00', $this->fila_de($tarea->id)->proxima_ejecucion_at, 'La tarea queda para el minuto siguiente.');
        $this->assertSame('activa', $this->fila_de($tarea->id)->estado);

        // A la ocurrencia + 61 minutos deja de esperar, aunque el dueño siga escribiendo.
        $this->ahora('2026-11-27 17:58:00');
        $this->mensaje($conversacion, 'user', 'listo', ['contenido' => 'Y de ayer?']);

        $this->ahora('2026-11-27 18:01:00');

        $this->despertar();

        $this->assertCount(1, $this->ejecuciones($tarea->id), 'Pasada la hora de espera, corre igual.');
        Queue::assertPushed(ResponderMensajeChatIaJob::class, 1);
    }

    /**
     * 🔴 CA10. Un negocio sin Agentes de IA no despierta nada: el comando sale en silencio y no crea ni
     * ejecuciones ni mensajes.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function sin_agentes_de_ia_no_despierta_nada()
    {
        $tarea = $this->tarea_del_asistente();

        $this->comercio->extencions()->detach();
        $this->comercio->load('extencions');

        $this->ahora(self::VIERNES_16_58);

        $salida = $this->despertar();

        $this->assertSame(0, $salida['codigo'], $salida['salida']);
        $this->assertSame([], $this->ejecuciones($tarea->id));
        $this->assertSame(0, AiMessage::where('tipo', 'tarea_programada')->count());
        Queue::assertNothingPushed();
        $this->assertSame('2026-11-27 17:00:00', $this->fila_de($tarea->id)->proxima_ejecucion_at);
    }

    /**
     * 🔴 CA12. El cierre de los turnos que despertó: `listo` → lista con el texto (recortado a 3.500), `error` →
     * fallida y suma un fallo, `pendiente` de más de 15 minutos → fallida por colgada, y una pendiente reciente
     * sigue en curso. Con tres fallos seguidos la tarea queda pausada; una lista pone los fallos en cero.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function cierra_lista_fallida_colgada()
    {
        $diaria = ['recurrencia' => 'diaria', 'hora' => '09:00', 'franja' => 'manana', 'fecha_inicio' => '2026-11-21', 'proxima_ejecucion_at' => '2026-11-23 09:00:00'];

        $t_lista = $this->tarea_del_asistente(array_merge($diaria, ['titulo' => 'Lista', 'fallos_seguidos' => 2]));
        $t_larga = $this->tarea_del_asistente(array_merge($diaria, ['titulo' => 'Larga']));
        $t_error = $this->tarea_del_asistente(array_merge($diaria, ['titulo' => 'Con error']));
        $t_colgada = $this->tarea_del_asistente(array_merge($diaria, ['titulo' => 'Colgada']));
        $t_reciente = $this->tarea_del_asistente(array_merge($diaria, ['titulo' => 'Reciente']));
        $t_tercera = $this->tarea_del_asistente(array_merge($diaria, ['titulo' => 'Tercera falla', 'fallos_seguidos' => 2]));

        $lista = $this->ejecucion_en_curso($t_lista, 'listo', 'Todo en orden con la logística.', 2);
        $larga = $this->ejecucion_en_curso($t_larga, 'listo', str_repeat('Informe largo. ', 400), 2);
        $con_error = $this->ejecucion_en_curso($t_error, 'error', null, 2);
        $colgada = $this->ejecucion_en_curso($t_colgada, 'pendiente', null, 16);
        $reciente = $this->ejecucion_en_curso($t_reciente, 'pendiente', null, 5);
        $tercera = $this->ejecucion_en_curso($t_tercera, 'error', null, 2);

        $this->despertar();

        $fila = function ($ejecucion_id) {
            return DB::table('ai_tareas_del_asistente_ejecuciones')->where('id', $ejecucion_id)->first();
        };

        // listo → lista, con el texto tal cual, y los fallos vuelven a cero.
        $this->assertSame('lista', $fila($lista['ejecucion'])->estado);
        $this->assertSame('Todo en orden con la logística.', $fila($lista['ejecucion'])->texto);
        $this->assertSame(0, (int) $this->fila_de($t_lista->id)->fallos_seguidos);

        // listo y larguísimo → lista, con el texto recortado al tope del contrato.
        $this->assertSame('lista', $fila($larga['ejecucion'])->estado);
        $this->assertLessThanOrEqual(3500, mb_strlen($fila($larga['ejecucion'])->texto));
        $this->assertGreaterThan(3000, mb_strlen($fila($larga['ejecucion'])->texto), 'Se recorta al tope, no mucho antes.');
        $this->assertStringContainsString('(seguí en la app)', $fila($larga['ejecucion'])->texto);

        // error → fallida, motivo error, un fallo.
        $this->assertSame('fallida', $fila($con_error['ejecucion'])->estado);
        $this->assertSame('error', $fila($con_error['ejecucion'])->motivo);
        $this->assertSame(1, (int) $this->fila_de($t_error->id)->fallos_seguidos);
        $this->assertSame('activa', $this->fila_de($t_error->id)->estado);

        // pendiente de 16 minutos → fallida por colgada; la de 5 minutos sigue en curso.
        $this->assertSame('fallida', $fila($colgada['ejecucion'])->estado);
        $this->assertSame('colgada', $fila($colgada['ejecucion'])->motivo);
        $this->assertSame('en_curso', $fila($reciente['ejecucion'])->estado);
        $this->assertSame(0, (int) $this->fila_de($t_reciente->id)->fallos_seguidos);

        // Tres fallos seguidos → la tarea queda pausada.
        $this->assertSame('fallida', $fila($tercera['ejecucion'])->estado);
        $this->assertSame(3, (int) $this->fila_de($t_tercera->id)->fallos_seguidos);
        $this->assertSame('pausada', $this->fila_de($t_tercera->id)->estado);
    }

    /**
     * 🔴 CA20. El Kernel agenda `asistente:despertar-tareas` cada minuto cuando el dueño tiene Agentes de IA; su
     * `when` da false sin nada vencido ni por cerrar (no arranca artisan en balde) y true con una tarea
     * vencida o con un turno por cerrar. Sin Agentes de IA no se agenda.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function agenda_del_cron()
    {
        $evento = $this->evento_del_schedule();

        $this->assertNotNull($evento, 'asistente:despertar-tareas no está en el schedule.');
        $this->assertSame('* * * * *', $evento->expression);

        // Sin nada vencido ni por cerrar: no arranca.
        $this->assertFalse($evento->filtersPass($this->app), 'Sin tareas el gate tiene que dar false.');

        // Una tarea que vence dentro del anticipo: arranca.
        $tarea = $this->tarea_del_asistente(['proxima_ejecucion_at' => '2026-11-21 10:01:00']);

        $this->assertTrue($evento->filtersPass($this->app), 'Con una tarea vencida el gate tiene que dar true.');

        // Una tarea para dentro de una hora no cuenta.
        DB::table('ai_tareas_del_asistente')->where('id', $tarea->id)->update(['proxima_ejecucion_at' => '2026-11-21 11:00:00']);

        $this->assertFalse($evento->filtersPass($this->app));

        // Un turno por cerrar cuenta aunque no haya tareas vencidas.
        $this->ejecucion_en_curso($tarea, 'listo', 'Listo.', 2);

        $this->assertTrue($evento->filtersPass($this->app), 'Con un turno por cerrar el gate tiene que dar true.');

        // Sin Agentes de IA no se agenda.
        $this->comercio->extencions()->detach();

        $this->assertNull($this->evento_del_schedule(), 'Sin Agentes de IA el comando no se agenda.');
    }

    /**
     * El evento de `asistente:despertar-tareas` del schedule del Kernel, o null. Por reflexión, porque
     * schedule() es protected (mismo mecanismo que Infraestructura/5_Gates_del_schedule_Test): el schedule de la
     * aplicación se armó antes de que el test fijara el dueño de la instancia.
     *
     * @return \Illuminate\Console\Scheduling\Event|null
     */
    protected function evento_del_schedule()
    {
        $schedule = new Schedule();
        $kernel = $this->app->make(\App\Console\Kernel::class);
        $metodo = new \ReflectionMethod($kernel, 'schedule');
        $metodo->setAccessible(true);
        $metodo->invoke($kernel, $schedule);

        foreach ($schedule->events() as $evento) {
            if (strpos((string) $evento->command, 'asistente:despertar-tareas') !== false) {
                return $evento;
            }
        }

        return null;
    }
}
