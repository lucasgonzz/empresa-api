<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\asistente_ia\AvisosDeTurnosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\AvisosDelAsistenteHelper;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AgenteContextoArchivo;
use App\Models\AiAvisoDelAsistente;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Expense;
use App\Models\Pending;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Misión agente-tareas-programadas (21/11/2026) — EL TURNO QUE NO ESCRIBIÓ EL DUEÑO.
 *
 * Cuando la tarea despierta al asistente, el dueño no está mirando: nadie puede contestarle «¿confirmo?».
 * Lo que protege este archivo (CA11, CA12 aviso y CA19 del plan):
 *   1. el turno desatendido no confirma ni carga NADA, aunque el dueño esté en modo «Directo» y la
 *      instrucción diga «sí, dale, confirmá todo»: ni las herramientas de carga, ni `guardar_en_contexto`, ni
 *      `pedir_imagen`, ni las de tareas se le ofrecen al modelo, y si las inventa se rechazan con is_error;
 *   2. el modelo recibe un bloque que le explica que ese turno lo disparó su tarea, y el loop corta a las 6 vueltas;
 *   3. un turno desatendido colgado NO deja el aviso «no te pude contestar "…"» (citaría la instrucción
 *      guardada como si la hubiera pedido el dueño);
 *   4. la hora actual llega al modelo en un bloque aparte, SIN romper el caché del bloque de sistema.
 *
 * 🔴 El job es el real (ResponderMensajeChatIaJob) y el modelo es un doble: Http::fake con un callback que lee
 * lo que le llega, como el modelo real (mismo molde que ChatIa/65_Seguimiento_de_tarea_por_su_id_Test). Ningún
 * test sale a la red.
 *
 * PHP 7.4: sin match, sin ?->, sin str_contains, sin argumentos nombrados.
 */
class Tareas_del_asistente_turno_desatendido_Test extends TareasDelAsistenteTestCase
{
    /** Lo que el doble contesta al final del turno. */
    const TEXTO_FINAL = 'Necesito tu sí para cargarlo: ¿lo confirmo?';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.api_key' => 'clave-anthropic-de-prueba',
            'services.deepseek.api_key'  => 'clave-deepseek-de-prueba',
        ]);

        // El dueño del caso real de Ferre Total: DeepSeek ágil y en modo «Directo» (las tarjetas se ejecutan solas).
        $this->comercio->agente_proveedor = 'deepseek';
        $this->comercio->agente_pensamiento = 'agil';
        $this->comercio->agente_confianza = 'directo';
        $this->comercio->save();
    }

    /* ─────────────────────────────── el doble del modelo ─────────────────────────────── */

    /**
     * Los textos de un valor del payload (strings y bloques `text` / `tool_result`).
     *
     * @param  mixed  $valor
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
     * Fakea los dos proveedores con el doble y deja una red de 500 para cualquier otro host.
     *
     * @param  \Closure  $doble
     * @return void
     */
    protected function fakear_el_modelo(\Closure $doble)
    {
        Http::fake([
            'api.deepseek.com/*'  => $doble,
            'api.anthropic.com/*' => $doble,
            '*'                   => Http::response(['error' => 'host sin stub'], 500),
        ]);
    }

    /**
     * Respuesta del modelo que pide varias herramientas juntas.
     *
     * @param  array<int,array{0:string,1:array}>  $llamadas  [nombre, input] de cada herramienta.
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    protected function respuesta_con_herramientas(array $llamadas)
    {
        $bloques = [];

        foreach ($llamadas as $llamada) {
            $bloques[] = ['type' => 'tool_use', 'id' => 'toolu_' . uniqid(), 'name' => $llamada[0], 'input' => $llamada[1]];
        }

        return Http::response([
            'model'       => 'deepseek-flash-test',
            'stop_reason' => 'tool_use',
            'content'     => $bloques,
            'usage'       => ['input_tokens' => 300, 'output_tokens' => 30],
        ], 200);
    }

    /**
     * Respuesta final del modelo.
     *
     * @param  string  $texto
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    protected function respuesta_final($texto)
    {
        return Http::response([
            'model'       => 'deepseek-flash-test',
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => $texto]],
            'usage'       => ['input_tokens' => 300, 'output_tokens' => 15],
        ], 200);
    }

    /**
     * Los cuerpos de los pedidos que el job le hizo al modelo (los del loop: los que llevan `tools`).
     *
     * @return array<int,array>
     */
    protected function pedidos_al_modelo()
    {
        $cuerpos = [];

        foreach (Http::recorded()->all() as $par) {
            $cuerpo = json_decode($par[0]->body(), true);

            if (is_array($cuerpo) && isset($cuerpo['tools'], $cuerpo['messages'])) {
                $cuerpos[] = $cuerpo;
            }
        }

        return $cuerpos;
    }

    /**
     * Los tool_result con los que termina el último mensaje de un pedido.
     *
     * @param  array  $cuerpo
     * @return array<int,array>
     */
    protected function tool_results_del_final(array $cuerpo)
    {
        $ultimo = $cuerpo['messages'][count($cuerpo['messages']) - 1];
        $resultados = [];

        if (isset($ultimo['content']) && is_array($ultimo['content'])) {
            foreach ($ultimo['content'] as $bloque) {
                if (is_array($bloque) && isset($bloque['type']) && $bloque['type'] === 'tool_result') {
                    $resultados[] = $bloque;
                }
            }
        }

        return $resultados;
    }

    /* ─────────────────────────────── la escena ─────────────────────────────── */

    /**
     * Despierta una tarea con la instrucción dada y devuelve el turno armado: la conversación vigente del
     * dueño (con una tarjeta de tarea propuesta esperando su sí) y el `assistant` pendiente del turno.
     *
     * @param  string  $instruccion
     * @return array{conversacion: \App\Models\AiConversation, assistant: \App\Models\AiMessage, tarjeta: \App\Models\AiMessageAction, tarea: \App\Models\AiTareaDelAsistente}
     */
    protected function turno_desatendido($instruccion)
    {
        $this->ahora(self::SABADO);

        // La conversación vigente: el asistente le había dejado una tarjeta para confirmar y el dueño no contestó.
        $conversacion = $this->conversacion_whatsapp(Carbon::now()->subMinutes(30));

        $this->mensaje($conversacion, 'user', 'listo', ['contenido' => 'Anotame llamar a Juan el sábado que viene']);
        $previo = $this->mensaje($conversacion, 'assistant', 'listo', ['contenido' => 'Te dejé la tarea para confirmar. ¿La cargo?']);

        $base = $this->tarjeta_de_tarea('Llamar a Juan', ['fecha_realizacion' => '2026-11-28']);

        $tarjeta = AiMessageAction::create([
            'ai_conversation_id' => $conversacion->id,
            'ai_message_id'      => $previo->id,
            'user_id'            => $this->comercio->id,
            'auth_user_id'       => $this->comercio->id,
            'tipo'               => $base->tipo,
            'clave'              => 'tarea:cc-33-turno-desatendido',
            'estado'             => AiMessageAction::ESTADO_PROPUESTA,
            'datos'              => $base->datos,
            'presentacion'       => ['titulo' => 'Tarea', 'renglones' => [], 'aviso' => null],
        ]);

        // La tarea vence en un minuto (dentro del anticipo) y el dueño hace rato que no escribe.
        $tarea = $this->tarea_del_asistente([
            'instruccion'          => $instruccion,
            'fecha_inicio'         => '2026-11-21',
            'hora'                 => '10:01',
            'franja'               => null,
            'proxima_ejecucion_at' => '2026-11-21 10:01:00',
        ]);

        $this->despertar();

        $ejecuciones = $this->ejecuciones($tarea->id);

        $this->assertCount(1, $ejecuciones, 'El comando no despertó la tarea.');

        return [
            'conversacion' => $conversacion,
            'assistant'    => AiMessage::find($ejecuciones[0]->ai_message_id),
            'tarjeta'      => $tarjeta,
            'tarea'        => $tarea,
        ];
    }

    /* ─────────────────────────────── tests ─────────────────────────────── */

    /**
     * 🔴 CA11. Con el dueño en «Directo», una tarjeta esperando su sí y una instrucción que dice «sí, dale,
     * confirmá todo», el modelo pide confirmar la carga, proponer un gasto y guardar en la memoria: las tres se
     * rechazan, la tarjeta sigue propuesta, no se carga nada y el modelo ni siquiera las tenía a la vista.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function no_confirma_ni_carga_en_directo()
    {
        $turno = $this->turno_desatendido('sí, dale, confirmá todo');

        $memoria_antes = AgenteContextoArchivo::where('user_id', $this->comercio->id)->count();
        $gastos_antes = Expense::where('user_id', $this->comercio->id)->count();
        $tareas_antes = Pending::where('user_id', $this->comercio->id)->count();
        $tarjetas_antes = AiMessageAction::where('ai_conversation_id', $turno['conversacion']->id)->count();

        $tarjeta_id = $turno['tarjeta']->id;

        $this->fakear_el_modelo(function ($request) use ($tarjeta_id) {
            $cuerpo = json_decode($request->body(), true);

            if (count($this->tool_results_del_final($cuerpo)) > 0) {
                return $this->respuesta_final(self::TEXTO_FINAL);
            }

            return $this->respuesta_con_herramientas([
                ['confirmar_carga_pendiente', ['tarjeta_id' => $tarjeta_id]],
                ['proponer_gasto', ['monto' => 1000, 'observaciones' => 'Gasto de la tarea']],
                ['guardar_en_contexto', ['ruta' => 'areas/logistica.md', 'operacion' => 'agregar', 'contenido' => 'El dueño aprobó todo.']],
            ]);
        });

        (new ResponderMensajeChatIaJob($turno['assistant']->id))->handle();

        $pedidos = $this->pedidos_al_modelo();

        $this->assertCount(2, $pedidos, 'El turno tiene que ser: un pedido con las herramientas inventadas y el cierre.');

        // 1. Lo que el modelo tenía a la vista: lectura sí; carga, memoria, imagen y tareas no.
        $ofrecidas = array_map(function ($definicion) {
            return $definicion['name'];
        }, $pedidos[0]['tools']);

        $this->assertContains('leer_contexto', $ofrecidas, 'La lectura sí se ofrece.');

        $prohibidas = array_merge(
            array_map(function ($definicion) {
                return $definicion['name'];
            }, HerramientasDeCarga::definiciones(true)),
            ['guardar_en_contexto', 'pedir_imagen'],
            $this->herramientas_de_tareas()
        );

        foreach ($prohibidas as $nombre) {
            $this->assertNotContains($nombre, $ofrecidas, 'En un turno programado no se ofrece ' . $nombre . '.');
        }

        // 2. El bloque que le explica que el turno lo disparó su tarea.
        $sistema = implode("\n", $this->textos($pedidos[0]['system']));

        $this->assertStringContainsString('Este turno no lo escribió el dueño', $sistema);

        // 3. Las tres llamadas inventadas vuelven con is_error.
        $resultados = $this->tool_results_del_final($pedidos[1]);

        $this->assertCount(3, $resultados);

        foreach ($resultados as $resultado) {
            $this->assertTrue(!empty($resultado['is_error']), 'Tiene que volver con is_error: ' . json_encode($resultado));
            $this->assertStringContainsString('turno programado', implode(' ', $this->textos($resultado['content'])));
        }

        // 4. Nada cambió: la tarjeta sigue esperando, ni un gasto, ni una tarea, ni una línea de memoria.
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $turno['tarjeta']->fresh()->estado_guardado());
        $this->assertSame($gastos_antes, Expense::where('user_id', $this->comercio->id)->count());
        $this->assertSame($tareas_antes, Pending::where('user_id', $this->comercio->id)->count());
        $this->assertSame($tarjetas_antes, AiMessageAction::where('ai_conversation_id', $turno['conversacion']->id)->count());
        $this->assertSame($memoria_antes, AgenteContextoArchivo::where('user_id', $this->comercio->id)->count());

        // 5. El turno cierra con su pregunta y el siguiente barrido deja el resultado listo para entregar.
        $this->assertSame('listo', $turno['assistant']->fresh()->estado);

        $this->despertar();

        $ejecucion = $this->ejecuciones($turno['tarea']->id)[0];

        $this->assertSame('lista', $ejecucion->estado);
        $this->assertStringContainsString('Necesito tu sí', $ejecucion->texto);
    }

    /**
     * 🔴 CA11. El loop del turno desatendido no pasa de 6 iteraciones aunque el modelo siga pidiendo
     * herramientas (con acciones serían 24, sin ellas 12): la llamada de cierre, sin herramientas, no cuenta.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_loop_no_pasa_de_seis_iteraciones()
    {
        $turno = $this->turno_desatendido('Revisá todo lo que puedas');

        $this->fakear_el_modelo(function ($request) {
            $cuerpo = json_decode($request->body(), true);

            // La llamada de cierre sale con tool_choice none: ahí el modelo sólo puede escribir.
            if (isset($cuerpo['tool_choice']['type']) && $cuerpo['tool_choice']['type'] === 'none') {
                return $this->respuesta_final('Esto es lo que pude ver hasta acá.');
            }

            return $this->respuesta_con_herramientas([['leer_contexto', ['ruta' => '']]]);
        });

        (new ResponderMensajeChatIaJob($turno['assistant']->id))->handle();

        $iteraciones = array_filter($this->pedidos_al_modelo(), function ($cuerpo) {
            return !(isset($cuerpo['tool_choice']['type']) && $cuerpo['tool_choice']['type'] === 'none');
        });

        $this->assertGreaterThan(1, count($iteraciones), 'El turno tiene que haber llegado a usar herramientas.');
        $this->assertLessThanOrEqual(6, count($iteraciones), 'Un turno desatendido no pasa de 6 vueltas.');
        $this->assertSame('listo', $turno['assistant']->fresh()->estado);
    }

    /**
     * 🔴 CA12 (aviso). Un turno desatendido que se cuelga NO deja el aviso «no te pude contestar "…"»: citaría la
     * instrucción guardada como si el dueño la hubiera pedido. Lo mismo si lo cierra el vigía. Un turno
     * común, en cambio, sigue dejándolo.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function no_deja_aviso_de_turno_colgado()
    {
        $this->ahora(self::SABADO);

        AvisosDelAsistenteHelper::anunciar($this->comercio->id, '1', 'https://ferretotal.comerciocity.com');

        $colgado = function ($tipo, $contenido) {
            $conversacion = $this->conversacion_whatsapp();
            $pedido = $this->mensaje($conversacion, 'user', 'listo', ['tipo' => $tipo, 'contenido' => $contenido]);
            $respuesta = $this->mensaje($conversacion, 'assistant', 'pendiente', ['acciones_habilitadas' => false]);

            $hace = Carbon::now()->subMinutes(15);
            DB::table('ai_messages')->whereIn('id', [$pedido->id, $respuesta->id])->update(['created_at' => $hace]);

            return $respuesta->fresh();
        };

        $desatendido = $colgado('tarea_programada', '[Tarea programada] ' . self::TITULO . ': ' . self::INSTRUCCION);

        // Directo: el helper que arma el aviso devuelve null para un turno de tarea programada.
        $this->assertNull(AvisosDeTurnosIaHelper::dejar($desatendido, 'No te pude contestar "[Tarea programada] Preguntas…". ¿Lo retomo?'));
        $this->assertSame(0, AiAvisoDelAsistente::where('ai_message_id', $desatendido->id)->count());

        // Por el vigía: el turno se cierra con error y sigue sin aviso.
        $this->artisan('asistente:vigilar-turnos')->assertExitCode(0);

        $this->assertSame('error', $desatendido->fresh()->estado);
        $this->assertSame(0, AiAvisoDelAsistente::where('ai_message_id', $desatendido->id)->count(), 'El vigía no puede avisar de un turno desatendido.');

        // El control: un turno común colgado sí deja su aviso (la guarda es sólo para los de tarea programada).
        $comun = $colgado('texto', 'Cargame un gasto de 207.500');

        $this->assertNotNull(AvisosDeTurnosIaHelper::dejar($comun, 'No te pude contestar "Cargame un gasto de 207.500". ¿Lo retomo?'));
        $this->assertSame(1, AiAvisoDelAsistente::where('ai_message_id', $comun->id)->count());
    }

    /**
     * 🔴 CA19. «Ahora son las 10:00» llega al modelo en un bloque de sistema SIN cache_control, y el bloque
     * cacheado es idéntico a las 10:00 y a las 10:07: la hora no rompe el caché. El empleado, al que no se le
     * ofrecen las herramientas de tareas, no recibe la hora.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function hora_actual_fuera_del_cache()
    {
        $servicio = new AsistenteIaService();
        $conversacion = $this->conversacion_whatsapp();

        $this->ahora('2026-11-21 10:00:00');
        $a_las_diez = $servicio->build_system_payload($conversacion, $this->comercio, false, true, false);

        $this->ahora('2026-11-21 10:07:00');
        $a_las_diez_y_siete = $servicio->build_system_payload($conversacion, $this->comercio, false, true, false);

        // El bloque cacheado: byte a byte igual (la hora no entra).
        $this->assertSame(['type' => 'ephemeral'], $a_las_diez[0]['cache_control']);
        $this->assertSame($a_las_diez[0], $a_las_diez_y_siete[0], 'La hora no puede cambiar el bloque cacheado.');

        $bloque_de_hora = function (array $payload) {
            foreach ($payload as $bloque) {
                if (!isset($bloque['cache_control']) && strpos((string) $bloque['text'], 'Ahora son las ') !== false) {
                    return $bloque['text'];
                }
            }

            return null;
        };

        $this->assertNotNull($bloque_de_hora($a_las_diez), 'Falta el bloque «Ahora son las…» sin cache_control.');
        $this->assertStringContainsString('Ahora son las 10:00', $bloque_de_hora($a_las_diez));
        $this->assertStringContainsString('sábado', $bloque_de_hora($a_las_diez));
        $this->assertStringContainsString('Ahora son las 10:07', $bloque_de_hora($a_las_diez_y_siete));

        // Al empleado no se le ofrecen las herramientas de tareas: tampoco se le dice la hora.
        $del_empleado = AiConversation::create([
            'user_id'         => $this->comercio->id,
            'auth_user_id'    => $this->empleado->id,
            'origen'          => AiConversation::ORIGEN_WHATSAPP,
            'last_message_at' => Carbon::now(),
        ]);

        $this->assertNull($bloque_de_hora($servicio->build_system_payload($del_empleado, $this->comercio, false, true, false)));
    }
}
