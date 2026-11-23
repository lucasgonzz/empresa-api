<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\asistente_ia\RecordatoriosDelAsistenteHelper;
use App\Models\AiConversation;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Misión agente-tareas-programadas (21/11/2026) — PROGRAMAR UNA TAREA DEL ASISTENTE HABLANDO CON ÉL.
 *
 * Pedido de Lucas: «si el dueño dice "hoy no me preguntes, mandame recién el viernes que viene por esto", que
 * se guarde esa tarea y el viernes le vuelve a mandar. O si dice el viernes por la tarde, que le mande recién
 * el viernes a la tarde». Lo que protege este archivo (CA1, CA2, CA6 y CA15 del plan):
 *   1. «el viernes que viene a la tarde» queda guardado con su día, su franja y la hora resuelta, y la
 *      herramienta devuelve el momento en palabras para que el modelo lo repita;
 *   2. los horarios preferidos del dueño (a qué hora es «a la tarde», qué días no escribirle) se guardan y
 *      mandan sobre los de fábrica;
 *   3. los límites y las validaciones (10 activas, el pasado, +90 días, 1000 caracteres) rechazan sin crear
 *      nada, y un empleado no programa mensajes al teléfono del dueño;
 *   4. lo que el asistente promete sobre CÓMO le llega sigue a lo que el admin anunció que puede mandar.
 *
 * 🔴 Las herramientas corren de verdad (execute_tool_calls) contra la base. El reloj está fijo (sábado
 * 21/11/2026 a las 10:00) y la cola fakeada (TareasDelAsistenteTestCase).
 *
 * PHP 7.4: sin match, sin ?->, sin str_contains, sin argumentos nombrados.
 */
class Tareas_del_asistente_programar_Test extends TareasDelAsistenteTestCase
{
    /**
     * Cuántas tareas del asistente tiene el dueño, de cualquier estado.
     *
     * @return int
     */
    protected function cantidad_de_tareas()
    {
        return DB::table('ai_tareas_del_asistente')->where('owner_id', $this->comercio->id)->count();
    }

    /**
     * 🔴 CA1. «El viernes que viene a la tarde»: una fila activa, una vez, a las 17:00 del 27/11, con la
     * franja dicha, y el resultado trae el momento en palabras y el id de la tarea.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function programa_una_vez_el_viernes_a_la_tarde()
    {
        $this->ahora(self::SABADO);

        $resultado = $this->herramienta('programar_tarea_del_asistente', [
            'titulo'      => self::TITULO,
            'instruccion' => self::INSTRUCCION,
            'fecha'       => '2026-11-27',
            'franja'      => 'tarde',
        ]);

        $this->assertFalse($resultado['error'], 'La herramienta rechazó el alta: ' . $resultado['texto']);
        $this->assertIsArray($resultado['datos'], 'El resultado tiene que ser un JSON: ' . $resultado['texto']);

        $this->assertSame(1, $this->cantidad_de_tareas());

        $fila = DB::table('ai_tareas_del_asistente')->where('owner_id', $this->comercio->id)->first();

        $this->assertSame('activa', $fila->estado);
        $this->assertSame('una_vez', $fila->recurrencia);
        $this->assertSame('17:00', $fila->hora);
        $this->assertSame('tarde', $fila->franja);
        $this->assertSame('2026-11-27 17:00:00', $fila->proxima_ejecucion_at);
        $this->assertSame(self::TITULO, $fila->titulo);
        $this->assertSame(self::INSTRUCCION, $fila->instruccion);

        $this->assertSame((int) $fila->id, (int) $resultado['datos']['tarea_id']);
        $this->assertStringContainsString('viernes 27/11 a las 17:00', $resultado['datos']['cuando']);
    }

    /**
     * 🔴 CA2. Los horarios del dueño: se guardan, mandan sobre los de fábrica para la franja, y las
     * recurrencias saltean los días en que no quiere mensajes. Sin horarios guardados, «a la mañana» son las 09:00.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function usa_los_horarios_del_dueno()
    {
        $this->ahora(self::SABADO);

        // Sin horarios guardados: «a la mañana» es a las 09:00 (el de fábrica).
        $de_fabrica = $this->herramienta('programar_tarea_del_asistente', [
            'titulo'      => 'Mañana de fábrica',
            'instruccion' => 'Revisá los pedidos',
            'fecha'       => '2026-11-22',
            'franja'      => 'manana',
        ]);

        $this->assertFalse($de_fabrica['error'], 'La herramienta rechazó el alta: ' . $de_fabrica['texto']);
        $this->assertSame('09:00', DB::table('ai_tareas_del_asistente')->where('id', $de_fabrica['datos']['tarea_id'])->value('hora'));

        // El dueño dice a qué hora prefiere la tarde y que los domingos no le escriban.
        $guardado = $this->herramienta('guardar_horarios_del_dueno', ['tarde' => '18:30', 'dias_sin_mensajes' => [7]]);

        $this->assertFalse($guardado['error'], 'La herramienta rechazó los horarios: ' . $guardado['texto']);

        $horarios = json_decode(DB::table('users')->where('id', $this->comercio->id)->value('agente_horarios'), true);

        $this->assertSame('18:30', $horarios['tarde']);
        $this->assertSame([7], $horarios['dias_sin_mensajes']);

        // Las tareas ya programadas no se mueven.
        $this->assertSame('2026-11-22 09:00:00', DB::table('ai_tareas_del_asistente')->where('id', $de_fabrica['datos']['tarea_id'])->value('proxima_ejecucion_at'));

        // Una tarea nueva «a la tarde» usa la hora del dueño.
        $tarde = $this->herramienta('programar_tarea_del_asistente', [
            'titulo'      => self::TITULO,
            'instruccion' => self::INSTRUCCION,
            'fecha'       => '2026-11-27',
            'franja'      => 'tarde',
        ]);

        $this->assertFalse($tarde['error'], 'La herramienta rechazó el alta: ' . $tarde['texto']);

        $fila = $this->fila_de($tarde['datos']['tarea_id']);

        $this->assertSame('18:30', $fila->hora);
        $this->assertSame('2026-11-27 18:30:00', $fila->proxima_ejecucion_at);

        // Una diaria a las 09:00 creada el sábado a las 10:00 saltea el domingo (día sin mensajes).
        $diaria = $this->herramienta('programar_tarea_del_asistente', [
            'titulo'      => 'Ventas del día',
            'instruccion' => 'Contame cómo vienen las ventas',
            'recurrencia' => 'diaria',
            'hora'        => '09:00',
        ]);

        $this->assertFalse($diaria['error'], 'La herramienta rechazó el alta: ' . $diaria['texto']);
        $this->assertSame('2026-11-23 09:00:00', $this->fila_de($diaria['datos']['tarea_id'])->proxima_ejecucion_at);
    }

    /**
     * 🔴 CA6. Límites y validaciones: la 11ª activa, una vez en el pasado o a menos de 5 minutos, a más de 90
     * días, una instrucción de 1001 caracteres y una recurrencia más frecuente que diaria se rechazan sin
     * crear nada; los bordes que sí valen, valen.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function rechaza_limites_y_validaciones()
    {
        $this->ahora(self::SABADO);

        // Diez activas: la 11ª se rechaza con una salida (cancelar una).
        for ($i = 1; $i <= 10; $i++) {
            $this->tarea_del_asistente(['titulo' => 'Tarea ' . $i]);
        }

        $onceava = $this->herramienta('programar_tarea_del_asistente', [
            'titulo'      => 'Tarea 11',
            'instruccion' => 'Una más',
            'fecha'       => '2026-11-27',
            'hora'        => '17:00',
        ]);

        $this->assertStringNotContainsString('Tool desconocida', $onceava['texto'], 'La herramienta no existe todavía.');
        $this->assertTrue($onceava['error'], 'La 11ª tarea activa tiene que rechazarse: ' . $onceava['texto']);
        $this->assertStringContainsStringIgnoringCase('cancel', $onceava['texto'], 'El rechazo tiene que sugerir cancelar una.');
        $this->assertSame(10, $this->cantidad_de_tareas());

        // Sin las diez, el resto de las validaciones.
        DB::table('ai_tareas_del_asistente')->where('owner_id', $this->comercio->id)->delete();

        $rechazos = [
            'a las 10:03 de hoy (a menos de 5 minutos)' => ['fecha' => '2026-11-21', 'hora' => '10:03'],
            'ayer'                                      => ['fecha' => '2026-11-20', 'hora' => '09:00'],
            'a +91 días'                                => ['fecha' => '2027-02-20', 'hora' => '09:00'],
        ];

        foreach ($rechazos as $caso => $momento) {
            $resultado = $this->herramienta('programar_tarea_del_asistente', array_merge([
                'titulo'      => 'Rechazada',
                'instruccion' => 'No se tiene que crear',
            ], $momento));

            $this->assertTrue($resultado['error'], 'Una vez ' . $caso . ' tiene que rechazarse: ' . $resultado['texto']);
            $this->assertStringNotContainsString('Tool desconocida', $resultado['texto']);

            // Un momento pasado o inminente se rechaza diciendo la hora actual, para que el modelo corrija (CA6).
            if ($momento['fecha'] !== '2027-02-20') {
                $this->assertStringContainsString('10:00', $resultado['texto'], 'El rechazo tiene que decir la hora actual (' . $caso . ').');
            }
        }

        $larga = $this->herramienta('programar_tarea_del_asistente', [
            'titulo'      => 'Rechazada',
            'instruccion' => str_repeat('a', 1001),
            'fecha'       => '2026-11-27',
            'hora'        => '17:00',
        ]);

        $this->assertTrue($larga['error'], 'Una instrucción de 1001 caracteres tiene que rechazarse: ' . $larga['texto']);

        $mas_frecuente = $this->herramienta('programar_tarea_del_asistente', [
            'titulo'      => 'Rechazada',
            'instruccion' => 'No se tiene que crear',
            'recurrencia' => 'cada_hora',
            'hora'        => '09:00',
        ]);

        $this->assertTrue($mas_frecuente['error'], 'No hay recurrencias más frecuentes que diaria: ' . $mas_frecuente['texto']);

        $this->assertSame(0, $this->cantidad_de_tareas(), 'Ningún rechazo puede dejar una fila.');

        // Los bordes que sí valen: 1000 caracteres, hoy a las 10:30 y +90 días.
        $valida_mil = $this->herramienta('programar_tarea_del_asistente', [
            'titulo'      => 'Mil',
            'instruccion' => str_repeat('a', 1000),
            'fecha'       => '2026-11-27',
            'hora'        => '17:00',
        ]);
        $this->assertFalse($valida_mil['error'], 'Mil caracteres es el máximo y entra: ' . $valida_mil['texto']);

        $hoy_mas_tarde = $this->herramienta('programar_tarea_del_asistente', [
            'titulo'      => 'Hoy más tarde',
            'instruccion' => 'Avisame hoy',
            'fecha'       => '2026-11-21',
            'hora'        => '10:30',
        ]);
        $this->assertFalse($hoy_mas_tarde['error'], 'Hoy a las 10:30 está a más de 5 minutos y entra: ' . $hoy_mas_tarde['texto']);

        $a_noventa_dias = $this->herramienta('programar_tarea_del_asistente', [
            'titulo'      => 'A noventa días',
            'instruccion' => 'Lejos pero entra',
            'fecha'       => '2027-02-19',
            'hora'        => '09:00',
        ]);
        $this->assertFalse($a_noventa_dias['error'], 'A 90 días entra: ' . $a_noventa_dias['texto']);

        $this->assertSame(3, $this->cantidad_de_tareas());
    }

    /**
     * 🔴 CA6. Un empleado no programa mensajes al teléfono del dueño: en una conversación suya las cinco
     * herramientas no se ofrecen (en la del dueño sí) y una llamada forzada se rechaza sin tocar nada.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_empleado_no_programa()
    {
        $this->ahora(self::SABADO);

        $del_dueno = $this->conversacion_whatsapp();
        $del_empleado = AiConversation::create([
            'user_id'         => $this->comercio->id,
            'auth_user_id'    => $this->empleado->id,
            'origen'          => AiConversation::ORIGEN_WHATSAPP,
            'last_message_at' => Carbon::now(),
        ]);

        $ofrecidas_al_dueno = $this->nombres_de_herramientas($del_dueno);

        foreach ($this->herramientas_de_tareas() as $nombre) {
            $this->assertContains($nombre, $ofrecidas_al_dueno, 'Al dueño se le ofrece ' . $nombre . '.');
        }

        $ofrecidas_al_empleado = $this->nombres_de_herramientas($del_empleado);

        foreach ($this->herramientas_de_tareas() as $nombre) {
            $this->assertNotContains($nombre, $ofrecidas_al_empleado, 'Al empleado no se le ofrece ' . $nombre . '.');
        }

        // Una llamada forzada de un empleado se rechaza con el motivo de la herramienta, sin crear nada.
        $forzada = $this->herramienta('programar_tarea_del_asistente', [
            'titulo'      => self::TITULO,
            'instruccion' => self::INSTRUCCION,
            'fecha'       => '2026-11-27',
            'hora'        => '17:00',
        ], $this->empleado);

        $this->assertTrue($forzada['error'], 'La llamada de un empleado tiene que rechazarse.');
        $this->assertStringNotContainsString('Tool desconocida', $forzada['texto'], 'Tiene que rechazarla la herramienta, no el despacho.');
        $this->assertSame(0, $this->cantidad_de_tareas());
    }

    /**
     * 🔴 CA15. Lo que el asistente promete sigue al anuncio del admin: sin consulta previa dice «en esta
     * conversación»; con la plantilla, «te escribo por WhatsApp»; sin plantilla, «si ese día hablamos por ahí».
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_aviso_sigue_al_anuncio()
    {
        $this->ahora(self::SABADO);

        Cache::forget(RecordatoriosDelAsistenteHelper::CLAVE_ANUNCIO . $this->comercio->id);

        $pedido = [
            'titulo'      => self::TITULO,
            'instruccion' => self::INSTRUCCION,
            'fecha'       => '2026-11-27',
            'franja'      => 'tarde',
        ];

        $sin_anuncio = $this->herramienta('programar_tarea_del_asistente', $pedido);

        $this->assertFalse($sin_anuncio['error'], 'La herramienta rechazó el alta: ' . $sin_anuncio['texto']);
        $this->assertStringContainsString('en esta conversación', $sin_anuncio['datos']['aviso']);

        // El admin consulta CON la plantilla: puede escribirle aunque pasaron más de 24 h.
        $this->pendientes('?plantilla=1')->assertStatus(200);

        $con_plantilla = $this->herramienta('programar_tarea_del_asistente', $pedido);

        $this->assertFalse($con_plantilla['error'], 'La herramienta rechazó el alta: ' . $con_plantilla['texto']);
        $this->assertStringContainsString('te escribo por WhatsApp', $con_plantilla['datos']['aviso']);

        // El admin consulta SIN la plantilla: sólo si ese día hablaron.
        $this->pendientes('?plantilla=0')->assertStatus(200);

        $sin_plantilla = $this->herramienta('programar_tarea_del_asistente', $pedido);

        $this->assertFalse($sin_plantilla['error'], 'La herramienta rechazó el alta: ' . $sin_plantilla['texto']);
        $this->assertStringContainsString('si ese día hablamos por ahí', $sin_plantilla['datos']['aviso']);
    }
}
