<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\asistente_ia\RecordatoriosDelAsistenteHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\PendingCompleted;
use Carbon\Carbon;

/**
 * Misión recordatorios-whatsapp (16/10/2026) — el contrato con el admin: recordatorios-pendientes
 * (qué vence ahora, con la forma exacta del JSON, y el anuncio que deja la consulta) y
 * recordatorios/avisado (el sello por día, idempotente, sin mover `updated_at`, y el mensaje en la
 * conversación de WhatsApp para que «listo, ya lo hice» tenga contexto).
 *
 * 🔴 El reloj está fijo en el viernes 16/10/2026 a las 10:30 (ver RecordatoriosTestCase).
 */
class Recordatorios_por_whatsapp_Test extends RecordatoriosTestCase
{
    /* ─────────────────────── 4/5. recordatorios-pendientes ─────────────────────── */

    /**
     * @test
     */
    public function recordatorios_pendientes_sin_clave_da_401()
    {
        $this->pendientes('', false)->assertStatus(401);

        $this->postJson('api/admin-sync/asistente/recordatorios/avisado', ['id' => 'recordatorio:1-1:' . self::HOY . ':1000'], $this->headers(false))
            ->assertStatus(401);
    }

    /**
     * El caso del pedido: «recordame hoy a las 10 que tengo que llamar a Juan», consultado a las 10:30.
     *
     * @test
     */
    public function la_puntual_de_hoy_vencida_dentro_de_la_gracia_sale_con_la_forma_del_contrato()
    {
        $tarea = $this->tarea(['notas' => "Tiene el\npresupuesto de la semana pasada"]);

        $respuesta = $this->pendientes();

        $respuesta->assertStatus(200);

        $this->assertSame([[
            'id'                 => $this->id_de($tarea),
            'tarea_id'           => (int) $tarea->id,
            'fecha'              => self::HOY,
            'hora'               => '10:00',
            'detalle'            => 'Llamar a Juan por el presupuesto',
            'texto'              => "⏰ Te recuerdo: *Llamar a Juan por el presupuesto* (hoy 10:00).\nNotas: Tiene el presupuesto de la semana pasada\nCuando lo hagas, avisame y lo marco como hecho.",
            'ai_conversation_id' => null,
            'ruta'               => '/agenda',
        ]], $respuesta->json('recordatorios'));
    }

    /**
     * @test
     */
    public function con_una_conversacion_de_whatsapp_vigente_viaja_su_id()
    {
        $this->tarea();

        $conversacion = $this->conversacion_whatsapp(Carbon::parse(self::AHORA)->subHour());

        // Una vieja (más de 6 h) no cuenta como vigente.
        $this->conversacion_whatsapp(Carbon::parse(self::AHORA)->subHours(7));

        $this->assertSame((int) $conversacion->id, $this->pendientes()->json('recordatorios.0.ai_conversation_id'));
    }

    /**
     * @test
     */
    public function lo_que_no_vencio_o_ya_no_corresponde_no_sale()
    {
        $antes_de_la_hora = $this->tarea(['detalle' => '11:00 · Antes de la hora', 'hora_aviso' => '11:00:00']);
        $fuera_de_la_gracia = $this->tarea(['detalle' => '07:29 · Fuera de la gracia', 'hora_aviso' => '07:29:00'], '2026-10-16 06:00:00');
        $en_el_borde = $this->tarea(['detalle' => '07:30 · Justo en el borde', 'hora_aviso' => '07:30:00'], '2026-10-16 06:00:00');
        $completada = $this->tarea(['detalle' => '10:00 · Completada', 'completado' => 1]);
        $de_manana = $this->tarea(['detalle' => '10:00 · De mañana', 'fecha_realizacion' => '2026-10-17 00:00:00']);
        $de_ayer = $this->tarea(['detalle' => '10:00 · De ayer', 'fecha_realizacion' => '2026-10-15 00:00:00'], '2026-10-15 08:00:00');
        $creada_despues = $this->tarea(['detalle' => '10:00 · Creada después'], '2026-10-16 10:15:00');
        $avisada_hoy = $this->tarea(['detalle' => '10:00 · Avisada hoy', 'avisado_at' => '2026-10-16 10:02:00']);
        $sin_pedido = $this->tarea(['detalle' => '10:00 · Sin pedido de aviso', 'avisar_por_whatsapp' => 0]);
        $sin_hora = $this->tarea(['detalle' => 'Sin hora', 'hora_aviso' => null]);
        $ajena = $this->tarea(['detalle' => '10:00 · De otro comercio', 'user_id' => $this->otro_dueno()->id]);

        $tareas = $this->tareas_pendientes();

        $this->assertSame([(int) $en_el_borde->id], $tareas, 'Solo sale la del borde exacto de la gracia (07:30 a las 10:30).');

        unset($antes_de_la_hora, $fuera_de_la_gracia, $completada, $de_manana, $de_ayer, $creada_despues, $avisada_hoy, $sin_pedido, $sin_hora, $ajena);
    }

    /**
     * Una puntual hecha solo por su PendingCompleted (sin el flag) tampoco sale, como en la Agenda.
     *
     * @test
     */
    public function la_puntual_con_su_realizada_no_sale()
    {
        $tarea = $this->tarea();

        PendingCompleted::create([
            'pending_id'        => $tarea->id,
            'user_id'           => $this->comercio->id,
            'fecha_realizacion' => self::HOY . ' 00:00:00',
        ]);

        $this->assertSame([], $this->tareas_pendientes());
    }

    /**
     * @test
     */
    public function la_recurrente_sale_el_dia_que_le_toca_una_vez_por_dia()
    {
        $semana = $this->unidad('week');

        // Viernes 9/10 cada semana: hoy (viernes 16/10) le toca.
        $toca = $this->tarea([
            'detalle'              => '10:00 · Semanal que toca',
            'fecha_realizacion'    => '2026-10-09 00:00:00',
            'es_recurrente'        => 1,
            'unidad_frecuencia_id' => $semana->id,
            'cantidad_frecuencia'  => 1,
        ], '2026-10-09 08:00:00');

        // Sábado 10/10 cada semana: hoy no.
        $no_toca = $this->tarea([
            'detalle'              => '10:00 · Semanal que no toca',
            'fecha_realizacion'    => '2026-10-10 00:00:00',
            'es_recurrente'        => 1,
            'unidad_frecuencia_id' => $semana->id,
            'cantidad_frecuencia'  => 1,
        ], '2026-10-09 08:00:00');

        // Le tocaría, pero la recurrencia terminó ayer.
        $terminada = $this->tarea([
            'detalle'               => '10:00 · Semanal terminada',
            'fecha_realizacion'     => '2026-10-02 00:00:00',
            'es_recurrente'         => 1,
            'unidad_frecuencia_id'  => $semana->id,
            'cantidad_frecuencia'   => 1,
            'fecha_fin_recurrencia' => '2026-10-15',
        ], '2026-10-02 08:00:00');

        // Le toca y la avisamos AYER (otra ocurrencia): hoy sale de nuevo.
        $avisada_ayer = $this->tarea([
            'detalle'              => '10:00 · Diaria avisada ayer',
            'fecha_realizacion'    => '2026-10-01 00:00:00',
            'es_recurrente'        => 1,
            'unidad_frecuencia_id' => $this->unidad('day')->id,
            'cantidad_frecuencia'  => 1,
            'avisado_at'           => '2026-10-15 10:01:00',
        ],'2026-10-01 08:00:00');

        // Le toca, pero la ocurrencia de hoy ya está hecha.
        $hecha_hoy = $this->tarea([
            'detalle'              => '10:00 · Semanal hecha hoy',
            'fecha_realizacion'    => '2026-10-09 00:00:00',
            'es_recurrente'        => 1,
            'unidad_frecuencia_id' => $semana->id,
            'cantidad_frecuencia'  => 1,
        ], '2026-10-09 08:00:00');

        PendingCompleted::create([
            'pending_id'        => $hecha_hoy->id,
            'user_id'           => $this->comercio->id,
            'fecha_realizacion' => self::HOY . ' 00:00:00',
        ]);

        $tareas = $this->tareas_pendientes();

        sort($tareas);

        $esperadas = [(int) $toca->id, (int) $avisada_ayer->id];

        sort($esperadas);

        $this->assertSame($esperadas, $tareas);

        // El id de la recurrente lleva la fecha de HOY: es la ocurrencia, no la base.
        $ids = array_column($this->pendientes()->json('recordatorios'), 'id', 'tarea_id');

        $this->assertSame('recordatorio:' . $toca->id . '-' . $toca->created_at->timestamp . ':' . self::HOY . ':1000', $ids[$toca->id]);

        unset($no_toca, $terminada);
    }

    /**
     * @test
     */
    public function van_por_hora_y_con_tope()
    {
        $a_las_diez = $this->tarea(['detalle' => '10:00 · A las diez']);
        $a_las_nueve = $this->tarea(['detalle' => '09:00 · A las nueve', 'hora_aviso' => '09:00:00']);

        $this->assertSame([(int) $a_las_nueve->id, (int) $a_las_diez->id], $this->tareas_pendientes());

        for ($i = 0; $i < RecordatoriosDelAsistenteHelper::TOPE_RECORDATORIOS; $i++) {
            $this->tarea(['detalle' => '10:15 · Otra ' . $i, 'hora_aviso' => '10:15:00']);
        }

        $this->assertCount(RecordatoriosDelAsistenteHelper::TOPE_RECORDATORIOS, $this->tareas_pendientes());
    }

    /**
     * La consulta deja el anuncio, con y sin plantilla.
     *
     * @test
     */
    public function la_consulta_deja_el_anuncio_del_admin()
    {
        $this->assertFalse(RecordatoriosDelAsistenteHelper::admin_avisa($this->comercio->id));

        $this->pendientes('?plantilla=0')->assertStatus(200);

        $this->assertTrue(RecordatoriosDelAsistenteHelper::admin_avisa($this->comercio->id));
        $this->assertFalse(RecordatoriosDelAsistenteHelper::admin_avisa_con_plantilla($this->comercio->id));

        $this->pendientes('?plantilla=1')->assertStatus(200);

        $this->assertTrue(RecordatoriosDelAsistenteHelper::admin_avisa_con_plantilla($this->comercio->id));

        // Sin el parámetro cuenta como sin plantilla.
        $this->pendientes()->assertStatus(200);

        $this->assertTrue(RecordatoriosDelAsistenteHelper::admin_avisa($this->comercio->id));
        $this->assertFalse(RecordatoriosDelAsistenteHelper::admin_avisa_con_plantilla($this->comercio->id));
    }

    /**
     * @test
     */
    public function sin_la_extension_da_403()
    {
        $this->comercio->extencions()->detach();
        $this->comercio->load('extencions');

        $this->pendientes()->assertStatus(403);
    }

    /* ─────────────────────── 4/5. recordatorios/avisado ─────────────────────── */

    /**
     * @test
     */
    public function avisado_sella_deja_un_solo_mensaje_y_ya_no_sale()
    {
        $tarea = $this->tarea();

        $conversacion = $this->conversacion_whatsapp(Carbon::parse(self::AHORA)->subHour());

        $actualizada = $tarea->updated_at->format('Y-m-d H:i:s');

        Carbon::setTestNow(Carbon::parse(self::AHORA)->addMinute());

        $this->avisado($this->id_de($tarea))->assertStatus(200)->assertExactJson(['ok' => true]);

        // Idempotente.
        $this->avisado($this->id_de($tarea))->assertStatus(200);

        $tarea = $tarea->fresh();

        $this->assertSame('2026-10-16 10:31:00', $tarea->avisado_at->format('Y-m-d H:i:s'));
        $this->assertSame($actualizada, $tarea->updated_at->format('Y-m-d H:i:s'), 'El sello no mueve updated_at: la tarjeta de cambios lo usa de referencia.');

        $mensajes = AiMessage::where('ai_conversation_id', $conversacion->id)->where('rol', 'assistant')->get();

        $this->assertCount(1, $mensajes);
        $this->assertSame('wamid.RECORDATORIO1', $mensajes[0]->whatsapp_message_id);
        $this->assertSame(AiMessage::CANAL_WHATSAPP, $mensajes[0]->canal);
        $this->assertStringStartsWith('⏰ Te recuerdo: *Llamar a Juan por el presupuesto* (hoy 10:00).', $mensajes[0]->contenido);

        $this->assertSame([], $this->tareas_pendientes());
    }

    /**
     * Sin conversación vigente, el recordatorio abre una (con título) para que «listo, ya lo hice»
     * tenga de qué hablar.
     *
     * @test
     */
    public function avisado_sin_conversacion_vigente_abre_una()
    {
        $tarea = $this->tarea();

        $this->avisado($this->id_de($tarea))->assertStatus(200);

        $conversacion = AiConversation::where('user_id', $this->comercio->id)->where('origen', AiConversation::ORIGEN_WHATSAPP)->first();

        $this->assertNotNull($conversacion);
        $this->assertSame('Recordatorio: Llamar a Juan por el presupuesto', $conversacion->titulo);
        $this->assertSame(1, AiMessage::where('ai_conversation_id', $conversacion->id)->count());
    }

    /**
     * @test
     */
    public function avisado_de_un_id_ajeno_mal_formado_o_de_otra_creacion_da_404()
    {
        $tarea = $this->tarea();

        $ajena = $this->tarea(['user_id' => $this->otro_dueno()->id]);

        $this->avisado($this->id_de($ajena))->assertStatus(404);
        $creada = $tarea->created_at->timestamp;

        $this->avisado('recordatorio:' . $tarea->id . '-' . ($creada + 1) . ':' . self::HOY . ':1000')->assertStatus(404);
        $this->avisado('recordatorio:' . $tarea->id . ':' . self::HOY . ':1000')->assertStatus(404);
        $this->avisado('recordatorio:' . $tarea->id . '-' . $creada . ':2026-02-30:1000')->assertStatus(404);
        $this->avisado('recordatorio:' . $tarea->id . '-' . $creada . ':2026-10-17:1000')->assertStatus(404);
        // Sin la hora (el formato anterior a las correcciones) o con una hora que no es del día.
        $this->avisado('recordatorio:' . $tarea->id . '-' . $creada . ':' . self::HOY)->assertStatus(404);
        $this->avisado('recordatorio:' . $tarea->id . '-' . $creada . ':' . self::HOY . ':2500')->assertStatus(404);
        $this->avisado('escaneo_factura:' . $tarea->id)->assertStatus(404);
        $this->avisado(null)->assertStatus(404);

        $this->assertNull($tarea->fresh()->avisado_at);
        $this->assertNull($ajena->fresh()->avisado_at);
    }

    /**
     * Un sello que llega después de la medianoche (reintento de la marca) no tapa la ocurrencia de
     * hoy de una recurrente.
     *
     * @test
     */
    public function el_sello_que_cruza_la_medianoche_no_tapa_la_ocurrencia_de_hoy()
    {
        $diaria = $this->tarea([
            'fecha_realizacion'    => '2026-10-01 00:00:00',
            'es_recurrente'        => 1,
            'unidad_frecuencia_id' => $this->unidad('day')->id,
            'cantidad_frecuencia'  => 1,
        ], '2026-10-01 08:00:00');

        $id_de_ayer = 'recordatorio:' . $diaria->id . '-' . $diaria->created_at->timestamp . ':2026-10-15:1000';

        $this->avisado($id_de_ayer)->assertStatus(200);

        $this->assertSame('2026-10-15 23:59:59', $diaria->fresh()->avisado_at->format('Y-m-d H:i:s'));

        $this->assertSame([(int) $diaria->id], $this->tareas_pendientes());
    }

    /**
     * El anuncio vence solo: dos horas sin consultas es un admin que ya no consulta.
     *
     * @test
     */
    public function el_anuncio_vence_a_las_dos_horas()
    {
        RecordatoriosDelAsistenteHelper::anunciar($this->comercio->id, '1');

        $this->assertTrue(RecordatoriosDelAsistenteHelper::admin_avisa_con_plantilla($this->comercio->id));

        Carbon::setTestNow(Carbon::parse(self::AHORA)->addHours(2)->addMinute());

        $this->assertFalse(RecordatoriosDelAsistenteHelper::admin_avisa($this->comercio->id));
    }


    /* ─────────────────────── correcciones del chequeo independiente ─────────────────────── */

    /**
     * La gracia cruza la medianoche: el recordatorio de las 23:58 que el admin ve a las 00:00 sale,
     * con la fecha de AYER en el id y en el texto, y el sello queda en ese día.
     *
     * @test
     */
    public function el_recordatorio_de_las_23_58_visto_a_las_00_00_sale_con_la_fecha_de_ayer()
    {
        $tarea = $this->tarea(['detalle' => '23:58 · Sacar la basura', 'hora_aviso' => '23:58:00']);

        Carbon::setTestNow(Carbon::parse('2026-10-17 00:00:00'));

        $respuesta = $this->pendientes();

        $respuesta->assertStatus(200);

        $this->assertCount(1, $respuesta->json('recordatorios'));
        $this->assertSame($this->id_de($tarea, '2026-10-16'), $respuesta->json('recordatorios.0.id'));
        $this->assertSame('2026-10-16', $respuesta->json('recordatorios.0.fecha'));
        $this->assertStringStartsWith('⏰ Te recuerdo: *Sacar la basura* (ayer 23:58).', $respuesta->json('recordatorios.0.texto'));

        Carbon::setTestNow(Carbon::parse('2026-10-17 00:01:00'));

        $this->avisado($this->id_de($tarea, '2026-10-16'))->assertStatus(200);

        $this->assertSame('2026-10-16 23:59:59', $tarea->fresh()->avisado_at->format('Y-m-d H:i:s'));
        $this->assertSame([], $this->tareas_pendientes());

        // A las 03:00 el margen ya no llega a ayer: una de las 23:58 sin avisar tampoco saldría.
        $otra = $this->tarea(['detalle' => '23:58 · Otra', 'hora_aviso' => '23:58:00']);

        Carbon::setTestNow(Carbon::parse('2026-10-17 03:00:00'));

        $this->assertSame([], $this->tareas_pendientes());

        unset($otra);
    }

    /**
     * «Mejor a las 11» después del recordatorio de las 10: vuelve a salir a las 11, con otro id, y el
     * sello tardío del de las 10 no lo tapa.
     *
     * @test
     */
    public function cambiar_la_hora_despues_del_aviso_vuelve_a_avisar_a_la_hora_nueva()
    {
        $tarea = $this->tarea();

        $this->avisado($this->id_de($tarea))->assertStatus(200);

        $id_de_las_10 = $this->id_de($tarea);

        // Releída, como la leen el PUT de la pantalla y ejecutar_cambios (con la marca del sello).
        $tarea = $tarea->fresh();

        $tarea->update(['detalle' => '11:00 · Llamar a Juan por el presupuesto', 'hora_aviso' => '11:00:00']);

        $tarea = $tarea->fresh();

        $this->assertNull($tarea->avisado_at, 'La hora nueva todavía no llegó: la marca de hoy se borra.');
        $this->assertSame([], $this->tareas_pendientes(), 'A las 10:30 la de las 11 todavía no venció.');

        // Un sello del recordatorio de las 10 que llega tarde contesta 200 pero no sella.
        $this->avisado($id_de_las_10)->assertStatus(200);

        $this->assertNull($tarea->fresh()->avisado_at);

        Carbon::setTestNow(Carbon::parse('2026-10-16 11:05:00'));

        $respuesta = $this->pendientes();

        $this->assertSame($this->id_de($tarea), $respuesta->json('recordatorios.0.id'));
        $this->assertStringEndsWith(':1100', $respuesta->json('recordatorios.0.id'));
        $this->assertNotSame($id_de_las_10, $respuesta->json('recordatorios.0.id'));
    }

    /**
     * Editar a una hora de hoy que ya pasó (o prenderle el aviso a esa hora) no dispara el WhatsApp
     * al toque; en un día que no le toca, la marca se borra.
     *
     * @test
     */
    public function editar_a_una_hora_que_ya_paso_no_dispara_el_aviso_al_toque()
    {
        $tarea = $this->tarea(['detalle' => '11:00 · Llamar a Juan', 'hora_aviso' => '11:00:00']);

        $tarea->update(['detalle' => '10:15 · Llamar a Juan', 'hora_aviso' => '10:15:00']);

        $this->assertSame(self::AHORA, $tarea->fresh()->avisado_at->format('Y-m-d H:i:s'));
        $this->assertSame([], $this->tareas_pendientes());

        $sin_pedido = $this->tarea(['detalle' => '10:00 · Sin pedido', 'avisar_por_whatsapp' => 0]);

        $sin_pedido->update(['avisar_por_whatsapp' => 1]);

        $this->assertSame(self::AHORA, $sin_pedido->fresh()->avisado_at->format('Y-m-d H:i:s'));
        $this->assertSame([], $this->tareas_pendientes());

        // Mañana a una hora que hoy ya pasó: hoy no le toca, así que no hay nada que marcar.
        $de_manana = $this->tarea(['detalle' => '11:00 · De mañana', 'hora_aviso' => '11:00:00', 'fecha_realizacion' => '2026-10-17 00:00:00', 'avisado_at' => '2026-10-15 11:00:00']);

        $de_manana->update(['detalle' => '09:00 · De mañana', 'hora_aviso' => '09:00:00']);

        $this->assertNull($de_manana->fresh()->avisado_at);

        // El sello del admin no toca ninguno de los tres campos: no recalcula nada.
        $vencida = $this->tarea(['detalle' => '10:00 · Vencida']);

        Carbon::setTestNow(Carbon::parse(self::AHORA)->addMinute());

        $this->avisado($this->id_de($vencida))->assertStatus(200);

        $this->assertSame('2026-10-16 10:31:00', $vencida->fresh()->avisado_at->format('Y-m-d H:i:s'));
    }
}
