<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\asistente_ia\PedidoSobreTareasIaHelper;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Misión agente-tareas-programadas, arreglo «pensamiento» (24/11/2026): los turnos donde el dueño pide algo sobre
 * tareas programadas (programar, avisar a una hora, posponer, cancelar, consultar) corren con el modelo PROFUNDO
 * en vez del ágil.
 *
 * El problema (medición real en demo3, misma conversación de 12 pedidos): con el modelo rápido 5 a 6 de 12
 * respuestas afirmaban haber programado o cancelado sin hacerlo, aun con la lista real de tareas en el contexto;
 * con el modo «pensamiento» (profundo), 1 de 12. No se toca el texto de ninguna respuesta: sólo qué modelo corre.
 *
 * Qué fijan estos tests:
 *   - unit del detector (PedidoSobreTareasIaHelper): los pedidos de tareas disparan (CA2) y los pedidos comunes
 *     no (CA3);
 *   - integración, mirando el `model` del payload que viaja al modelo doblado: pedido de tareas = todas las
 *     vueltas del turno con el profundo (CA1), pedido común = el ágil como siempre, empleado, dueño sin Agentes de
 *     IA y turno desatendido = como hoy (CA5), un «sí» a una pregunta sobre tareas = profundo (CA4) y un «sí» a
 *     otra pregunta = como hoy.
 *
 * Los ids de modelo salen de la config (`services.deepseek.model_profundo` / `model_agil`), no se escriben acá.
 *
 * PHP 7.4: sin match, sin nullsafe, sin str_contains, sin argumentos nombrados.
 */
class Tareas_del_asistente_pensamiento_Test extends TareasDelAsistenteConModeloTestCase
{
    /* ─────────────────────────────── ayudas ─────────────────────────────── */

    /**
     * Los modelos que viajaron en los pedidos del turno, uno por llamada al modelo y en orden.
     *
     * @return array<int,string>
     */
    protected function modelos_del_turno()
    {
        $modelos = [];

        foreach ($this->pedidos_al_modelo() as $cuerpo) {
            $modelos[] = isset($cuerpo['model']) ? (string) $cuerpo['model'] : '';
        }

        return $modelos;
    }

    /**
     * Afirma que TODAS las llamadas del turno pidieron el modelo de la config indicada y que hubo al menos una.
     *
     * @param  string  $clave_de_config  'model_profundo' o 'model_agil'.
     * @param  string  $mensaje  Qué se esperaba, para el mensaje de falla.
     * @return void
     */
    protected function assert_todas_las_vueltas_con($clave_de_config, $mensaje)
    {
        $esperado = (string) config('services.deepseek.' . $clave_de_config);
        $modelos = $this->modelos_del_turno();

        $this->assertNotSame('', $esperado, 'La config del modelo no puede estar vacía.');
        $this->assertNotEmpty($modelos, 'El turno no llamó al modelo.');

        foreach ($modelos as $indice => $modelo) {
            $this->assertSame($esperado, $modelo, $mensaje . ' (vuelta ' . ($indice + 1) . ' con ' . $modelo . ').');
        }
    }

    /**
     * Un turno armado a mano en la conversación dada (sin pasar por el POST del admin) y corrido con el job real y
     * el modelo doblado. Sirve para el empleado y para el dueño sin la extensión.
     *
     * @param  \App\Models\AiConversation  $conversacion
     * @param  string  $texto  Lo que escribe la persona.
     * @return \App\Models\AiMessage  El assistant del turno, ya terminado.
     */
    protected function turno_a_mano($conversacion, $texto)
    {
        $this->modelo_contesta('Dale, te cuento.');

        $this->mensaje($conversacion, 'user', 'listo', ['contenido' => $texto]);

        $assistant = $this->mensaje($conversacion, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        (new ResponderMensajeChatIaJob($assistant->id))->handle();

        return AiMessage::find($assistant->id);
    }

    /**
     * Una conversación vigente del dueño con un intercambio previo: lo que dijo la persona y lo que contestó el
     * asistente (la pregunta a la que después contesta con un «sí»).
     *
     * @param  string  $lo_que_dijo_la_persona
     * @param  string  $lo_que_pregunto_el_asistente
     * @return \App\Models\AiConversation
     */
    protected function conversacion_con_pregunta($lo_que_dijo_la_persona, $lo_que_pregunto_el_asistente)
    {
        $conversacion = $this->conversacion_whatsapp(Carbon::now()->subMinutes(1));

        $this->mensaje($conversacion, 'user', 'listo', ['contenido' => $lo_que_dijo_la_persona]);
        $this->mensaje($conversacion, 'assistant', 'listo', ['contenido' => $lo_que_pregunto_el_asistente]);

        return $conversacion;
    }

    /* ─────────────────────────────── unit del detector ─────────────────────────────── */

    /**
     * 🔴 CA2. Los pedidos que hablan de tareas programadas disparan: los de la medición real y las demás formas
     * del plan (programar, avisar a una hora, posponer, cancelar, cambiar horarios, consultar).
     *
     * @group asistente-whatsapp
     * @test
     */
    public function los_pedidos_sobre_tareas_disparan()
    {
        $pedidos = [
            // Los de la medición real en demo3.
            'Todos los días a primera hora contame cómo vienen las ventas',
            '¿Qué tenés programado?',
            'Hoy no, mandame recién el viernes',
            'Para mí a la mañana es a las 8:30',
            'El viernes a la tarde preguntame por la logística',
            'Cancelá lo de las ventas de todos los días',
            'Cancelá también la de logística',
            'Mañana a las 10 preguntame si ya llamé al proveedor',
            'Mañana a las 11:15 avisame cómo viene la caja',
            'Cancelá la del proveedor',
            '¿Qué quedó programado?',
            'En 20 minutos preguntame si ya llamé al proveedor',
            'A las 15:43 avisame cómo viene la caja',
            'Cancelá todo lo que tengas programado',
            // Otras formas de pedirlo.
            'Escribime mañana a las 9 para ver los pedidos',
            'Recordame el lunes llamar a Pérez',
            'Avisame cada lunes cómo cerró la semana',
            'Mandame el resumen todos los días a las 8',
            'Programame un aviso para el viernes a la tarde',
            'Agendá una tarea para mañana a la mañana',
            'Sacá la tarea de logística',
            'Borrá el aviso de las ventas',
            'Dejá de avisarme lo de la caja',
            'No me escribas más por lo de logística',
            'No me escribas los domingos',
            'No me avises hasta el lunes',
            'Pasala para el lunes',
            'Posponé lo de la caja hasta el viernes',
            '¿Qué tareas tengo?',
            'Mostrame los avisos que tengo programados',
        ];

        foreach ($pedidos as $pedido) {
            $this->assertTrue(PedidoSobreTareasIaHelper::habla_de_tareas($pedido), 'Tendría que disparar: «' . $pedido . '».');
        }
    }

    /**
     * 🔴 CA3. Los pedidos comunes del negocio no disparan (para no encarecer todo el chat).
     *
     * @group asistente-whatsapp
     * @test
     */
    public function los_pedidos_comunes_no_disparan()
    {
        $pedidos = [
            '¿Cuánto vendí hoy?',
            'Cargá una venta de 2 remeras a Juan',
            '¿Qué stock tengo de jean azul?',
            'Hola',
            'Gracias',
            'Sí',
            'Dale',
            'Pasame el saldo de Pérez',
            '¿Cómo viene la caja?',
            'Cancelá la venta de recién',
            'Cancelá el pedido 123',
            'Mandame el PDF de la factura',
            '¿A qué hora abre el banco?',
            'Armame una promo para mañana',
            'Cuánto me debe Juan',
            'Mostrame las ventas del viernes',
            '',
        ];

        foreach ($pedidos as $pedido) {
            $this->assertFalse(PedidoSobreTareasIaHelper::habla_de_tareas($pedido), 'No tendría que disparar: «' . $pedido . '».');
        }
    }

    /* ─────────────────────────────── integración: el modelo del payload ─────────────────────────────── */

    /**
     * 🔴 CA1. Un pedido de tareas del dueño: el turno corre con el modelo profundo, y el ágil no aparece en
     * ninguna llamada. (El dueño tiene configurado «ágil»: es lo que cambia.)
     *
     * @group asistente-whatsapp
     * @test
     */
    public function un_pedido_de_tareas_corre_con_el_modelo_profundo()
    {
        $this->assertNotSame(
            (string) config('services.deepseek.model_agil'),
            (string) config('services.deepseek.model_profundo'),
            'Para que el test mida algo, los dos modelos tienen que ser distintos.'
        );

        $this->el_dueno_escribe('Mañana a las 10 preguntame si ya llamé al proveedor');

        $this->assert_todas_las_vueltas_con('model_profundo', 'Un pedido de tareas tiene que correr con el modelo profundo');
    }

    /**
     * 🔴 CA1. TODAS las vueltas del turno van con el profundo, también las que siguen a una herramienta: el modelo
     * consulta las tareas y después contesta. (El reloj queda suelto: el servicio mide el presupuesto del turno con
     * time() y un reloj fijo lo rompe en los tests con herramientas.)
     *
     * @group asistente-whatsapp
     * @test
     */
    public function todas_las_vueltas_del_turno_van_con_el_profundo()
    {
        Carbon::setTestNow(null);

        $this->instalar_el_doble_del_modelo();

        // Cuántas veces contestó el doble: la primera pide la herramienta, las siguientes cierran con texto.
        $llamadas = 0;

        $this->comportamiento_del_modelo = function () use (&$llamadas) {
            $llamadas++;

            if ($llamadas > 1) {
                return Http::response([
                    'model'       => 'deepseek-flash-test',
                    'stop_reason' => 'end_turn',
                    'content'     => [['type' => 'text', 'text' => 'No tenés tareas programadas.']],
                    'usage'       => ['input_tokens' => 300, 'output_tokens' => 15],
                ], 200);
            }

            return Http::response([
                'model'       => 'deepseek-flash-test',
                'stop_reason' => 'tool_use',
                'content'     => [['type' => 'tool_use', 'id' => 'toolu_' . uniqid(), 'name' => 'consultar_tareas_del_asistente', 'input' => new \stdClass()]],
                'usage'       => ['input_tokens' => 300, 'output_tokens' => 30],
            ], 200);
        };

        $respuesta = $this->mandar(['texto' => '¿Qué tenés programado?', 'whatsapp_message_id' => 'wamid.DUENO' . uniqid()]);
        $respuesta->assertStatus(202);

        (new ResponderMensajeChatIaJob((int) $respuesta->json('ai_message_id')))->handle();

        $this->assertGreaterThanOrEqual(2, count($this->modelos_del_turno()), 'El turno tenía que dar al menos dos vueltas (herramienta y respuesta).');

        $this->assert_todas_las_vueltas_con('model_profundo', 'Todas las vueltas de un pedido de tareas van con el profundo');
    }

    /**
     * 🔴 CA3. Un pedido común del dueño sigue con el modelo ágil: no se encarece lo que no habla de tareas.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function un_pedido_comun_sigue_con_el_modelo_agil()
    {
        $this->el_dueno_escribe('¿Cuánto vendí hoy?');

        $this->assert_todas_las_vueltas_con('model_agil', 'Un pedido común sigue con el modelo ágil');
    }

    /**
     * 🔴 CA5. Al empleado no se le ofrecen las herramientas de tareas: aunque el texto hable de tareas, su turno
     * sigue con el modelo ágil.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_empleado_sigue_con_el_modelo_agil()
    {
        $conversacion = AiConversation::create([
            'user_id'         => $this->comercio->id,
            'auth_user_id'    => $this->empleado->id,
            'origen'          => AiConversation::ORIGEN_WHATSAPP,
            'last_message_at' => Carbon::now(),
        ]);

        $this->turno_a_mano($conversacion, 'Mañana a las 10 preguntame si ya llamé al proveedor');

        $this->assert_todas_las_vueltas_con('model_agil', 'El turno del empleado sigue con el modelo ágil');
    }

    /**
     * 🔴 CA5. Un dueño sin Agentes de IA no tiene las herramientas de tareas: el turno ni siquiera llega al modelo
     * (el servicio corta antes con «La cuenta no tiene habilitado el asistente») y, en todo caso, ninguna llamada
     * pide el profundo.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function sin_agentes_de_ia_no_se_pide_el_modelo_profundo()
    {
        $this->comercio->extencions()->detach();
        $this->comercio->unsetRelation('extencions');
        $this->comercio->load('extencions');

        $profundo = (string) config('services.deepseek.model_profundo');

        $turno = $this->turno_a_mano($this->conversacion_whatsapp(), 'Mañana a las 10 preguntame si ya llamé al proveedor');

        $this->assertSame('error', $turno->estado, 'Sin la extensión el turno termina en error antes de llamar al modelo.');

        foreach ($this->modelos_del_turno() as $modelo) {
            $this->assertNotSame($profundo, $modelo, 'Sin Agentes de IA no se pide el modelo profundo.');
        }
    }

    /**
     * 🔴 CA5. El turno desatendido (lo despertó una tarea) sigue como hoy aunque su instrucción hable de tareas.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_turno_desatendido_sigue_con_el_modelo_agil()
    {
        $this->modelo_contesta('Listo, ya lo miré.');

        $tarea = $this->tarea_del_asistente([
            'instruccion'          => 'Mañana a las 10 preguntame si ya llamé al proveedor',
            'fecha_inicio'         => '2026-11-21',
            'hora'                 => '10:01',
            'franja'               => null,
            'proxima_ejecucion_at' => '2026-11-21 10:01:00',
        ]);

        $this->despertar();

        $ejecuciones = $this->ejecuciones($tarea->id);

        $this->assertCount(1, $ejecuciones, 'El comando no despertó la tarea.');

        (new ResponderMensajeChatIaJob((int) $ejecuciones[0]->ai_message_id))->handle();

        $this->assert_todas_las_vueltas_con('model_agil', 'El turno desatendido sigue con el modelo ágil');
    }

    /**
     * 🔴 CA4. Un «sí» suelto que contesta a una pregunta del asistente sobre tareas («¿La cancelo?») también corre
     * con el modelo profundo.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function un_si_a_una_pregunta_sobre_tareas_corre_con_el_profundo()
    {
        $this->conversacion_con_pregunta(
            'Cancelá lo de logística',
            'Encontré la tarea «Preguntas de logística», programada para el viernes. ¿La cancelo?'
        );

        $this->el_dueno_escribe('Sí');

        $this->assert_todas_las_vueltas_con('model_profundo', 'El sí a una pregunta sobre tareas corre con el modelo profundo');
    }

    /**
     * 🔴 CA4. Un «sí» a una pregunta que NO es sobre tareas («¿Querés el detalle por vendedor?») sigue con el modelo ágil.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function un_si_a_otra_pregunta_sigue_con_el_modelo_agil()
    {
        $this->conversacion_con_pregunta(
            '¿Cuánto vendí ayer?',
            'Ayer vendiste $50.000 en total. ¿Querés que te muestre el detalle por vendedor?'
        );

        $this->el_dueno_escribe('Sí');

        $this->assert_todas_las_vueltas_con('model_agil', 'El sí a otra pregunta sigue con el modelo ágil');
    }
}
