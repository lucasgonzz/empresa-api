<?php

namespace Tests\Feature\ChatIa;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\ExtencionEmpresa;
use App\Models\Pending;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Agenda\AgendaTestCase;

/**
 * Misión asistente-seguimiento-de-tarea (10/10/2026) — el seguimiento de una tarea que el asistente
 * acaba de agendar ("pasala al miércoles", "ya la hice").
 *
 * El defecto (demo, 4.3.8, DeepSeek ágil, modo resuelto): "Recordame el martes llamar a Herramientas
 * del Interior" → se confirma → "Pasala al miércoles" → "el sistema no encontró esa tarea en la
 * agenda". Medido contra DeepSeek real el mismo día: el modelo llamó a proponer_cambios_en_tarea con
 * `tarea_id` = el #N de la TARJETA, porque la línea `[Tarjeta #N · …]` del historial no traía el id de
 * la tarea (el resultado de la confirmación era solo {texto, ruta}).
 *
 * Lo que protege:
 * - que la línea de historial de una tarjeta de tarea lleve `tarea_id` (y que las demás queden igual);
 * - que los tres ejecutores de tareas devuelvan `tarea_id` en el resultado;
 * - que un `tarea_id` que no es de ninguna tarea NO arme nada y traiga la agenda para reintentar, y que
 *   si el número es el de una tarjeta de tarea de la conversación, nombre el id de la tarea buena;
 * - que nada de eso muestre tareas de otro comercio.
 *
 * 🔴 El "modelo" es un doble (Http::fake con un callback) que LEE el historial que le llega, como el
 * modelo real: si la línea de la tarjeta trae `tarea_id`, lo usa; si no, usa el #N de la tarjeta, que
 * es el id equivocado que mandó DeepSeek. Ningún test sale a la red.
 *
 * @group chat-ia
 */
class Seguimiento_de_tarea_por_su_id_Test extends AgendaTestCase
{
    /** Lo que el doble contesta cuando la tarjeta de cambios quedó armada. */
    const TEXTO_FINAL = 'Te dejé la tarjeta para pasarla al miércoles.';

    /** @var User */
    protected $dueno;

    /** @var AsistenteIaService */
    protected $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.api_key' => 'clave-anthropic-de-prueba',
            'services.deepseek.api_key'  => 'clave-deepseek-de-prueba',
        ]);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $extencion = ExtencionEmpresa::where('slug', 'asistente_ia')->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => 'asistente_ia', 'name' => 'Asistente IA']);
        }

        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);

        /*
         * La configuración de la demo donde se midió el defecto. En 'resuelto' las tarjetas de la
         * agenda quedan para confirmar (solo 'directo' las ejecuta solas), que es lo que el test mira.
         */
        $this->dueno->agente_proveedor = 'deepseek';
        $this->dueno->agente_pensamiento = 'agil';
        $this->dueno->agente_confianza = 'resuelto';
        $this->dueno->save();

        $this->service = new AsistenteIaService();
    }

    // -------------------------------------------------------------------------------------------
    // Piezas
    // -------------------------------------------------------------------------------------------

    /**
     * El próximo martes (nunca hoy) y el miércoles que le sigue.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function martes_y_miercoles()
    {
        $martes = Carbon::today()->next(Carbon::TUESDAY);

        return [$martes, $martes->copy()->addDay()];
    }

    /**
     * Conversación con el primer pedido del dueño y el assistant que lo contesta.
     *
     * @param string $pedido
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion($pedido)
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->dueno->id,
            'auth_user_id' => $this->dueno->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => $pedido,
        ]);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant];
    }

    /**
     * Un pedido nuevo del dueño en la conversación y el assistant pendiente que lo va a contestar.
     *
     * @param AiConversation $conversation
     * @param string $pedido
     * @return AiMessage
     */
    protected function nuevo_turno($conversation, $pedido)
    {
        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => $pedido,
        ]);

        return AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);
    }

    /**
     * Ejecuta una herramienta de carga como si la hubiera pedido el modelo y devuelve su JSON.
     *
     * @param AiConversation $conversation
     * @param AiMessage $assistant
     * @param string $herramienta
     * @param array $input
     * @return array
     */
    protected function herramienta($conversation, $assistant, $herramienta, array $input)
    {
        $resultados = $this->service->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_' . uniqid(),
            'name'  => $herramienta,
            'input' => $input,
        ]], $conversation, $assistant);

        $this->assertArrayNotHasKey('is_error', $resultados[0], 'La herramienta devolvió una falla técnica: ' . $resultados[0]['content']);

        return json_decode($resultados[0]['content'], true);
    }

    /**
     * Deja el assistant en listo y confirma la tarjeta por el endpoint de la pantalla.
     *
     * @param AiConversation $conversation
     * @param AiMessage $assistant
     * @param int $tarjeta_id
     * @return \Illuminate\Testing\TestResponse
     */
    protected function confirmar($conversation, $assistant, $tarjeta_id)
    {
        $assistant->contenido = 'Te dejé la tarjeta para confirmar.';
        $assistant->estado = 'listo';
        $assistant->save();

        return $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $tarjeta_id . '/confirmar');
    }

    /**
     * El turno 1 de la demo: el asistente propone la tarea del martes y el dueño la confirma.
     *
     * @return array{conversation: AiConversation, tarjeta: AiMessageAction, tarea: Pending, miercoles: Carbon, confirmacion: \Illuminate\Testing\TestResponse}
     */
    protected function tarea_del_martes_confirmada()
    {
        list($martes, $miercoles) = $this->martes_y_miercoles();

        list($conversation, $assistant) = $this->conversacion('Recordame el martes llamar a Herramientas del Interior P63');

        $propuesta = $this->herramienta($conversation, $assistant, 'proponer_tarea', [
            'detalle' => 'Llamar a Herramientas del Interior P63',
            'fecha'   => $martes->format('Y-m-d'),
        ]);

        $this->assertTrue($propuesta['ok'], json_encode($propuesta));

        $confirmar = $this->confirmar($conversation, $assistant, $propuesta['tarjeta_id']);

        $confirmar->assertStatus(200);

        $tarea = Pending::where('user_id', $this->dueno->id)->where('detalle', 'Llamar a Herramientas del Interior P63')->first();

        $this->assertNotNull($tarea);
        $this->tareas_creadas[] = $tarea->id;

        return [
            'conversation' => $conversation,
            'tarjeta'      => AiMessageAction::find($propuesta['tarjeta_id']),
            'tarea'        => $tarea,
            'miercoles'    => $miercoles,
            'confirmacion' => $confirmar,
        ];
    }

    /**
     * Todos los textos de un valor del payload (contenidos en string y bloques `text`/`tool_result`).
     *
     * @param mixed $valor
     * @return array<int,string>
     */
    protected function textos($valor)
    {
        if (is_string($valor)) {
            return [$valor];
        }

        if (!is_array($valor)) {
            return [];
        }

        $textos = [];

        foreach ($valor as $clave => $hijo) {
            if (in_array($clave, ['content', 'text'], true) || is_int($clave)) {
                $textos = array_merge($textos, $this->textos($hijo));
            }
        }

        return $textos;
    }

    /**
     * El contenido del tool_result con el que termina el último mensaje, o null si no termina en uno.
     *
     * @param array $mensaje
     * @return string|null
     */
    protected function tool_result_final(array $mensaje)
    {
        if (!isset($mensaje['content']) || !is_array($mensaje['content'])) {
            return null;
        }

        $resultado = null;

        foreach ($mensaje['content'] as $bloque) {
            if (is_array($bloque) && isset($bloque['type']) && $bloque['type'] === 'tool_result') {
                $resultado = implode('', $this->textos($bloque['content']));
            }
        }

        return $resultado;
    }

    /**
     * @param string $nombre
     * @param array $input
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    protected function respuesta_tool_use($nombre, array $input)
    {
        return Http::response([
            'model'       => 'deepseek-flash-test',
            'stop_reason' => 'tool_use',
            'content'     => [[
                'type'  => 'tool_use',
                'id'    => 'toolu_' . uniqid(),
                'name'  => $nombre,
                'input' => $input,
            ]],
            'usage'       => ['input_tokens' => 300, 'output_tokens' => 30],
        ], 200);
    }

    /**
     * @param string $texto
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    protected function respuesta_end_turn($texto)
    {
        return Http::response([
            'model'       => 'deepseek-flash-test',
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => $texto]],
            'usage'       => ['input_tokens' => 300, 'output_tokens' => 15],
        ], 200);
    }

    /**
     * El doble del modelo para "Pasala al miércoles".
     *
     * Primera vuelta: busca en el historial la línea de la tarjeta de la tarea. Si trae `tarea_id` y
     * el doble lee el historial, lo usa; si no, manda el #N de la tarjeta — el id equivocado que mandó
     * DeepSeek en la demo. Después: con `ok` cierra; con un rechazo que trae opciones.tareas, elige la
     * tarea por su detalle y vuelve a llamar (lo que la descripción de la herramienta le pide); con
     * cualquier otro rechazo, lo cuenta tal cual y cierra (lo que hacía el modelo en la demo).
     *
     * @param Carbon $miercoles
     * @param bool $lee_el_tarea_id_del_historial false = un modelo que siempre arranca con el #N.
     * @return \Closure
     */
    protected function doble_del_modelo(Carbon $miercoles, $lee_el_tarea_id_del_historial = true)
    {
        $test = $this;
        $fecha = $miercoles->format('Y-m-d');

        return function ($request) use ($test, $fecha, $lee_el_tarea_id_del_historial) {

            $body = json_decode($request->body(), true);
            $mensajes = $body['messages'];

            $resultado = $test->tool_result_final($mensajes[count($mensajes) - 1]);

            if (!is_null($resultado)) {

                $json = json_decode($resultado, true);

                if (!empty($json['ok'])) {
                    return $test->respuesta_end_turn(self::TEXTO_FINAL);
                }

                if (isset($json['opciones']['tareas'])) {

                    $tareas = array_merge($json['opciones']['tareas']['vencidas'], $json['opciones']['tareas']['proximas']);

                    foreach ($tareas as $tarea) {
                        if (strpos($tarea['detalle'], 'Herramientas del Interior P63') !== false) {
                            return $test->respuesta_tool_use('proponer_cambios_en_tarea', ['tarea_id' => $tarea['tarea_id'], 'fecha' => $fecha]);
                        }
                    }
                }

                return $test->respuesta_end_turn('El cambio no se pudo proponer: ' . (isset($json['error']) ? $json['error'] : ''));
            }

            $historial = implode("\n", $test->textos($mensajes));

            if (!preg_match('/\[Tarjeta #(\d+) · Tarea en la agenda[^\]]*\]/u', $historial, $linea)) {
                return $test->respuesta_end_turn('No encontré la tarjeta en el historial.');
            }

            $tarea_id = (int) $linea[1];

            if ($lee_el_tarea_id_del_historial && preg_match('/ · tarea_id: (\d+) · /u', $linea[0], $id)) {
                $tarea_id = (int) $id[1];
            }

            return $test->respuesta_tool_use('proponer_cambios_en_tarea', ['tarea_id' => $tarea_id, 'fecha' => $fecha]);
        };
    }

    /**
     * Fakea los dos proveedores con el doble y deja una red para cualquier otro host.
     *
     * @param \Closure $doble
     * @return void
     */
    protected function fakear_el_modelo($doble)
    {
        Http::fake([
            'api.deepseek.com/*'  => $doble,
            'api.anthropic.com/*' => $doble,
            '*'                   => Http::response(['error' => 'host sin stub'], 500),
        ]);
    }

    /**
     * Las tarjetas de cambios en tarea de un mensaje.
     *
     * @param AiMessage $assistant
     * @return \Illuminate\Support\Collection
     */
    protected function tarjetas_de_cambios($assistant)
    {
        return AiMessageAction::where('ai_message_id', $assistant->id)
                                ->where('tipo', AiMessageAction::TIPO_TAREA_EDITAR)
                                ->get();
    }

    /**
     * El primer tool_result de un turno, decodificado (sale del segundo request al modelo).
     *
     * @param int $desde Cantidad de requests grabados antes del turno.
     * @return array
     */
    protected function primer_tool_result_del_turno($desde)
    {
        $grabados = Http::recorded()->all();

        $this->assertGreaterThan($desde + 1, count($grabados), 'El turno no llegó a devolverle un resultado al modelo.');

        $body = json_decode($grabados[$desde + 1][0]->body(), true);

        $mensajes = $body['messages'];

        return json_decode($this->tool_result_final($mensajes[count($mensajes) - 1]), true);
    }

    // -------------------------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------------------------

    /**
     * El caso de la demo, de punta a punta: con el `tarea_id` en la línea del historial, el modelo
     * que la lee manda el id bueno en la PRIMERA llamada y la tarjeta queda armada.
     *
     * Sin el arreglo la línea no tiene `tarea_id`, el doble manda el #N de la tarjeta y el turno
     * termina contando "no encontré esa tarea" — el texto que vio Lucas.
     *
     * @test
     */
    public function pasala_al_miercoles_mueve_la_tarea_recien_confirmada_con_el_id_del_historial()
    {
        $caso = $this->tarea_del_martes_confirmada();

        $assistant = $this->nuevo_turno($caso['conversation'], 'Pasala al miércoles');

        $this->fakear_el_modelo($this->doble_del_modelo($caso['miercoles']));

        $texto = $this->service->responder($caso['conversation'], $assistant);

        // Sin el arreglo, acá el turno cierra con "El cambio no se pudo proponer: No encontré esa tarea en tu agenda."
        $this->assertEquals(self::TEXTO_FINAL, $texto);

        $grabados = Http::recorded()->all();

        // La línea que leyó el modelo trae el id de la tarea antes del estado.
        $historial = implode("\n", $this->textos(json_decode($grabados[0][0]->body(), true)['messages']));
        $this->assertStringContainsString(' · tarea_id: ' . $caso['tarea']->id . ' · estado: confirmada (', $historial);

        // Una llamada con el id bueno y el cierre: no hubo intento fallido.
        $this->assertCount(2, $grabados, 'El modelo tendría que acertar a la primera.');

        $tarjetas = $this->tarjetas_de_cambios($assistant);

        $this->assertCount(1, $tarjetas);
        $this->assertEquals('propuesta', $tarjetas[0]->estado);
        $this->assertSame($caso['tarea']->id, (int) $tarjetas[0]->datos['pending_id']);
        $this->assertEquals('Cambios en la tarea', $tarjetas[0]->presentacion['titulo']);
        $this->assertSame($caso['miercoles']->format('Y-m-d'), $tarjetas[0]->datos['fecha_realizacion']);

        // La tarea no se movió: es una tarjeta, la confirma la persona.
        $this->assertEquals(Carbon::parse($caso['tarea']->fecha_realizacion)->format('Y-m-d'), Carbon::parse($caso['tarea']->fresh()->fecha_realizacion)->format('Y-m-d'));

        // De dónde sale el dato de la línea: el resultado de la confirmación trae el id de la tarea creada.
        $this->assertSame($caso['tarea']->id, $caso['confirmacion']->json('model.resultado.tarea_id'));
    }

    /**
     * Un modelo que igual manda el #N de la tarjeta: el rechazo dice que no se armó nada, nombra el
     * `tarea_id` de la tarea de esa tarjeta, trae la agenda, y el segundo intento arma la tarjeta.
     *
     * @test
     */
    public function el_numero_de_la_tarjeta_como_tarea_id_no_arma_nada_y_el_rechazo_lleva_a_la_tarea_buena()
    {
        $caso = $this->tarea_del_martes_confirmada();

        /*
         * Si justo existe una tarea del dueño con el mismo número que la tarjeta, el #N encontraría
         * OTRA tarea y el caso no se reproduce. Se la saca dentro de la transacción del test (vuelve
         * sola con el rollback): lo que se prueba es el número que no es de ninguna tarea.
         */
        Pending::where('user_id', $this->dueno->id)->where('id', $caso['tarjeta']->id)->delete();

        $assistant = $this->nuevo_turno($caso['conversation'], 'Pasala al miércoles');

        $this->fakear_el_modelo($this->doble_del_modelo($caso['miercoles'], false));

        $texto = $this->service->responder($caso['conversation'], $assistant);

        $rechazo = $this->primer_tool_result_del_turno(0);

        $this->assertFalse($rechazo['ok']);
        $this->assertStringStartsWith('No se armó ninguna tarjeta: ninguna tarea de la agenda tiene el tarea_id ' . $caso['tarjeta']->id . '.', $rechazo['error']);
        $this->assertStringContainsString('es el de la tarjeta #' . $caso['tarjeta']->id, $rechazo['error']);
        $this->assertStringContainsString('su tarea_id es ' . $caso['tarea']->id . '.', $rechazo['error']);

        $proximas = array_column($rechazo['opciones']['tareas']['proximas'], 'tarea_id');
        $this->assertContains($caso['tarea']->id, $proximas, 'La agenda del rechazo trae la tarea del martes.');

        // El primer intento no dejó tarjeta; el segundo, una sola y sobre la tarea buena.
        $tarjetas = $this->tarjetas_de_cambios($assistant);

        $this->assertCount(1, $tarjetas);
        $this->assertSame($caso['tarea']->id, (int) $tarjetas[0]->datos['pending_id']);
        $this->assertCount(3, Http::recorded()->all());
        $this->assertEquals(self::TEXTO_FINAL, $texto);
    }

    /**
     * Un número que no es de ninguna tarea ni de ninguna tarjeta, en las dos herramientas que piden
     * `tarea_id`: no se arma nada, no se nombra ninguna tarjeta y viaja la agenda del dueño.
     *
     * @test
     */
    public function un_tarea_id_que_no_es_de_nadie_no_arma_tarjeta_y_trae_la_agenda_en_las_dos_herramientas()
    {
        $tarea = $this->crear_tarea([
            'detalle'           => 'Pagar la luz P63',
            'fecha_realizacion' => Carbon::today()->addDays(3)->format('Y-m-d'),
        ]);

        $inexistente = (int) Pending::max('id') + (int) AiMessageAction::max('id') + 1000;

        foreach (['proponer_cambios_en_tarea' => ['fecha' => Carbon::today()->addDays(4)->format('Y-m-d')], 'proponer_marcar_tarea_hecha' => []] as $herramienta => $input) {

            list($conversation, $assistant) = $this->conversacion('Movela');

            $respuesta = $this->herramienta($conversation, $assistant, $herramienta, array_merge(['tarea_id' => $inexistente], $input));

            $this->assertFalse($respuesta['ok'], $herramienta);
            $this->assertStringStartsWith('No se armó ninguna tarjeta: ninguna tarea de la agenda tiene el tarea_id ' . $inexistente . '.', $respuesta['error'], $herramienta);
            $this->assertStringNotContainsString('tarjeta #', $respuesta['error'], $herramienta . ': no es el número de ninguna tarjeta.');
            $this->assertStringContainsString('volvé a llamar con su tarea_id', $respuesta['error'], $herramienta);

            $this->assertArrayHasKey('vencidas', $respuesta['opciones']['tareas'], $herramienta);
            $this->assertContains($tarea->id, array_column($respuesta['opciones']['tareas']['proximas'], 'tarea_id'), $herramienta);

            $this->assertEquals(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count(), $herramienta . ': no se armó ninguna tarjeta.');
        }
    }

    /**
     * Ni el rechazo ni su pista cruzan comercios ni conversaciones: la tarea de otro dueño no se
     * encuentra ni aparece en las opciones, y el número de una tarjeta de OTRA conversación no se
     * nombra como tarjeta de esta.
     *
     * @test
     */
    public function el_rechazo_no_muestra_tareas_de_otro_comercio_ni_tarjetas_de_otra_conversacion()
    {
        $otro = User::create([
            'name'         => 'Otro comercio P63',
            'company_name' => 'Otro comercio P63',
            'email'        => 'otro-p63-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $ajena = Pending::create([
            'detalle'           => 'Tarea de otro comercio P63',
            'fecha_realizacion' => Carbon::today()->addDays(2)->format('Y-m-d'),
            'completado'        => 0,
            'es_recurrente'     => 0,
            'user_id'           => $otro->id,
        ]);

        list($conversation, $assistant) = $this->conversacion('Movela');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_cambios_en_tarea', [
            'tarea_id' => $ajena->id,
            'fecha'    => Carbon::today()->addDays(3)->format('Y-m-d'),
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringStartsWith('No se armó ninguna tarjeta', $respuesta['error']);

        $todas = array_merge($respuesta['opciones']['tareas']['vencidas'], $respuesta['opciones']['tareas']['proximas']);
        $this->assertNotContains($ajena->id, array_column($todas, 'tarea_id'));
        $this->assertStringNotContainsString('Tarea de otro comercio P63', json_encode($respuesta, JSON_UNESCAPED_UNICODE));

        // Una tarjeta de tarea de OTRA conversación del mismo dueño: su número no se nombra acá.
        $caso = $this->tarea_del_martes_confirmada();
        Pending::where('user_id', $this->dueno->id)->where('id', $caso['tarjeta']->id)->delete();

        list($otra_conversation, $otro_assistant) = $this->conversacion('Movela');

        $respuesta = $this->herramienta($otra_conversation, $otro_assistant, 'proponer_cambios_en_tarea', [
            'tarea_id' => $caso['tarjeta']->id,
            'fecha'    => $caso['miercoles']->format('Y-m-d'),
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringNotContainsString('tarjeta #', $respuesta['error'], 'La tarjeta es de otra conversación.');
    }

    /**
     * La línea de historial: `· tarea_id: N` solo en las tarjetas de tarea que lo tienen; todas las
     * demás —una tarea nueva sin confirmar, una confirmada antes de este arreglo, un gasto— quedan
     * exactamente como antes.
     *
     * @test
     */
    public function la_linea_de_historial_lleva_el_tarea_id_solo_en_las_tarjetas_de_tarea_que_lo_tienen()
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->dueno->id,
            'auth_user_id' => $this->dueno->id,
        ]);

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => 'agendame cosas']);

        $mensaje = AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'assistant', 'contenido' => 'Te dejé las tarjetas.']);

        $renglones = [['etiqueta' => 'Qué', 'valor' => 'Llamar a Herramientas del Interior'], ['etiqueta' => 'Cuándo', 'valor' => 'martes 13/10/2026']];
        $ruta = ['name' => 'pending', 'params' => new \stdClass(), 'texto' => 'Ver en la Agenda'];

        $tarjeta = function ($tipo, $estado, array $datos, $resultado, $titulo, array $renglones) use ($conversation, $mensaje) {
            return AiMessageAction::create([
                'ai_conversation_id' => $conversation->id,
                'ai_message_id'      => $mensaje->id,
                'user_id'            => $this->dueno->id,
                'auth_user_id'       => $this->dueno->id,
                'tipo'               => $tipo,
                'clave'              => $tipo . ':' . uniqid(),
                'estado'             => $estado,
                'datos'              => $datos,
                'presentacion'       => ['titulo' => $titulo, 'renglones' => $renglones, 'aviso' => null],
                'resultado'          => $resultado,
                'resuelta_at'        => is_null($resultado) ? null : now(),
            ]);
        };

        $nueva = $tarjeta('tarea_nueva', 'confirmada', [], ['texto' => 'Tarea agendada para el martes 13/10/2026', 'ruta' => $ruta, 'tarea_id' => 501], 'Tarea en la agenda', $renglones);
        $vieja = $tarjeta('tarea_nueva', 'confirmada', [], ['texto' => 'Tarea agendada para el martes 13/10/2026', 'ruta' => $ruta], 'Tarea en la agenda', $renglones);
        $sin_confirmar = $tarjeta('tarea_nueva', 'propuesta', [], null, 'Tarea en la agenda', $renglones);
        $cambios = $tarjeta('tarea_editar', 'propuesta', ['pending_id' => 502], null, 'Cambios en la tarea', [['etiqueta' => 'Tarea', 'valor' => 'Llamar'], ['etiqueta' => 'Cuándo', 'valor' => 'martes 13/10/2026 → miércoles 14/10/2026']]);
        $hecha = $tarjeta('tarea_completar', 'confirmada', ['pending_id' => 503], ['texto' => 'Tarea marcada como hecha', 'ruta' => $ruta, 'tarea_id' => 503], 'Marcar como hecha', [['etiqueta' => 'Tarea', 'valor' => 'Barrer']]);
        $gasto = $tarjeta('gasto', 'confirmada', [], ['texto' => 'Gasto N° 88 registrado', 'ruta' => null], 'Gasto', [['etiqueta' => 'Monto', 'valor' => '$ 5.000']]);

        $payload = $this->service->build_messages_payload($conversation);

        $lineas = explode("\n", $payload[1]['content']);

        $que = 'Qué: Llamar a Herramientas del Interior; Cuándo: martes 13/10/2026';

        $this->assertEquals('Te dejé las tarjetas.', $lineas[0]);
        $this->assertEquals('[Tarjeta #' . $nueva->id . ' · Tarea en la agenda · ' . $que . ' · tarea_id: 501 · estado: confirmada (Tarea agendada para el martes 13/10/2026)]', $lineas[1]);
        $this->assertEquals('[Tarjeta #' . $vieja->id . ' · Tarea en la agenda · ' . $que . ' · estado: confirmada (Tarea agendada para el martes 13/10/2026)]', $lineas[2], 'Una tarjeta confirmada antes del arreglo queda como siempre.');
        $this->assertEquals('[Tarjeta #' . $sin_confirmar->id . ' · Tarea en la agenda · ' . $que . ' · estado: propuesta]', $lineas[3], 'Sin confirmar todavía no hay tarea.');
        $this->assertEquals('[Tarjeta #' . $cambios->id . ' · Cambios en la tarea · Tarea: Llamar; Cuándo: martes 13/10/2026 → miércoles 14/10/2026 · tarea_id: 502 · estado: propuesta]', $lineas[4]);
        $this->assertEquals('[Tarjeta #' . $hecha->id . ' · Marcar como hecha · Tarea: Barrer · tarea_id: 503 · estado: confirmada (Tarea marcada como hecha)]', $lineas[5]);
        $this->assertEquals('[Tarjeta #' . $gasto->id . ' · Gasto · Monto: $ 5.000 · estado: confirmada (Gasto N° 88 registrado)]', $lineas[6], 'Las tarjetas que no son de tarea no cambian.');
    }

    /**
     * Los otros dos ejecutores de la agenda también devuelven el id de su tarea: cambiar la fecha y
     * marcarla hecha.
     *
     * @test
     */
    public function confirmar_cambios_y_marcar_hecha_devuelven_el_tarea_id()
    {
        $tarea = $this->crear_tarea([
            'detalle'           => 'Barrer el deposito P63',
            'fecha_realizacion' => Carbon::today()->format('Y-m-d'),
        ]);

        list($conversation, $assistant) = $this->conversacion('Pasala a mañana');

        $cambios = $this->herramienta($conversation, $assistant, 'proponer_cambios_en_tarea', [
            'tarea_id' => $tarea->id,
            'fecha'    => Carbon::today()->addDay()->format('Y-m-d'),
        ]);

        $this->assertTrue($cambios['ok'], json_encode($cambios));

        $confirmar = $this->confirmar($conversation, $assistant, $cambios['tarjeta_id']);

        $confirmar->assertStatus(200);
        $this->assertEquals('Tarea actualizada', $confirmar->json('model.resultado.texto'));
        $this->assertSame($tarea->id, $confirmar->json('model.resultado.tarea_id'));

        $assistant = $this->nuevo_turno($conversation, 'Ya la hice');

        $hecha = $this->herramienta($conversation, $assistant, 'proponer_marcar_tarea_hecha', [
            'tarea_id' => $tarea->id,
        ]);

        $this->assertTrue($hecha['ok'], json_encode($hecha));

        $confirmar = $this->confirmar($conversation, $assistant, $hecha['tarjeta_id']);

        $confirmar->assertStatus(200);
        $this->assertEquals('Tarea marcada como hecha', $confirmar->json('model.resultado.texto'));
        $this->assertSame($tarea->id, $confirmar->json('model.resultado.tarea_id'));
    }
}
