<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\asistente_ia\RecordatoriosDelAsistenteHelper;
use Illuminate\Support\Facades\Cache;

/**
 * Misión agente-tareas-programadas (21/11/2026) — LA ENTREGA TAMBIÉN SALE CUANDO EL ADMIN NO TIENE PLANTILLA.
 *
 * La revisión de la punta admin lo pidió expresamente: el admin consulta `recordatorios-pendientes?plantilla=0`
 * cuando no tiene configurada la plantilla de recordatorios, y entonces manda sólo con la ventana de 24 h
 * abierta. La lista que le devolvemos NO puede depender de ese parámetro: el resultado de la tarea tiene que
 * venir igual (el admin decide cómo mandarlo), y el parámetro sólo cambia lo que el asistente le promete al
 * dueño (CA15). Sin esto, un admin sin plantilla nunca vería los ítems de tarea.
 *
 * 🔴 Archivo NUEVO: los tests de aceptación del commit 573440a6 no se tocan.
 *
 * PHP 7.4: sin match, sin ?->, sin str_contains, sin argumentos nombrados.
 */
class Tareas_del_asistente_entrega_sin_plantilla_Test extends TareasDelAsistenteTestCase
{
    /**
     * La tarea de las 17:00 despertada a las 16:58 y cerrada como lista a las 17:01 (reloj queda en las 17:01).
     *
     * @return void
     */
    protected function dejar_una_ejecucion_lista()
    {
        $tarea = $this->tarea_del_asistente();

        $this->turno_listo($tarea, '2026-11-27 16:58:00', 'Te quedaban dos preguntas sin responder.');

        $this->ahora('2026-11-27 17:01:00');

        $this->despertar();
    }

    /**
     * 🔴 Con `plantilla=0` el ítem de la tarea viene igual que con `plantilla=1` y sin el parámetro, y el anuncio
     * queda como «admin sin plantilla».
     *
     * @group asistente-whatsapp
     * @test
     */
    public function con_plantilla_cero_la_tarea_sale_igual()
    {
        $this->dejar_una_ejecucion_lista();

        Cache::forget(RecordatoriosDelAsistenteHelper::CLAVE_ANUNCIO . $this->comercio->id);

        $sin_plantilla = $this->pendientes('?plantilla=0');
        $sin_plantilla->assertStatus(200);

        $con_plantilla = $this->pendientes('?plantilla=1');
        $con_plantilla->assertStatus(200);

        $sin_parametro = $this->pendientes();
        $sin_parametro->assertStatus(200);

        $this->assertCount(1, $sin_plantilla->json('recordatorios'), 'Con plantilla=0 el ítem de la tarea tiene que venir.');
        $this->assertSame($con_plantilla->json('recordatorios'), $sin_plantilla->json('recordatorios'));
        $this->assertSame($sin_parametro->json('recordatorios'), $sin_plantilla->json('recordatorios'));
        $this->assertSame('tarea_del_asistente', $sin_plantilla->json('recordatorios.0.plantilla'));
        $this->assertSame('Te quedaban dos preguntas sin responder.', $sin_plantilla->json('recordatorios.0.texto'));

        // El anuncio de la última consulta (`plantilla=0` otra vez) deja al asistente sin prometer la plantilla.
        $this->pendientes('?plantilla=0')->assertStatus(200);

        $this->assertTrue(RecordatoriosDelAsistenteHelper::admin_avisa($this->comercio->id));
        $this->assertFalse(RecordatoriosDelAsistenteHelper::admin_avisa_con_plantilla($this->comercio->id));
    }

    /**
     * 🔴 Un admin viejo (que no manda `plantilla`, o la manda en 0) y un ítem de tarea sellado: el sello es el de
     * siempre, un solo POST, y después la lista queda vacía.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function se_sella_igual_con_el_admin_sin_plantilla()
    {
        $this->dejar_una_ejecucion_lista();

        $item = $this->pendientes('?plantilla=0')->json('recordatorios.0');

        $this->avisado($item['id'], 'wamid.SINPLANTILLA')->assertStatus(200)->assertJson(['ok' => true]);

        $this->assertSame([], $this->pendientes('?plantilla=0')->json('recordatorios'));
    }
}
