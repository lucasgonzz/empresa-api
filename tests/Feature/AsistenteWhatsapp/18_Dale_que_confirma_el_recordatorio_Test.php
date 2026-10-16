<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionDeterministaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaTareaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\RecordatoriosDelAsistenteHelper;
use App\Models\Pending;

/**
 * Misión recordatorios-whatsapp (16/10/2026), corrección del chequeo independiente — el «dale» que
 * confirma la tarea solo (ConfirmacionDeterministaIaHelper) le pasa al modelo el aviso del
 * recordatorio.
 *
 * 🔴 EL CASO: «recordame el viernes a las 10 que…», el asistente arma la tarjeta y el dueño contesta
 * «Dale». Ese «sí» lo confirma el código, no el modelo, y la nota que lee el modelo después traía
 * solo el texto del resultado: el `aviso` —«a las 10 le llega un recordatorio por WhatsApp» o «no le
 * llega ningún aviso»— se perdía, y el modelo prometía o negaba el recordatorio a ciegas.
 *
 * Pasa por el camino real: proponer_tarea → «Dale» → quizas_confirmar() →
 * ConfirmacionPorTextoIaHelper::confirmar() → nota().
 */
class Dale_que_confirma_el_recordatorio_Test extends RecordatoriosTestCase
{
    /**
     * La escena: la tarjeta de «recordame el viernes a las 10», el «Dale» del dueño y el assistant
     * que lo contesta, todavía pendiente. Devuelve lo que devolvió quizas_confirmar().
     *
     * @return array
     */
    protected function dale_a_la_tarea()
    {
        $conversacion = $this->conversacion_whatsapp();

        $this->mensaje($conversacion, 'user', 'listo', ['contenido' => 'Recordame el viernes a las 10 que tengo que llamar a Juan']);

        $propone = $this->mensaje($conversacion, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $respuesta = PropuestaTareaIaHelper::proponer_tarea(ContextoDeCargaIa::de_la_conversacion($conversacion), $propone, [
            'detalle' => 'ZZR18 Llamar a Juan',
            'fecha'   => '2026-10-23',
            'hora'    => '10',
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $propone->estado = 'listo';
        $propone->contenido = 'Te agendo el viernes 23/10 a las 10:00 «Llamar a Juan». ¿Dale?';
        $propone->save();

        $this->mensaje($conversacion, 'user', 'listo', ['contenido' => 'Dale']);

        $contestando = $this->mensaje($conversacion, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $confirmacion = ConfirmacionDeterministaIaHelper::quizas_confirmar($conversacion, $contestando);

        $this->assertNotNull($confirmacion, 'El «Dale» a una sola tarjeta se confirma sin el modelo.');
        $this->assertTrue($confirmacion['resultado']['ok'], json_encode($confirmacion['resultado']));

        return $confirmacion;
    }

    /**
     * @test
     */
    public function con_anuncio_y_plantilla_la_nota_del_dale_promete_el_recordatorio()
    {
        RecordatoriosDelAsistenteHelper::anunciar($this->comercio->id, '1');

        $confirmacion = $this->dale_a_la_tarea();

        $nota = ConfirmacionDeterministaIaHelper::nota($confirmacion);

        $this->assertStringContainsString('[El sistema ya confirmó la tarjeta #' . $confirmacion['tarjeta_id'], $nota);
        $this->assertStringContainsString('Aviso de esta carga, para vos: A las 10:00 de ese día le llega un recordatorio por WhatsApp.', $nota);

        $tarea = Pending::where('user_id', $this->comercio->id)->where('detalle', '10:00 · ZZR18 Llamar a Juan')->first();

        $this->assertNotNull($tarea);
        $this->assertTrue($tarea->avisar_por_whatsapp);
    }

    /**
     * @test
     */
    public function sin_anuncio_la_nota_del_dale_dice_que_no_le_llega()
    {
        $confirmacion = $this->dale_a_la_tarea();

        $nota = ConfirmacionDeterministaIaHelper::nota($confirmacion);

        $this->assertStringContainsString('Aviso de esta carga, para vos: ' . PropuestaTareaIaHelper::AVISO_SIN_RECORDATORIO, $nota);

        $tarea = Pending::where('user_id', $this->comercio->id)->where('detalle', '10:00 · ZZR18 Llamar a Juan')->first();

        $this->assertFalse($tarea->avisar_por_whatsapp);
    }

    /**
     * Una nota sin aviso en el resultado queda como siempre: no se le agrega nada.
     *
     * @test
     */
    public function un_resultado_sin_aviso_deja_la_nota_como_estaba()
    {
        $nota = ConfirmacionDeterministaIaHelper::nota([
            'tarjeta_id' => 7,
            'resultado'  => ['ok' => true, 'resultado' => 'Gasto N° 12 registrado', 'detalle' => ['numero' => 12]],
        ]);

        $this->assertSame(
            '[El sistema ya confirmó la tarjeta #7 por el sí de la persona. Resultado: Gasto N° 12 registrado. '
            . 'Contale el resultado con esas palabras y seguí con lo que haya quedado pendiente del pedido; no la vuelvas a confirmar.]',
            $nota
        );
    }
}
