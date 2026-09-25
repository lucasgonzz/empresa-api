<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiTokenUsage;
use Carbon\Carbon;

/**
 * El consumo de IA de un negocio contra el tope de su plan (misión
 * foto-sucursal-y-asistente-configurable, 17/9/2026).
 *
 * Lo usan el footer del chat (GET api/mi-consumo-ia) y el corte que va ANTES de despachar el job de
 * respuesta (AiConversationController::send_message y AdminSync\AsistenteController::mensajes): si el
 * negocio ya superó su tope, no se gasta una llamada a la API y se le contesta con el texto de
 * límite.
 *
 * 🔴 SIN TOPE NUNCA CORTA. Un tope null o 0 se lee como "sin límite": es la guarda de
 * compatibilidad de las dos direcciones del contrato con el admin. Un cliente al que nadie le
 * pusheó un plan (o un admin viejo que no pushea) queda con los dos topes en null y el agente
 * responde como hoy. El corte solo se activa con un tope > 0 recibido del admin y guardado en el
 * dueño.
 *
 * 🔴 LAS INTERACCIONES SE CUENTAN POR FILA `chat_mensaje`, NO POR MENSAJE. Cada mensaje del chat
 * puede grabar varias filas 'chat_mensaje' (una por iteración del loop de tool use, cada respuesta
 * de la API trae su propio usage). El tope de interacciones se mide contra ESAS filas —que es lo
 * que el plan pide y lo que el admin estima al armar el paquete—, así que "interacción" acá es una
 * llamada a la API del chat, no un mensaje único del dueño.
 *
 * 🔴 LA TRANSCRIPCIÓN DE FOTOS SUMA TOKENS PERO NO INTERACCIONES (misión asistente-deepseek-pro-razona,
 * 24/9/2026). Un turno de DeepSeek que razona en Pro con fotos hace antes una llamada al modelo con
 * visión que las transcribe, y la graba con el proceso `chat_transcripcion_foto`
 * (TranscripcionDeFotosIaHelper::PROCESO). No es `chat_mensaje` a propósito: no es una pregunta del
 * dueño y no le puede comer el tope DIARIO de interacciones. Pero tokens_del_mes() suma TODAS las
 * filas del dueño sin filtrar por proceso, así que esos tokens sí cuentan contra el tope MENSUAL:
 * se pagaron por su turno. No hay que tocar nada acá para que eso pase; si alguien filtra
 * tokens_del_mes() por proceso, tiene que dejar adentro este.
 *
 * Los tokens se cuentan sobre el MES CALENDARIO y las interacciones sobre el DÍA, las dos ventanas
 * en la zona de la app (America/Argentina/Buenos_Aires, config/app.php). El defecto de plataforma
 * conocido de un cliente migrado del shared (UTC) al VPS (-03) —el mismo que documenta
 * ConsumoIaController— corre estas ventanas unas horas en las filas viejas, y no es algo que este
 * helper pueda arreglar sin tocar config/database.php.
 */
class TopeDeTokensHelper
{
    /** Fracción de un tope a partir de la cual se avisa que está cerca (estilo Claude). */
    const UMBRAL_CERCA = 0.8;

    /** El proceso de `ai_token_usages` que cuenta como una interacción del chat. */
    const PROCESO_CHAT = 'chat_mensaje';

    /**
     * Lo que se le contesta al dueño cuando superó el tope de su plan. Va como el contenido del
     * assistant, que nace 'listo' sin despachar el job (ver el corte en AiConversationController y
     * AdminSync\AsistenteController): un negocio pasado de tope no gasta una llamada a la API.
     */
    const MENSAJE_LIMITE = 'Llegaste al límite de tu plan de este mes. Escribinos para ampliarlo.';

    /**
     * El estado de consumo del dueño contra los topes de su plan.
     *
     * @param  \App\Models\User|null  $owner
     * @return array{consumo_tokens:int, consumo_interacciones:int, tope_tokens:int|null, tope_interacciones:int|null, supero:bool, cerca:bool}
     */
    public static function estado($owner)
    {
        $tope_tokens = self::tope_valido(is_null($owner) ? null : $owner->plan_ia_tope_tokens_mensual);
        $tope_interacciones = self::tope_valido(is_null($owner) ? null : $owner->plan_ia_tope_interacciones_diarias);

        $owner_id = is_null($owner) ? 0 : (int) $owner->id;

        $consumo_tokens = $owner_id > 0 ? self::tokens_del_mes($owner_id) : 0;
        $consumo_interacciones = $owner_id > 0 ? self::interacciones_del_dia($owner_id) : 0;

        return [
            'consumo_tokens'        => $consumo_tokens,
            'consumo_interacciones' => $consumo_interacciones,
            'tope_tokens'           => $tope_tokens,
            'tope_interacciones'    => $tope_interacciones,
            'supero'                => self::supera($consumo_tokens, $tope_tokens) || self::supera($consumo_interacciones, $tope_interacciones),
            'cerca'                 => self::cerca($consumo_tokens, $tope_tokens) || self::cerca($consumo_interacciones, $tope_interacciones),
        ];
    }

    /**
     * Un tope guardado en el dueño, normalizado: null o 0 (o negativo) se leen como "sin tope".
     *
     * @param  mixed  $valor
     * @return int|null
     */
    protected static function tope_valido($valor)
    {
        $valor = (int) $valor;

        return $valor > 0 ? $valor : null;
    }

    /**
     * La suma de los cuatro contadores de `ai_token_usages` del dueño en el mes calendario actual.
     *
     * @param  int  $owner_id
     * @return int
     */
    protected static function tokens_del_mes($owner_id)
    {
        $total = AiTokenUsage::where('user_id', $owner_id)
            ->whereBetween('created_at', [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()])
            ->selectRaw('COALESCE(SUM(input_tokens + output_tokens + cache_creation_input_tokens + cache_read_input_tokens), 0) as total')
            ->value('total');

        return (int) $total;
    }

    /**
     * Cuántas filas `chat_mensaje` grabó el dueño en el día de hoy (ver el 🔴 del docblock).
     *
     * @param  int  $owner_id
     * @return int
     */
    protected static function interacciones_del_dia($owner_id)
    {
        return (int) AiTokenUsage::where('user_id', $owner_id)
            ->where('proceso', self::PROCESO_CHAT)
            ->whereBetween('created_at', [Carbon::now()->startOfDay(), Carbon::now()->endOfDay()])
            ->count();
    }

    /**
     * true si hay tope definido y el consumo lo alcanzó o pasó.
     *
     * @param  int  $consumo
     * @param  int|null  $tope
     * @return bool
     */
    protected static function supera($consumo, $tope)
    {
        return !is_null($tope) && $consumo >= $tope;
    }

    /**
     * true si hay tope definido y el consumo llegó al umbral de aviso (>= 80%).
     *
     * @param  int  $consumo
     * @param  int|null  $tope
     * @return bool
     */
    protected static function cerca($consumo, $tope)
    {
        return !is_null($tope) && $consumo >= ($tope * self::UMBRAL_CERCA);
    }
}
