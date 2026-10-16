<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaTareaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\RecordatoriosDelAsistenteHelper;
use App\Models\AiMessageAction;
use App\Models\Pending;
use Illuminate\Support\Facades\Cache;

/**
 * Misión recordatorios-whatsapp (16/10/2026) — lo que pasa al confirmar la tarea: la que confirma
 * EL DUEÑO con hora pide el aviso (un empleado no, sin hora tampoco), el texto que lee el modelo
 * promete el aviso solo con el anuncio del admin (con plantilla, sin plantilla, sin anuncio), y
 * proponer_cambios_en_tarea con `hora` reescribe el prefijo y mueve `hora_aviso`.
 */
class Recordatorio_al_confirmar_la_tarea_Test extends RecordatoriosTestCase
{
    /* ─────────────────────── 3. la tarea pide el aviso ─────────────────────── */

    /**
     * @test
     */
    public function la_tarea_del_dueno_con_hora_pide_el_aviso()
    {
        $resultado = $this->confirmar_tarea($this->tarjeta_de_tarea('10:00 · ZZR16 Llamar a Juan'));

        $tarea = Pending::find($resultado['tarea_id']);

        $this->assertTrue($tarea->avisar_por_whatsapp);
        $this->assertSame('10:00:00', $tarea->hora_aviso);
        $this->assertNull($tarea->avisado_at);
    }

    /**
     * Un empleado que pide «recordame…» desde el sistema no le manda un WhatsApp al teléfono del dueño.
     *
     * @test
     */
    public function la_tarea_de_un_empleado_no_pide_el_aviso()
    {
        $this->empleado->admin_access = 1;
        $this->empleado->save();

        $resultado = $this->confirmar_tarea($this->tarjeta_de_tarea('10:00 · ZZR16 Llamar a Juan'), $this->empleado->fresh());

        $tarea = Pending::find($resultado['tarea_id']);

        $this->assertFalse($tarea->avisar_por_whatsapp);
        $this->assertSame('10:00:00', $tarea->hora_aviso, 'La hora igual queda: la tarea es la misma, solo no avisa.');
        $this->assertSame(PropuestaTareaIaHelper::AVISO_SIN_RECORDATORIO, $resultado['aviso']);
    }

    /**
     * @test
     */
    public function la_tarea_sin_hora_no_pide_el_aviso()
    {
        RecordatoriosDelAsistenteHelper::anunciar($this->comercio->id, '1');

        $resultado = $this->confirmar_tarea($this->tarjeta_de_tarea('ZZR16 Llamar a Juan'));

        $tarea = Pending::find($resultado['tarea_id']);

        $this->assertFalse($tarea->avisar_por_whatsapp);
        $this->assertNull($tarea->hora_aviso);
        $this->assertSame(PropuestaTareaIaHelper::AVISO_SIN_RECORDATORIO, $resultado['aviso'], 'Sin hora el texto es el de siempre: no promete nada.');
    }

    /**
     * Los tres casos del aviso al modelo: con plantilla promete la hora, sin plantilla promete a
     * medias (solo si hablaron ese día), sin anuncio del admin no promete nada.
     *
     * @test
     */
    public function el_aviso_al_modelo_depende_del_anuncio_del_admin()
    {
        RecordatoriosDelAsistenteHelper::anunciar($this->comercio->id, '1');

        $con_plantilla = $this->confirmar_tarea($this->tarjeta_de_tarea('10:00 · ZZR16 Con plantilla'));

        $this->assertSame('A las 10:00 de ese día le llega un recordatorio por WhatsApp. Decíselo en una línea.', $con_plantilla['aviso']);

        $recurrente = $this->confirmar_tarea($this->tarjeta_de_tarea('09:15 · ZZR16 Recurrente', [
            'es_recurrente'        => true,
            'unidad_frecuencia_id' => $this->unidad('week')->id,
            'cantidad_frecuencia'  => 1,
        ]));

        $this->assertSame('A las 09:15 de ese día y de cada repetición le llega un recordatorio por WhatsApp. Decíselo en una línea.', $recurrente['aviso']);

        RecordatoriosDelAsistenteHelper::anunciar($this->comercio->id, '0');

        $sin_plantilla = $this->confirmar_tarea($this->tarjeta_de_tarea('10:00 · ZZR16 Sin plantilla'));

        $this->assertSame(PropuestaTareaIaHelper::AVISO_RECORDATORIO_SI_HABLARON, $sin_plantilla['aviso']);
        $this->assertStringContainsString('no le prometas el aviso', $sin_plantilla['aviso']);

        Cache::forget(RecordatoriosDelAsistenteHelper::CLAVE_ANUNCIO . $this->comercio->id);

        $sin_anuncio = $this->confirmar_tarea($this->tarjeta_de_tarea('10:00 · ZZR16 Sin anuncio'));

        $this->assertSame(PropuestaTareaIaHelper::AVISO_SIN_RECORDATORIO, $sin_anuncio['aviso']);

        // En los tres casos la tarea pide el aviso igual: lo que cambia es solo qué se promete.
        $this->assertSame(4, Pending::where('user_id', $this->comercio->id)->where('detalle', 'like', '%ZZR16%')->where('avisar_por_whatsapp', 1)->count());
    }

    /**
     * proponer_cambios_en_tarea con `hora`: reescribe el prefijo (sin apilar horas), `hora_aviso` lo
     * sigue y, confirmado por el dueño, pide el aviso. "sin hora" la saca.
     *
     * @test
     */
    public function los_cambios_con_hora_reescriben_el_prefijo_y_la_hora_del_aviso()
    {
        $tarea = $this->tarea(['detalle' => '10:00 · ZZR16 Pagar la luz', 'avisar_por_whatsapp' => 0]);

        $conversacion = $this->conversacion_whatsapp();

        $contexto = ContextoDeCargaIa::de_la_conversacion($conversacion);

        $respuesta = PropuestaTareaIaHelper::proponer_cambios($contexto, $this->mensaje($conversacion, 'assistant', 'pendiente'), [
            'tarea_id' => $tarea->id,
            'hora'     => '11',
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame('11:00 · ZZR16 Pagar la luz', $accion->datos['detalle']);

        $resultado = PropuestaTareaIaHelper::ejecutar_cambios($contexto, $accion);

        $this->assertSame(PropuestaTareaIaHelper::AVISO_SIN_RECORDATORIO, $resultado['aviso'], 'Sin anuncio del admin no se promete el aviso.');

        $tarea = $tarea->fresh();

        $this->assertSame('11:00 · ZZR16 Pagar la luz', $tarea->detalle);
        $this->assertSame('11:00:00', $tarea->hora_aviso);
        $this->assertTrue($tarea->avisar_por_whatsapp, 'El dueño confirmó un cambio con hora: pide el aviso.');

        // Un detalle nuevo SIN hora conserva la que tenía: cambiar el texto no saca el recordatorio.
        $sin_prefijo = PropuestaTareaIaHelper::proponer_cambios($contexto, $this->mensaje($conversacion, 'assistant', 'pendiente'), [
            'tarea_id' => $tarea->id,
            'detalle'  => 'ZZR16 Pagar la luz y el gas',
        ]);

        $this->assertSame('11:00 · ZZR16 Pagar la luz y el gas', AiMessageAction::find($sin_prefijo['tarjeta_id'])->datos['detalle']);

        // La hora pedida le gana al prefijo viejo que el modelo reenvía en el detalle: nunca dos horas.
        $reenviado = PropuestaTareaIaHelper::proponer_cambios($contexto, $this->mensaje($conversacion, 'assistant', 'pendiente'), [
            'tarea_id' => $tarea->id,
            'detalle'  => '11:00 · ZZR16 Pagar la luz',
            'hora'     => '12',
        ]);

        $this->assertSame('12:00 · ZZR16 Pagar la luz', AiMessageAction::find($reenviado['tarjeta_id'])->datos['detalle']);

        // "sin hora" la saca, y con ella el recordatorio.
        $sacar = PropuestaTareaIaHelper::proponer_cambios($contexto, $this->mensaje($conversacion, 'assistant', 'pendiente'), [
            'tarea_id' => $tarea->id,
            'hora'     => 'sin hora',
        ]);

        $accion = AiMessageAction::find($sacar['tarjeta_id']);

        $this->assertSame('ZZR16 Pagar la luz', $accion->datos['detalle']);

        PropuestaTareaIaHelper::ejecutar_cambios($contexto, $accion);

        $this->assertNull($tarea->fresh()->hora_aviso);

        // Una hora que no se entiende se pregunta.
        $rara = PropuestaTareaIaHelper::proponer_cambios($contexto, $this->mensaje($conversacion, 'assistant', 'pendiente'), [
            'tarea_id' => $tarea->id,
            'hora'     => '25',
        ]);

        $this->assertNotEmpty($rara['faltan']);
    }
}
