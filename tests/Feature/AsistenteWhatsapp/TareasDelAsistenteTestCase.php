<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiTareaDelAsistente;
use App\Models\AiTareaDelAsistenteEjecucion;
use App\Services\AsistenteIa\AsistenteIaService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Base de las suites de la misión agente-tareas-programadas (21/11/2026): el asistente personal tiene
 * tareas propias («el viernes a la tarde preguntame por la logística», «todos los días a primera hora
 * contame cómo vienen las ventas»), se despierta a la hora, corre un turno con la instrucción guardada y
 * el resultado le llega al dueño por WhatsApp (carril de recordatorios) y queda en la conversación.
 *
 * Extiende RecordatoriosTestCase para heredar la clave del admin, el dueño con la extensión `agentes_ia`,
 * `pendientes()` y `avisado()`; lo que suma es la escena de las tareas del asistente:
 *   - herramienta(): ejecuta una herramienta como si la hubiera pedido el modelo (la real, contra la base);
 *   - despertar(): corre el comando `asistente:despertar-tareas`;
 *   - tarea_del_asistente(): una fila de `ai_tareas_del_asistente`, tal como la deja la herramienta.
 *
 * 🔴 El reloj está fijo: cada test lo pone con ahora() al empezar (por defecto el sábado 21/11/2026 a las
 * 10:00, zona de la app) y tearDown() lo suelta con `null`: lo que se mide acá es «qué vence ahora», y con
 * el reloj de verdad cada test dependería de la hora a la que se corre.
 *
 * 🔴 La cola se fakea en TODOS los tests (phpunit.xml la deja en `sync`): sin eso el comando despertaría
 * el turno y el job llamaría al modelo en serio. Los tests que quieren el job real lo corren a mano, con
 * el modelo doblado por Http::fake.
 *
 * PHP 7.4: sin match, sin ?->, sin str_contains, sin argumentos nombrados.
 */
abstract class TareasDelAsistenteTestCase extends RecordatoriosTestCase
{
    /** "Ahora" por defecto en esta suite: un sábado a las 10:00. */
    const SABADO = '2026-11-21 10:00:00';

    /** Título de la tarea del ejemplo de Lucas. */
    const TITULO = 'Preguntas de logística';

    /** Instrucción de la tarea del ejemplo de Lucas. */
    const INSTRUCCION = 'Retomá las preguntas pendientes sobre logística';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ahora(self::SABADO);

        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    /* ─────────────────────────────── reloj ─────────────────────────────── */

    /**
     * Fija el reloj del test.
     *
     * @param  string|\Carbon\Carbon  $momento  Momento en la zona de la app (o un Carbon con su propia zona).
     * @return void
     */
    protected function ahora($momento)
    {
        Carbon::setTestNow($momento instanceof Carbon ? $momento : Carbon::parse($momento));
    }

    /* ─────────────────────────────── herramientas ─────────────────────────────── */

    /**
     * Ejecuta una herramienta como si la hubiera pedido el modelo: en una conversación de WhatsApp del
     * dueño, hablando la persona dada (null = el dueño), con la herramienta REAL y la base real.
     *
     * @param  string  $nombre  Nombre de la herramienta.
     * @param  array  $input  Lo que mandó el modelo.
     * @param  \App\Models\User|null  $persona  Quien habla (null = el dueño).
     * @return array{error: bool, texto: string, datos: array|null}  `error` = el tool_result vino con
     *                                                                is_error; `datos` = el JSON decodificado (null si no era JSON).
     */
    protected function herramienta($nombre, array $input = [], $persona = null)
    {
        $persona = is_null($persona) ? $this->comercio : $persona;

        $conversacion = AiConversation::create([
            'user_id'         => $this->comercio->id,
            'auth_user_id'    => $persona->id,
            'origen'          => AiConversation::ORIGEN_WHATSAPP,
            'last_message_at' => Carbon::now(),
        ]);

        $assistant = $this->mensaje($conversacion, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $resultados = (new AsistenteIaService())->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_' . uniqid(),
            'name'  => $nombre,
            'input' => $input,
        ]], $conversacion, $assistant);

        $contenido = isset($resultados[0]['content']) ? $resultados[0]['content'] : '';
        $texto = is_string($contenido) ? $contenido : json_encode($contenido);

        return [
            'error' => !empty($resultados[0]['is_error']),
            'texto' => $texto,
            'datos' => json_decode($texto, true),
        ];
    }

    /**
     * Las definiciones que `build_tools()` le ofrece al modelo, por nombre.
     *
     * @param  \App\Models\AiConversation  $conversacion  La conversación del turno.
     * @param  bool  $con_acciones  Si el turno tiene las herramientas de carga.
     * @return array<int,string>
     */
    protected function nombres_de_herramientas($conversacion, $con_acciones = false)
    {
        $herramientas = (new AsistenteIaService())->build_tools($con_acciones, true, $this->comercio, false, $conversacion);

        return array_map(function ($definicion) {
            return $definicion['name'];
        }, $herramientas);
    }

    /** Las cinco herramientas de tareas del asistente (plan §2.5). */
    protected function herramientas_de_tareas()
    {
        return [
            'programar_tarea_del_asistente',
            'reprogramar_tarea_del_asistente',
            'consultar_tareas_del_asistente',
            'cancelar_tarea_del_asistente',
            'guardar_horarios_del_dueno',
        ];
    }

    /* ─────────────────────────────── comando ─────────────────────────────── */

    /**
     * Corre `asistente:despertar-tareas` (el mismo comando que agenda el Kernel cada minuto).
     *
     * @return array{codigo: int, salida: string}
     */
    protected function despertar()
    {
        $codigo = Artisan::call('asistente:despertar-tareas');

        return ['codigo' => $codigo, 'salida' => Artisan::output()];
    }

    /* ─────────────────────────────── escena ─────────────────────────────── */

    /**
     * Una tarea del asistente en la base, tal como la deja la herramienta: una vez, 17:00, el viernes
     * 27/11/2026 (la del ejemplo de Lucas). Las columnas dadas pisan los valores por defecto.
     *
     * @param  array  $columnas  Columnas de `ai_tareas_del_asistente` a pisar.
     * @return \App\Models\AiTareaDelAsistente
     */
    protected function tarea_del_asistente(array $columnas = [])
    {
        return AiTareaDelAsistente::create(array_merge([
            'owner_id'             => $this->comercio->id,
            'auth_user_id'         => $this->comercio->id,
            'area'                 => 'general',
            'titulo'               => self::TITULO,
            'instruccion'          => self::INSTRUCCION,
            'recurrencia'          => 'una_vez',
            'dias'                 => null,
            'hora'                 => '17:00',
            'franja'               => 'tarde',
            'fecha_inicio'         => '2026-11-27',
            'fecha_fin'            => null,
            'zona_horaria'         => 'America/Argentina/Buenos_Aires',
            'proxima_ejecucion_at' => '2026-11-27 17:00:00',
            'estado'               => 'activa',
            'ejecuciones'          => 0,
            'fallos_seguidos'      => 0,
        ], $columnas))->fresh();
    }

    /**
     * La fila cruda de una tarea (strings tal cual los guarda la base, sin casts).
     *
     * @param  int  $tarea_id
     * @return object|null
     */
    protected function fila_de($tarea_id)
    {
        return DB::table('ai_tareas_del_asistente')->where('id', $tarea_id)->first();
    }

    /**
     * Las filas crudas de las ejecuciones del dueño (todas, o las de una tarea), de la más vieja a la más nueva.
     *
     * @param  int|null  $tarea_id
     * @return array<int,object>
     */
    protected function ejecuciones($tarea_id = null)
    {
        $consulta = DB::table('ai_tareas_del_asistente_ejecuciones')->where('owner_id', $this->comercio->id);

        if (!is_null($tarea_id)) {
            $consulta->where('tarea_id', $tarea_id);
        }

        return $consulta->orderBy('id')->get()->all();
    }

    /**
     * Una ejecución ya armada (como la deja el comando) con su turno: el mensaje `tarea_programada` del
     * «dueño» y el `assistant` en el estado dado, dentro de una conversación de WhatsApp nueva. Sirve para
     * probar el cierre y la entrega sin pasar por el despertar.
     *
     * @param  \App\Models\AiTareaDelAsistente  $tarea
     * @param  string  $estado_del_assistant  pendiente|listo|error
     * @param  string|null  $contenido  Contenido del assistant (null en pendiente).
     * @param  int  $minutos_de_antiguedad  Hace cuántos minutos se creó el turno.
     * @param  string  $ocurrencia  Momento programado (`ocurrencia_at`).
     * @return array{ejecucion: int, conversacion: \App\Models\AiConversation, assistant: \App\Models\AiMessage}
     */
    protected function ejecucion_en_curso($tarea, $estado_del_assistant, $contenido, $minutos_de_antiguedad, $ocurrencia = '2026-11-21 09:00:00')
    {
        $creado = Carbon::now()->subMinutes($minutos_de_antiguedad);

        $conversacion = $this->conversacion_whatsapp($creado);

        $this->mensaje($conversacion, 'user', 'listo', [
            'tipo'      => 'tarea_programada',
            'contenido' => '[Tarea programada] ' . $tarea->titulo . ': ' . $tarea->instruccion,
        ]);

        $assistant = $this->mensaje($conversacion, 'assistant', $estado_del_assistant, [
            'contenido'            => $contenido,
            'acciones_habilitadas' => false,
        ]);

        DB::table('ai_messages')->where('ai_conversation_id', $conversacion->id)->update(['created_at' => $creado, 'updated_at' => $creado]);

        $ejecucion = AiTareaDelAsistenteEjecucion::create([
            'tarea_id'           => $tarea->id,
            'owner_id'           => $this->comercio->id,
            'ocurrencia_at'      => $ocurrencia,
            'estado'             => 'en_curso',
            'ai_conversation_id' => $conversacion->id,
            'ai_message_id'      => $assistant->id,
        ]);

        DB::table('ai_tareas_del_asistente_ejecuciones')->where('id', $ejecucion->id)->update(['created_at' => $creado, 'updated_at' => $creado]);

        return ['ejecucion' => (int) $ejecucion->id, 'conversacion' => $conversacion, 'assistant' => $assistant->fresh()];
    }

    /**
     * Despierta la tarea dada a su hora y deja el turno terminado (`listo` con ese contenido), como si el
     * job hubiera corrido. La ejecución queda `en_curso`: el cierre (`lista`) lo hace el siguiente barrido
     * del comando, en el momento que decida el test.
     *
     * @param  \App\Models\AiTareaDelAsistente  $tarea  Una tarea cuya próxima ejecución es `$cuando`.
     * @param  string  $cuando  Momento en que corre el comando (dentro del anticipo de la ocurrencia).
     * @param  string  $contenido  Lo que contestó el asistente.
     * @return array{ejecucion: object, assistant: \App\Models\AiMessage}
     */
    protected function turno_listo($tarea, $cuando, $contenido)
    {
        $this->ahora($cuando);

        $this->despertar();

        $ejecuciones = $this->ejecuciones($tarea->id);

        $this->assertCount(1, $ejecuciones, 'El comando no despertó la tarea.');

        $assistant = AiMessage::find($ejecuciones[0]->ai_message_id);

        $assistant->contenido = $contenido;
        $assistant->estado = 'listo';
        $assistant->save();

        return ['ejecucion' => $ejecuciones[0], 'assistant' => $assistant->fresh()];
    }
}
