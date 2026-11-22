<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Base de las suites de la enmienda 1 de la misión agente-tareas-programadas (22/11/2026): las que corren el
 * job REAL del turno (ResponderMensajeChatIaJob) con el modelo doblado por Http::fake, como el test 33, pero
 * ahora también para el turno que inicia el DUEÑO (el POST real de admin-sync `asistente/mensajes`).
 *
 * Es un archivo nuevo a propósito: TareasDelAsistenteTestCase (de aceptación) no se toca.
 *
 * PHP 7.4: sin match, sin nullsafe, sin str_contains, sin argumentos nombrados.
 */
abstract class TareasDelAsistenteConModeloTestCase extends TareasDelAsistenteTestCase
{
    /** El prefijo del id de un ítem de tarea del asistente en el carril de recordatorios. */
    const PREFIJO = 'recordatorio:tarea_asistente:';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.api_key' => 'clave-anthropic-de-prueba',
            'services.deepseek.api_key'  => 'clave-deepseek-de-prueba',
        ]);

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

    /** @var \Closure|null  Lo que hace el doble del modelo AHORA (se cambia entre turnos de un mismo test). */
    protected $comportamiento_del_modelo = null;

    /**
     * Instala (una sola vez por test) el doble de los dos proveedores: delega en `$comportamiento_del_modelo`.
     * Http::fake acumula los stubs y gana el primero que coincide, así que no se puede volver a fakear entre
     * dos turnos: lo que cambia es el comportamiento al que delega. Una red de 500 para cualquier otro host.
     *
     * @return void
     */
    protected function instalar_el_doble_del_modelo()
    {
        if (!is_null($this->comportamiento_del_modelo)) {
            return;
        }

        $this->comportamiento_del_modelo = function () {
            return Http::response(['error' => 'sin comportamiento'], 500);
        };

        $doble = function () {
            return call_user_func($this->comportamiento_del_modelo);
        };

        Http::fake([
            'api.deepseek.com/*'  => $doble,
            'api.anthropic.com/*' => $doble,
            '*'                   => Http::response(['error' => 'host sin stub'], 500),
        ]);
    }

    /**
     * El modelo contesta siempre `$texto` (hasta que el test cambie el comportamiento).
     *
     * @param  string  $texto
     * @return void
     */
    protected function modelo_contesta($texto)
    {
        $this->instalar_el_doble_del_modelo();

        $this->comportamiento_del_modelo = function () use ($texto) {
            return Http::response([
                'model'       => 'deepseek-flash-test',
                'stop_reason' => 'end_turn',
                'content'     => [['type' => 'text', 'text' => $texto]],
                'usage'       => ['input_tokens' => 300, 'output_tokens' => 15],
            ], 200);
        };
    }

    /**
     * El modelo explota (excepción en el cliente HTTP): el turno tiene que terminar en `error`.
     *
     * @return void
     */
    protected function modelo_falla()
    {
        $this->instalar_el_doble_del_modelo();

        $this->comportamiento_del_modelo = function () {
            throw new \RuntimeException('el modelo no contesta');
        };
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
     * El system que viajó en un pedido, SIN los bloques cacheados (los que llevan cache_control): lo que cambia
     * en cada turno, junto.
     *
     * @param  array  $cuerpo
     * @return string
     */
    protected function system_sin_cache(array $cuerpo)
    {
        $system = isset($cuerpo['system']) ? $cuerpo['system'] : [];

        if (is_string($system)) {
            return $system;
        }

        $textos = [];

        foreach ($system as $bloque) {
            if (is_array($bloque) && !isset($bloque['cache_control']) && isset($bloque['text'])) {
                $textos[] = (string) $bloque['text'];
            }
        }

        return implode("\n", $textos);
    }

    /* ─────────────────────────────── turnos ─────────────────────────────── */

    /**
     * El dueño escribe por WhatsApp (el POST real del admin) y el job del turno corre con el modelo doblado.
     * Devuelve el `assistant` del turno ya terminado.
     *
     * @param  string  $texto  Lo que escribe el dueño.
     * @param  string  $respuesta_del_modelo  Lo que contesta el modelo.
     * @return \App\Models\AiMessage
     */
    protected function el_dueno_escribe($texto, $respuesta_del_modelo = 'Dale, te cuento.')
    {
        $this->modelo_contesta($respuesta_del_modelo);

        $respuesta = $this->mandar(['texto' => $texto, 'whatsapp_message_id' => 'wamid.DUENO' . uniqid()]);

        $respuesta->assertStatus(202);

        $id = (int) $respuesta->json('ai_message_id');

        (new ResponderMensajeChatIaJob($id))->handle();

        return AiMessage::find($id);
    }

    /* ─────────────────────────────── resultados de tareas ─────────────────────────────── */

    /**
     * Despierta una tarea de una sola vez a las `$hora` del viernes 27/11/2026, deja su turno terminado con
     * `$resultado` y la cierra como `lista`. Deja el reloj tres minutos después de la hora de la tarea.
     *
     * @param  string  $titulo
     * @param  string  $resultado
     * @param  string  $hora  HH:MM de la tarea.
     * @return object  La fila de la ejecución (ya `lista`).
     */
    protected function resultado_listo($titulo, $resultado, $hora = '17:00')
    {
        $tarea = $this->tarea_del_asistente([
            'titulo'               => $titulo,
            'hora'                 => $hora,
            'proxima_ejecucion_at' => '2026-11-27 ' . $hora . ':00',
        ]);

        $despierta = Carbon::parse('2026-11-27 ' . $hora . ':00')->subMinutes(2)->format('Y-m-d H:i:s');

        $this->turno_listo($tarea, $despierta, $resultado);

        $this->ahora(Carbon::parse('2026-11-27 ' . $hora . ':00')->addMinutes(1));

        $this->despertar();

        $ejecucion = $this->ejecuciones($tarea->id)[0];

        $this->assertSame('lista', $ejecucion->estado, 'El turno terminado tiene que quedar lista para entregar.');

        return $ejecucion;
    }

    /**
     * El id del contrato de una ejecución.
     *
     * @param  object  $ejecucion  Fila de la ejecución.
     * @return string
     */
    protected function id_de_ejecucion($ejecucion)
    {
        return self::PREFIJO . $ejecucion->id . '-' . Carbon::parse($ejecucion->created_at)->timestamp;
    }

    /**
     * El admin sella el resultado como mandado. `$via` null = el admin viejo (no manda el campo).
     *
     * @param  object  $ejecucion
     * @param  string|null  $via  'texto' | 'plantilla' | null.
     * @param  string  $wamid
     * @return \Illuminate\Testing\TestResponse
     */
    protected function sellar($ejecucion, $via = null, $wamid = 'wamid.SELLO')
    {
        $cuerpo = ['id' => $this->id_de_ejecucion($ejecucion), 'whatsapp_message_id' => $wamid];

        if (!is_null($via)) {
            $cuerpo['via'] = $via;
        }

        return $this->postJson('api/admin-sync/asistente/recordatorios/avisado', $cuerpo, $this->headers());
    }

    /**
     * La fila cruda de una ejecución.
     *
     * @param  int  $id
     * @return object
     */
    protected function ejecucion_cruda($id)
    {
        return \Illuminate\Support\Facades\DB::table('ai_tareas_del_asistente_ejecuciones')->where('id', $id)->first();
    }
}
