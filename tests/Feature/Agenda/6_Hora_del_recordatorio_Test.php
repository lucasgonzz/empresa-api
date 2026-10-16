<?php

namespace Tests\Feature\Agenda;

use App\Http\Controllers\Helpers\agenda\AgendaTareaHelper;
use App\Models\Pending;
use Carbon\Carbon;

/**
 * Misión recordatorios-whatsapp (16/10/2026) — la hora del recordatorio sale del texto.
 *
 * `hora_aviso` no la escribe nadie a mano: AgendaTareaHelper::validar() la deriva del prefijo
 * «HH:MM · » del detalle. Lo que fija esta suite es la mitad de la pantalla de esa decisión: si el
 * dueño cambia la hora escrita en la Agenda, o la borra, el recordatorio la sigue; y editar desde la
 * pantalla nunca prende ni apaga el pedido de aviso (`avisar_por_whatsapp`), que es del asistente.
 *
 * @group agenda
 */
class Hora_del_recordatorio_Test extends AgendaTestCase
{
    /**
     * @test
     */
    public function validar_deriva_la_hora_del_prefijo()
    {
        $base = ['fecha_realizacion' => '2026-10-23'];

        $this->assertSame('10:00:00', AgendaTareaHelper::validar($base + ['detalle' => '10:00 · Llamar a Juan'], auth()->id())['hora_aviso']);
        $this->assertSame('23:59:00', AgendaTareaHelper::validar($base + ['detalle' => '23:59 · Cerrar'], auth()->id())['hora_aviso']);
        $this->assertSame('00:00:00', AgendaTareaHelper::validar($base + ['detalle' => '00:00 · Medianoche'], auth()->id())['hora_aviso']);

        $this->assertNull(AgendaTareaHelper::validar($base + ['detalle' => 'Llamar a Juan'], auth()->id())['hora_aviso']);
        $this->assertNull(AgendaTareaHelper::validar($base + ['detalle' => '25:00 · Hora que no existe'], auth()->id())['hora_aviso']);
        $this->assertNull(AgendaTareaHelper::validar($base + ['detalle' => '10:75 · Minutos que no existen'], auth()->id())['hora_aviso']);
        $this->assertNull(AgendaTareaHelper::validar($base + ['detalle' => '9:00 · Un dígito'], auth()->id())['hora_aviso']);
        $this->assertNull(AgendaTareaHelper::validar($base + ['detalle' => 'Llamar 10:00 · a Juan'], auth()->id())['hora_aviso']);

        // Lo que no se devuelve: el pedido de aviso y la marca son del asistente y del admin.
        $columnas = AgendaTareaHelper::validar($base + ['detalle' => '10:00 · Llamar a Juan'], auth()->id());

        $this->assertArrayNotHasKey('avisar_por_whatsapp', $columnas);
        $this->assertArrayNotHasKey('avisado_at', $columnas);

        $this->assertSame('Llamar a Juan', AgendaTareaHelper::detalle_sin_hora('10:00 · Llamar a Juan'));
        $this->assertSame('Llamar a Juan', AgendaTareaHelper::detalle_sin_hora('Llamar a Juan'));
    }

    /**
     * La pantalla cambia la hora escrita: `hora_aviso` la sigue y el pedido de aviso no se toca.
     *
     * @test
     */
    public function editar_la_hora_desde_la_pantalla_mueve_la_hora_y_no_toca_el_aviso()
    {
        $tarea = $this->crear_tarea(['detalle' => '10:00 · Llamar a Juan', 'fecha_realizacion' => '2026-10-23']);

        $this->assertSame('10:00:00', $tarea->hora_aviso);
        $this->assertFalse($tarea->avisar_por_whatsapp, 'Una tarea creada desde la pantalla no pide el aviso.');

        // El asistente la había dejado pidiendo el aviso (lo pone solo él).
        Pending::where('id', $tarea->id)->update(['avisar_por_whatsapp' => 1]);

        $body = [
            'detalle'           => '11:30 · Llamar a Juan',
            'fecha_realizacion' => '2026-10-23',
        ];

        $this->putJson('api/pending/' . $tarea->id, $body)->assertStatus(200);

        $tarea = $tarea->fresh();

        $this->assertSame('11:30:00', $tarea->hora_aviso);
        $this->assertTrue($tarea->avisar_por_whatsapp);

        // Borrarle la hora la saca del recordatorio, sin apagar el pedido (si vuelve la hora, vuelve el aviso).
        $this->putJson('api/pending/' . $tarea->id, ['detalle' => 'Llamar a Juan', 'fecha_realizacion' => '2026-10-23'])->assertStatus(200);

        $tarea = $tarea->fresh();

        $this->assertNull($tarea->hora_aviso);
        $this->assertTrue($tarea->avisar_por_whatsapp);
    }


    /**
     * Por el PUT de la pantalla: cambiar la hora después del recordatorio borra la marca de hoy si la
     * hora nueva todavía no llegó, y la pone en "ahora" si ya pasó (no dispara un WhatsApp al toque).
     *
     * @test
     */
    public function cambiar_la_hora_por_la_pantalla_recalcula_la_marca_del_recordatorio()
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:30:00'));

        try {

            $tarea = $this->crear_tarea(['detalle' => '10:00 · Llamar a Juan', 'fecha_realizacion' => '2026-10-16']);

            // El asistente pidió el aviso y el admin ya lo mandó a las 10:02 (sin pasar por el modelo).
            Pending::where('id', $tarea->id)->update(['avisar_por_whatsapp' => 1, 'avisado_at' => '2026-10-16 10:02:00']);

            $this->putJson('api/pending/' . $tarea->id, ['detalle' => '11:00 · Llamar a Juan', 'fecha_realizacion' => '2026-10-16'])->assertStatus(200);

            $this->assertNull($tarea->fresh()->avisado_at, '«Mejor a las 11»: la marca de hoy se borra y a las 11 vuelve a salir.');

            $this->putJson('api/pending/' . $tarea->id, ['detalle' => '10:15 · Llamar a Juan', 'fecha_realizacion' => '2026-10-16'])->assertStatus(200);

            $this->assertSame('2026-10-16 10:30:00', $tarea->fresh()->avisado_at->format('Y-m-d H:i:s'), 'Una hora que ya pasó no dispara el aviso al toque.');

            // Editar otra cosa (las notas) no toca la marca.
            $this->putJson('api/pending/' . $tarea->id, ['detalle' => '10:15 · Llamar a Juan', 'fecha_realizacion' => '2026-10-16', 'notas' => 'urgente'])->assertStatus(200);

            $this->assertSame('2026-10-16 10:30:00', $tarea->fresh()->avisado_at->format('Y-m-d H:i:s'));

        } finally {

            Carbon::setTestNow();
        }
    }
}
