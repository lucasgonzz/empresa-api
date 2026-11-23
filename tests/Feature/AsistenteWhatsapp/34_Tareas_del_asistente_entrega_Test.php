<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Models\AiMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Misión agente-tareas-programadas (21/11/2026) — EL RESULTADO LE LLEGA AL DUEÑO POR WHATSAPP.
 *
 * Pedido de Lucas: «todos los días lo genera y le hace llegar por WhatsApp ese aviso». El resultado del turno
 * viaja por el carril de los recordatorios, que el admin de producción ya consulta cada 5 minutos y ya sabe
 * mandar (texto con la ventana de 24 h abierta, plantilla con la ventana cerrada), sin rutas nuevas. Lo que
 * protege este archivo (CA13, CA14 y CA18 del plan):
 *   1. una ejecución `lista` sale como un ítem más de `recordatorios-pendientes` con la forma que lee el admin
 *      y, sellada con `recordatorios/avisado`, queda `entregada` UNA vez, sin duplicar el mensaje de la
 *      conversación; lo ajeno o desconocido da 404;
 *   2. lo que nadie sella en 3 horas deja de ofrecerse y el comando lo marca `no_entregada` (sigue en la
 *      conversación);
 *   3. el ítem pasa la normalización del admin de HOY (empresa nueva con admin viejo) y sin tareas del asistente
 *      la respuesta es la de siempre.
 *
 * 🔴 Los endpoints admin-sync son los reales; el turno se arma con el comando real y se termina a mano (el
 * modelo no corre acá). Cola fakeada y reloj fijo (TareasDelAsistenteTestCase).
 *
 * PHP 7.4: sin match, sin ?->, sin str_contains, sin argumentos nombrados.
 */
class Tareas_del_asistente_entrega_Test extends TareasDelAsistenteTestCase
{
    /** Lo que contestó el asistente (sin formato, para comparar tal cual). */
    const RESULTADO = 'Hola, ¿cómo vienen las preguntas de logística? Te quedaban dos sin responder.';

    /** El prefijo del id de un ítem de tarea del asistente en el carril de recordatorios. */
    const PREFIJO = 'recordatorio:tarea_asistente:';

    /**
     * Los ítems de tarea del asistente de recordatorios-pendientes.
     *
     * @return array<int,array>
     */
    protected function items_de_tareas()
    {
        $respuesta = $this->pendientes();

        $respuesta->assertStatus(200);

        return array_values(array_filter($respuesta->json('recordatorios'), function ($item) {
            return strpos((string) $item['id'], self::PREFIJO) === 0;
        }));
    }

    /**
     * La tarea de las 17:00 despertada a las 16:58, su turno terminado con RESULTADO y cerrada como `lista` por
     * el barrido de las 17:01 (de ahí cuentan las 3 horas de gracia). Deja el reloj en las 17:01.
     *
     * @return array{tarea: \App\Models\AiTareaDelAsistente, ejecucion: object, assistant: \App\Models\AiMessage}
     */
    protected function ejecucion_lista()
    {
        $tarea = $this->tarea_del_asistente();

        $turno = $this->turno_listo($tarea, '2026-11-27 16:58:00', self::RESULTADO);

        $this->ahora('2026-11-27 17:01:00');

        $this->despertar();

        $ejecucion = $this->ejecuciones($tarea->id)[0];

        $this->assertSame('lista', $ejecucion->estado, 'El turno terminado tiene que quedar lista para entregar.');

        return ['tarea' => $tarea, 'ejecucion' => $ejecucion, 'assistant' => $turno['assistant']];
    }

    /**
     * 🔴 CA13. Una ejecución lista sale por recordatorios-pendientes con la forma del contrato; sellarla con
     * avisado la deja entregada una sola vez (el wamid también en el mensaje del turno, sin otro mensaje en la
     * conversación); un segundo sello no cambia nada; ya no se lista; un id de otro dueño o inventado da 404.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function sale_por_recordatorios_y_se_sella_una_vez()
    {
        $lista = $this->ejecucion_lista();

        $items = $this->items_de_tareas();

        $this->assertCount(1, $items, 'La ejecución lista tiene que salir como un ítem de recordatorios-pendientes.');

        $item = $items[0];

        $this->assertSame(self::PREFIJO . $lista['ejecucion']->id . '-' . Carbon::parse($lista['ejecucion']->created_at)->timestamp, $item['id']);
        $this->assertNull($item['tarea_id']);
        $this->assertSame('2026-11-27', $item['fecha']);
        $this->assertSame('17:00', $item['hora']);
        $this->assertStringStartsWith('que hoy te escriba por «' . self::TITULO . '»', $item['detalle']);
        $this->assertSame(self::RESULTADO, $item['texto']);
        $this->assertSame((int) $lista['ejecucion']->ai_conversation_id, (int) $item['ai_conversation_id']);
        $this->assertSame('', $item['ruta']);
        $this->assertSame('tarea_del_asistente', $item['plantilla']);
        $this->assertSame('«' . self::TITULO . '»', $item['resumen']);

        $mensajes_antes = AiMessage::where('ai_conversation_id', $lista['ejecucion']->ai_conversation_id)->count();

        // El admin manda el WhatsApp y sella.
        $this->avisado($item['id'], 'wamid.T1')->assertStatus(200)->assertJson(['ok' => true]);

        $ejecucion = DB::table('ai_tareas_del_asistente_ejecuciones')->where('id', $lista['ejecucion']->id)->first();

        $this->assertSame('entregada', $ejecucion->estado);
        $this->assertSame('2026-11-27 17:01:00', $ejecucion->entregada_at);
        $this->assertSame('wamid.T1', $ejecucion->whatsapp_message_id);
        $this->assertSame('wamid.T1', $lista['assistant']->fresh()->whatsapp_message_id);
        $this->assertSame($mensajes_antes, AiMessage::where('ai_conversation_id', $lista['ejecucion']->ai_conversation_id)->count(), 'El resultado ya estaba en la conversación: no se crea otro mensaje.');

        // Un segundo sello (el admin reintenta): 200 y nada cambia.
        $this->ahora('2026-11-27 17:05:00');

        $this->avisado($item['id'], 'wamid.T2')->assertStatus(200)->assertJson(['ok' => true]);

        $ejecucion = DB::table('ai_tareas_del_asistente_ejecuciones')->where('id', $lista['ejecucion']->id)->first();

        $this->assertSame('2026-11-27 17:01:00', $ejecucion->entregada_at);
        $this->assertSame('wamid.T1', $ejecucion->whatsapp_message_id);
        $this->assertSame($mensajes_antes, AiMessage::where('ai_conversation_id', $lista['ejecucion']->ai_conversation_id)->count());

        // Entregada: ya no se ofrece.
        $this->assertSame([], $this->items_de_tareas());

        // Lo ajeno o desconocido, 404.
        $otro = $this->otro_dueno();
        $de_otro = $this->tarea_del_asistente(['owner_id' => $otro->id, 'auth_user_id' => $otro->id]);

        $ajena = DB::table('ai_tareas_del_asistente_ejecuciones')->insertGetId([
            'tarea_id'      => $de_otro->id,
            'owner_id'      => $otro->id,
            'ocurrencia_at' => '2026-11-27 17:00:00',
            'estado'        => 'lista',
            'texto'         => 'De otro negocio',
            'created_at'    => Carbon::now(),
            'updated_at'    => Carbon::now(),
        ]);

        $this->avisado(self::PREFIJO . $ajena . '-' . Carbon::now()->timestamp, 'wamid.T3')->assertStatus(404);
        $this->avisado(self::PREFIJO . '999999999-' . Carbon::now()->timestamp, 'wamid.T4')->assertStatus(404);
        $this->avisado(self::PREFIJO . 'no-parsea', 'wamid.T5')->assertStatus(404);

        $this->assertSame('lista', DB::table('ai_tareas_del_asistente_ejecuciones')->where('id', $ajena)->value('estado'), 'Lo ajeno no se toca.');
    }

    /**
     * 🔴 CA14. Una ejecución lista que nadie selló no se ofrece pasadas las 3 horas, y el comando la marca
     * `no_entregada`; el mensaje sigue en la conversación.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function vence_a_las_tres_horas()
    {
        $lista = $this->ejecucion_lista();

        // Un poco antes de las 3 horas (desde las 17:01) todavía se ofrece.
        $this->ahora('2026-11-27 19:58:00');

        $this->assertCount(1, $this->items_de_tareas(), 'A menos de 3 horas sigue ofreciéndose.');

        // Pasadas las 3 horas, no.
        $this->ahora('2026-11-27 20:02:00');

        $this->assertSame([], $this->items_de_tareas(), 'Pasadas las 3 horas no se ofrece.');

        $this->despertar();

        $ejecucion = DB::table('ai_tareas_del_asistente_ejecuciones')->where('id', $lista['ejecucion']->id)->first();

        $this->assertSame('no_entregada', $ejecucion->estado);
        $this->assertNull($ejecucion->entregada_at);

        $en_la_conversacion = AiMessage::find($lista['assistant']->id);

        $this->assertNotNull($en_la_conversacion, 'El mensaje sigue en la conversación.');
        $this->assertSame(self::RESULTADO, $en_la_conversacion->contenido);
        $this->assertSame('listo', $en_la_conversacion->estado);
    }

    /**
     * 🔴 CA18. Empresa nueva con el admin de hoy: el ítem pasa la normalización de `traer_recordatorios()`
     * (id y texto no vacíos, fecha y hora con su patrón, detalle no vacío y con « (hoy HH:MM)» menor a 600) y
     * trae exactamente las claves que el admin lee más las dos opcionales.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function forma_compatible_con_el_admin_de_hoy()
    {
        $this->ejecucion_lista();

        $items = $this->items_de_tareas();

        $this->assertCount(1, $items);

        $item = $items[0];

        $this->assertNotSame('', trim((string) $item['id']));
        $this->assertLessThanOrEqual(80, strlen($item['id']));
        $this->assertNotSame('', trim((string) $item['texto']));
        $this->assertLessThanOrEqual(3500, mb_strlen($item['texto']));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $item['fecha']);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $item['hora']);
        $this->assertNotSame('', trim((string) $item['detalle']));
        $this->assertLessThanOrEqual(300, mb_strlen($item['detalle']));
        $this->assertLessThan(600, mb_strlen($item['detalle'] . ' (hoy ' . $item['hora'] . ')'));

        $claves = array_keys($item);
        sort($claves);

        $this->assertSame(
            ['ai_conversation_id', 'detalle', 'fecha', 'hora', 'id', 'plantilla', 'resumen', 'ruta', 'tarea_id', 'texto'],
            $claves,
            'Las claves que lee el admin de hoy más plantilla y resumen (opcionales).'
        );
    }

    /**
     * 🔴 CA18. Sin ejecuciones de tareas del asistente la respuesta es la de siempre: con el recordatorio de la
     * Agenda de la suite de recordatorios, la lista trae sólo ese ítem, con las ocho claves de hoy.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function sin_tareas_del_asistente_todo_sigue_igual()
    {
        $this->ahora(self::AHORA);

        $recordatorio = $this->tarea();

        // Con la tabla de tareas del asistente existiendo y vacía, el contrato es el mismo que sin ella.
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasTable('ai_tareas_del_asistente_ejecuciones'),
            'Falta la tabla de ejecuciones de las tareas del asistente.'
        );

        $respuesta = $this->pendientes();

        $respuesta->assertStatus(200);

        $this->assertCount(1, $respuesta->json('recordatorios'));
        $this->assertSame((int) $recordatorio->id, (int) $respuesta->json('recordatorios.0.tarea_id'));

        $claves = array_keys($respuesta->json('recordatorios.0'));
        sort($claves);

        $this->assertSame(['ai_conversation_id', 'detalle', 'fecha', 'hora', 'id', 'ruta', 'tarea_id', 'texto'], $claves);
        $this->assertSame([], $this->items_de_tareas());
    }
}
