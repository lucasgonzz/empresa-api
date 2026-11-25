<?php

namespace Tests\Feature\OnboardingConversacional;

use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiTareaDelAsistente;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AsistenteWhatsapp\TareasDelAsistenteConModeloTestCase;

/**
 * Base de las suites de la misión onboarding-conversacional-whatsapp (23-24/11/2026): cuando el dueño le
 * escribe por primera vez a su asistente personal por WhatsApp, el asistente le hace, de a una pregunta,
 * las 32 preguntas sobre su negocio; recuerda en qué pregunta quedó, retoma días después y pregunta de nuevo
 * con una escalera de recordatorios acotada.
 *
 * Extiende TareasDelAsistenteConModeloTestCase: el dueño con `agentes_ia`, el POST real de admin-sync, el job
 * real del turno con el modelo doblado, el reloj fijo y las tareas del asistente. Lo propio de esta suite:
 *   - el reloj arranca un LUNES a las 10:00 (los criterios CA6, CA14 y CA16 cuentan días desde ahí);
 *   - las listas de claves del catálogo están escritas ACÁ, a mano, no leídas del código: si alguien cambia el
 *     catálogo, esta suite lo ve;
 *   - turno_manual(): un turno por cualquier canal y de cualquier persona, sin pasar por el POST de WhatsApp.
 *
 * 🔴 Nada de datos reales: ningún nombre, teléfono ni texto de un cliente. Los dueños de los tests son ficticios.
 *
 * PHP 7.4: sin match, sin nullsafe, sin str_contains, sin argumentos nombrados.
 */
abstract class OnboardingTestCase extends TareasDelAsistenteConModeloTestCase
{
    /** "Ahora" por defecto en esta suite: un lunes a las 10:00. */
    const LUNES = '2026-11-23 10:00:00';

    /** Los 32 temas, en el orden del catálogo. */
    const TEMAS = [
        'historia', 'como_lo_describen', 'posicionamiento', 'competencia', 'vision_3_anios',
        'que_vende_y_a_quien', 'reglas_del_negocio', 'preguntas_frecuentes', 'que_decide_solo', 'cuenta_corriente',
        'mejor_cliente', 'cliente_complicado', 'por_que_lo_eligen', 'lo_que_piden',
        'recorrido_de_un_pedido', 'equipo', 'proveedores_clave', 'cuello_de_botella', 'tarea_repetitiva', 'papel_o_excel', 'stock_que_no_falta',
        'como_llegan_clientes', 'tono_con_clientes', 'promos', 'temporadas', 'redes_y_tienda',
        'meta_del_anio', 'margen_buscado', 'estrellas_y_muertos', 'numero_que_mira', 'prioridad_hoy',
        'como_responder',
    ];

    /** Los 13 esenciales, en el orden en que se preguntan. */
    const ESENCIALES = [
        'historia', 'como_lo_describen', 'posicionamiento', 'que_vende_y_a_quien', 'reglas_del_negocio',
        'preguntas_frecuentes', 'mejor_cliente', 'recorrido_de_un_pedido', 'equipo', 'proveedores_clave',
        'como_llegan_clientes', 'tono_con_clientes', 'meta_del_anio',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ahora(self::LUNES);
    }

    /* ─────────────────────────────── turnos ─────────────────────────────── */

    /**
     * Un turno completo (el job real, el modelo doblado) iniciado por cualquier canal y cualquier persona.
     *
     * @param  string  $canal  whatsapp | app | sistema.
     * @param  string  $texto  Lo que escribe.
     * @param  \App\Models\User|null  $persona  Quien escribe (null = el dueño).
     * @param  string|null  $tipo  Tipo del mensaje del usuario (null = texto).
     * @return \App\Models\AiMessage  El `assistant` del turno, ya terminado.
     */
    protected function turno_manual($canal, $texto, $persona = null, $tipo = null)
    {
        $persona = is_null($persona) ? $this->comercio : $persona;

        $this->modelo_contesta('Dale, te cuento.');

        $datos_de_la_conversacion = [
            'user_id'         => $this->comercio->id,
            'auth_user_id'    => $persona->id,
            'last_message_at' => now(),
        ];

        // Sólo WhatsApp fija el origen: en los demás canales manda el default de la columna (`usuario`).
        if ($canal === 'whatsapp') {
            $datos_de_la_conversacion['origen'] = AiConversation::ORIGEN_WHATSAPP;
        }

        $conversacion = AiConversation::create($datos_de_la_conversacion);

        $datos = ['contenido' => $texto, 'canal' => $canal];

        if (!is_null($tipo)) {
            $datos['tipo'] = $tipo;
        }

        $this->mensaje($conversacion, 'user', 'listo', $datos);

        $assistant = $this->mensaje($conversacion, 'assistant', 'pendiente', ['canal' => $canal, 'acciones_habilitadas' => $canal !== 'app']);

        (new ResponderMensajeChatIaJob($assistant->id))->handle();

        return AiMessage::find($assistant->id);
    }

    /**
     * Despierta la próxima tarea de onboarding con el comando real (`asistente:despertar-tareas`) y corre el turno
     * desatendido con el job real y el modelo doblado. Deja el reloj un minuto después de la hora de la tarea.
     *
     * @return void
     */
    protected function despierta_y_corre_el_turno()
    {
        $tarea = $this->tareas_de_onboarding('activa')[0];

        $this->ahora($tarea->proxima_ejecucion_at->copy()->addMinute());

        $this->modelo_contesta('Hola, ¿tenés un rato para contarme un poco más del negocio?');

        $antes = count($this->ejecuciones());

        $this->despertar();

        $ejecuciones = $this->ejecuciones();

        $this->assertCount($antes + 1, $ejecuciones, 'El comando despertó la tarea de onboarding.');

        (new ResponderMensajeChatIaJob($ejecuciones[count($ejecuciones) - 1]->ai_message_id))->handle();
    }

    /**
     * Ejecuta una herramienta como la pide el modelo (la de la clase madre) y deja la escena como la deja un turno
     * real: la clase madre abre una conversación con un `assistant` PENDIENTE que nadie cierra, y el POST del
     * siguiente mensaje del dueño se suma a esa última conversación de WhatsApp y queda detrás de ese pendiente
     * (el turno no corre). Acá el pendiente se cierra, como si la herramienta hubiera sido parte de un turno terminado.
     *
     * @param  string  $nombre
     * @param  array  $input
     * @param  \App\Models\User|null  $persona
     * @return array{error: bool, texto: string, datos: array|null}
     */
    protected function herramienta($nombre, array $input = [], $persona = null)
    {
        $resultado = parent::herramienta($nombre, $input, $persona);

        $conversaciones = DB::table('ai_conversations')->where('user_id', $this->comercio->id)->pluck('id')->all();

        DB::table('ai_messages')
            ->whereIn('ai_conversation_id', $conversaciones)
            ->where('rol', 'assistant')
            ->where('estado', 'pendiente')
            ->update(['estado' => 'listo', 'contenido' => 'Hecho.']);

        return $resultado;
    }

    /**
     * El dueño ya le había escrito por WhatsApp antes (historial): un mensaje suyo, viejo, en el canal.
     *
     * @return void
     */
    protected function con_historial_de_whatsapp()
    {
        $conversacion = $this->conversacion_whatsapp(now()->subDays(40));

        $mensaje = $this->mensaje($conversacion, 'user', 'listo', ['contenido' => 'Hola, probando']);

        DB::table('ai_messages')->where('id', $mensaje->id)->update(['created_at' => now()->subDays(40), 'updated_at' => now()->subDays(40)]);
    }

    /* ─────────────────────────────── lo que ve el modelo ─────────────────────────────── */

    /**
     * El system SIN caché del último pedido que se le hizo al modelo.
     *
     * @return string
     */
    protected function system_del_ultimo_pedido()
    {
        $pedidos = $this->pedidos_al_modelo();

        $this->assertNotEmpty($pedidos, 'El modelo no recibió ningún pedido.');

        return $this->system_sin_cache($pedidos[count($pedidos) - 1]);
    }

    /**
     * Cuántos bloques de system viajaron en el último pedido.
     *
     * @return int
     */
    protected function bloques_del_ultimo_pedido()
    {
        $pedidos = $this->pedidos_al_modelo();

        $cuerpo = $pedidos[count($pedidos) - 1];

        return is_array($cuerpo['system']) ? count($cuerpo['system']) : 1;
    }

    /**
     * El bloque <onboarding_del_negocio> del último pedido, o '' si no viajó.
     *
     * @return string
     */
    protected function bloque_de_onboarding()
    {
        $system = $this->system_del_ultimo_pedido();

        $desde = strpos($system, '<onboarding_del_negocio>');

        if ($desde === false) {
            return '';
        }

        $hasta = strpos($system, '</onboarding_del_negocio>', $desde);

        return $hasta === false ? substr($system, $desde) : substr($system, $desde, $hasta - $desde + strlen('</onboarding_del_negocio>'));
    }

    /* ─────────────────────────────── estado ─────────────────────────────── */

    /**
     * Las columnas del onboarding del dueño, crudas.
     *
     * @return object
     */
    protected function onboarding()
    {
        return DB::table('users')->where('id', $this->comercio->id)->first();
    }

    /**
     * Las tareas de onboarding del dueño, de la más vieja a la más nueva.
     *
     * @param  string|null  $estado  Sólo las de ese estado (null = todas).
     * @return array<int,\App\Models\AiTareaDelAsistente>
     */
    protected function tareas_de_onboarding($estado = null)
    {
        $consulta = AiTareaDelAsistente::where('owner_id', $this->comercio->id)->where('area', 'onboarding')->orderBy('id');

        if (!is_null($estado)) {
            $consulta->where('estado', $estado);
        }

        return $consulta->get()->all();
    }

    /**
     * El estado crudo de una tarea del asistente.
     *
     * @param  int  $id
     * @return string
     */
    protected function estado_de_tarea($id)
    {
        return (string) DB::table('ai_tareas_del_asistente')->where('id', $id)->value('estado');
    }

    /**
     * Las filas del estado por tema del dueño, por clave.
     *
     * @return array<string,object>
     */
    protected function temas_marcados()
    {
        $filas = DB::table('ai_conocimiento_del_negocio')->where('owner_id', $this->comercio->id)->orderBy('id')->get();

        $por_clave = [];

        foreach ($filas as $fila) {
            $por_clave[$fila->clave] = $fila;
        }

        return $por_clave;
    }

    /**
     * El agente guarda algo en la memoria y declara qué temas quedaron respondidos (la herramienta REAL).
     *
     * @param  array<int,string>  $temas
     * @param  string  $ruta
     * @return array{error: bool, texto: string, datos: array|null}
     */
    protected function el_agente_guarda($temas, $ruta = 'negocio/identidad.md')
    {
        return $this->herramienta('guardar_en_contexto', [
            'ruta'      => $ruta,
            'operacion' => 'agregar',
            'contenido' => '- Dato de prueba de la suite (' . implode(', ', $temas) . ').',
            'motivo'    => 'El dueño lo contó por WhatsApp',
            'temas'     => $temas,
        ]);
    }

    /**
     * Pone el onboarding del dueño en un estado dado sin pasar por el disparo (para armar escenas).
     *
     * @param  string|null  $estado
     * @param  string|null  $origen
     * @return void
     */
    protected function onboarding_en($estado, $origen = 'whatsapp')
    {
        DB::table('users')->where('id', $this->comercio->id)->update([
            'ia_onboarding_estado'       => $estado,
            'ia_onboarding_origen'       => is_null($estado) ? null : $origen,
            'ia_onboarding_iniciado_at'  => is_null($estado) ? null : now(),
        ]);
    }
}
