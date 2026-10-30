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
 * 🔴 UNA INTERACCIÓN ES UNA RESPUESTA DEL AGENTE, NO UNA LLAMADA A LA API (misión
 * agente-memoria-y-sin-techo, 30/10/2026; hasta ahí se contaban las filas `chat_mensaje` de
 * ai_token_usages). Cada mensaje del chat graba una fila por VUELTA del loop de tool use, así que un
 * solo pedido contaba hasta 8 interacciones. El caso real: Pablo (Ferre Total, plan «Agentes Light»,
 * tope de 100 por día) leyó el 29/10 a las 23:51 «Llegaste al límite de tu plan de este mes» con 106
 * filas `chat_mensaje` de ese día —muchas menos respuestas: cada pedido sumaba una fila por vuelta—, y
 * al otro día le andaba (el que había pasado era el DIARIO, y el texto decía «de este mes»). Con el techo de vueltas
 * en 24 (AsistenteIaService::MAX_TOOL_ITERATIONS_CON_ACCIONES) el tope de 100 se comería en 4 o 5
 * pedidos. Ahora interacciones_del_dia() cuenta las respuestas del agente de hoy (ver ahí qué entra y
 * qué no). El admin solo manda el NÚMERO del tope (ClientAiPlanSyncService): qué es una interacción lo
 * decide este helper, sin cambio de contrato.
 *
 * 🔴 Y SE CUENTAN SOBRE ai_token_usages, NO SOBRE ai_messages (ronda de arreglos de la revisión
 * independiente, R3, 30/10/2026). La primera versión contaba los `ai_messages` del asistente, y
 * AiConversationController::destroy borra la conversación con sus mensajes EN DURO: borrar una charla
 * le devolvía al dueño las interacciones del día. Ahora cada vuelta del loop graba su fila
 * `chat_mensaje` con `referencia_id` = el mensaje que contesta (AsistenteIaService), y una interacción
 * es un `referencia_id` distinto de hoy: las filas de consumo no se borran con la conversación.
 * (Verificado antes de cambiarlo: el admin no lee `referencia_id` de ai_token_usages —ConsumoIaController
 * le manda totales agrupados por día, proceso, proveedor, modelo y persona— y la única que graba
 * `chat_mensaje` es la respuesta a la persona en el loop del chat.)
 *
 * LA TRANSCRIPCIÓN DE FOTOS SUMA TOKENS PERO NO INTERACCIONES (misión asistente-deepseek-pro-razona,
 * 24/9/2026). Un turno de DeepSeek que razona en Pro con fotos hace antes una llamada al modelo con
 * visión que las transcribe, y la graba con el proceso `chat_transcripcion_foto`
 * (TranscripcionDeFotosIaHelper::PROCESO). No es una interacción por construcción: es otro proceso, y
 * las interacciones son las filas `chat_mensaje` (una llamada más del mismo turno, que tiene UNA
 * respuesta). Pero
 * tokens_del_mes() suma TODAS las filas del dueño sin filtrar por proceso, así que esos tokens sí
 * cuentan contra el tope MENSUAL: se pagaron por su turno. No hay que tocar nada acá para que eso pase;
 * si alguien filtra tokens_del_mes() por proceso, tiene que dejar adentro este.
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

    /**
     * El proceso de `ai_token_usages` de cada vuelta del loop del chat (AsistenteIaService). Desde la
     * misión agente-memoria-y-sin-techo una FILA ya no es una interacción: lo es cada `referencia_id`
     * distinto de estas filas (el mensaje que contesta), ver interacciones_del_dia().
     */
    const PROCESO_CHAT = 'chat_mensaje';

    /**
     * Lo que se le contesta al dueño cuando superó el tope MENSUAL de tokens de su plan. Va como el
     * contenido del assistant, que nace 'listo' sin despachar el job (ver el corte en
     * AiConversationController y AdminSync\AsistenteController): un negocio pasado de tope no gasta una
     * llamada a la API.
     *
     * Desde la misión agente-memoria-y-sin-techo los llamadores no lo usan directo: piden
     * mensaje_limite(), que elige entre este y MENSAJE_LIMITE_DIARIO. Queda como constante por
     * compatibilidad (código o tests que lo lean).
     */
    const MENSAJE_LIMITE = 'Llegaste al límite de tu plan de este mes. Escribinos para ampliarlo.';

    /**
     * Lo que se le contesta al dueño cuando superó el tope DIARIO de interacciones (misión
     * agente-memoria-y-sin-techo, 30/10/2026). Antes salía MENSAJE_LIMITE para los dos topes, y Pablo
     * (Ferre Total) leyó «de este mes» por el tope de HOY: al otro día le andaba y el texto le había
     * mentido. Este dice cuál tope es y que mañana se renueva.
     */
    const MENSAJE_LIMITE_DIARIO = 'Llegaste al límite de mensajes de hoy de tu plan. Mañana se renueva; si necesitás más, escribinos para ampliarlo.';

    /**
     * El estado de consumo del dueño contra los topes de su plan.
     *
     * Misión agente-memoria-y-sin-techo: suma (sin sacar ni cambiar ninguna) `supero_tokens` y
     * `supero_interacciones`, para que mensaje_limite() diga cuál de los dos topes se pasó. `supero`
     * sigue siendo el O de los dos, como siempre.
     *
     * @param  \App\Models\User|null  $owner
     * @return array{consumo_tokens:int, consumo_interacciones:int, tope_tokens:int|null, tope_interacciones:int|null, supero:bool, cerca:bool, supero_tokens:bool, supero_interacciones:bool}
     */
    public static function estado($owner)
    {
        $tope_tokens = self::tope_valido(is_null($owner) ? null : $owner->plan_ia_tope_tokens_mensual);
        $tope_interacciones = self::tope_valido(is_null($owner) ? null : $owner->plan_ia_tope_interacciones_diarias);

        $owner_id = is_null($owner) ? 0 : (int) $owner->id;

        $consumo_tokens = $owner_id > 0 ? self::tokens_del_mes($owner_id) : 0;
        $consumo_interacciones = $owner_id > 0 ? self::interacciones_del_dia($owner_id) : 0;

        $supero_tokens = self::supera($consumo_tokens, $tope_tokens);
        $supero_interacciones = self::supera($consumo_interacciones, $tope_interacciones);

        return [
            'consumo_tokens'        => $consumo_tokens,
            'consumo_interacciones' => $consumo_interacciones,
            'tope_tokens'           => $tope_tokens,
            'tope_interacciones'    => $tope_interacciones,
            'supero'                => $supero_tokens || $supero_interacciones,
            'cerca'                 => self::cerca($consumo_tokens, $tope_tokens) || self::cerca($consumo_interacciones, $tope_interacciones),
            'supero_tokens'         => $supero_tokens,
            'supero_interacciones'  => $supero_interacciones,
        ];
    }

    /**
     * El texto de límite que corresponde al estado (misión agente-memoria-y-sin-techo, 30/10/2026): el
     * del tope mensual de tokens si se pasó ése —mañana no se arregla—, y si no el del tope diario de
     * interacciones. Con un estado viejo, sin las claves nuevas, o sin ningún tope pasado, el mensual de
     * siempre (es lo que contestaba el corte antes de esta misión).
     *
     * @param  array  $estado  Lo que devolvió estado().
     * @return string
     */
    public static function mensaje_limite(array $estado)
    {
        $supero_tokens = ! empty($estado['supero_tokens']);
        $supero_interacciones = ! empty($estado['supero_interacciones']);

        if ($supero_interacciones && ! $supero_tokens) {

            return self::MENSAJE_LIMITE_DIARIO;
        }

        return self::MENSAJE_LIMITE;
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
     * Cuántas respuestas le dio hoy el agente al negocio (ver los 🔴 del docblock): los mensajes DISTINTOS
     * (`referencia_id`) de las filas `chat_mensaje` de hoy del dueño (`ai_token_usages.user_id`, que junta
     * las del dueño y las de sus empleados), más UNA por cada fila de hoy sin `referencia_id` (las que el
     * loop grabó antes de la ronda de arreglos R3: sin el mensaje, cada vuelta cuenta como antes).
     *
     * 🔴 POR QUÉ ACÁ Y NO EN ai_messages. Las filas de consumo no se borran con la conversación
     * (AiConversationController::destroy borra la conversación y sus mensajes en duro): contando mensajes,
     * borrar una charla le devolvía al dueño las interacciones del día.
     *
     * Qué NO cuenta, por construcción (solo el loop del chat graba `chat_mensaje`, y solo corre para
     * contestarle a la persona: lo despachan send_message y AdminSync\AsistenteController::mensajes):
     * - las conversaciones de origen 'mcp': el servidor MCP corre las herramientas sin el loop;
     * - las respuestas con un texto de límite (MENSAJE_LIMITE, MENSAJE_LIMITE_DIARIO): nacen `listo` sin
     *   despachar el job, no llaman a la IA;
     * - los mensajes que escribe el sistema solo (el recordatorio de una tarea por WhatsApp, los avisos
     *   de AvisosDelAsistenteHelper, el resumen con el que nace la conversación de una sugerencia, el
     *   cierre de las imágenes de categorías): no pasan por el loop;
     * - las otras llamadas del turno con su propio proceso (la transcripción de fotos, el título, el hilo).
     *
     * ⚠️ Una respuesta cuenta desde su PRIMERA llamada a la IA (su primera fila), no desde que se crea:
     * un mensaje `pendiente` que todavía no volvió de la primera vuelta no cuenta, y uno cuya primera
     * llamada falla tampoco (no gastó). El corte se mide antes de crear la respuesta, igual que antes.
     *
     * La consulta va por el índice (user_id, created_at) de ai_token_usages
     * (ai_token_usages_user_created_index): un dueño, un día. Es el filtro con índice que pidió la
     * revisión (R4) en lugar del join de ai_messages con su subconsulta por mensaje.
     *
     * @param  int  $owner_id
     * @return int
     */
    protected static function interacciones_del_dia($owner_id)
    {
        $fila = AiTokenUsage::where('user_id', (int) $owner_id)
            ->whereBetween('created_at', [Carbon::now()->startOfDay(), Carbon::now()->endOfDay()])
            ->where('proceso', self::PROCESO_CHAT)
            ->selectRaw('COUNT(DISTINCT referencia_id) as respuestas, COALESCE(SUM(CASE WHEN referencia_id IS NULL THEN 1 ELSE 0 END), 0) as sin_referencia')
            ->first();

        if (is_null($fila)) {

            return 0;
        }

        return (int) $fila->respuestas + (int) $fila->sin_referencia;
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
