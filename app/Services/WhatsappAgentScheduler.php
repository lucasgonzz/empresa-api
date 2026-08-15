<?php

namespace App\Services;

use App\Http\Controllers\Helpers\WhatsappChatHelper;
use App\Jobs\GenerateWhatsappAiReplyJob;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Programa la respuesta del agente de WhatsApp después de un mensaje entrante, con demora
 * configurable (`whatsapp_bot_configs.ai_reply_delay_seconds`) y reinicio de la espera
 * (debounce). Es lo que hace que el agente conteste UNA sola vez cuando el cliente manda
 * tres mensajes seguidos.
 *
 * Cómo funciona el debounce, porque es el corazón de todo esto y no es obvio leyendo el job
 * suelto: cada mensaje entrante incrementa un contador guardado en caché (el "token") y
 * despacha un job llevándose ese número. Cuando el job se despierta, compara el token que
 * trae contra el vigente: si no coincide es porque después de él entró otro mensaje, así que
 * se descarta solo sin generar nada. De los tres jobs que dispararon los tres mensajes, solo
 * el último sobrevive — y ese ve los tres mensajes en el historial.
 *
 * Es el mismo patrón ya probado en producción en admin-api
 * (`LeadAiSuggestionScheduler` + `GenerateLeadAiSuggestionJob`).
 */
class WhatsappAgentScheduler
{
    /** Prefijo de la clave de caché del token de debounce, por chat. */
    private const CACHE_KEY_PREFIX = 'whatsapp_agent_reply_token:';

    /** TTL del token en caché: tiene que cubrir demoras largas y una cola lenta. */
    private const CACHE_TTL_SECONDS = 7200;

    /**
     * Reinicia la espera y programa el job que genera la respuesta del agente.
     *
     * Se llama desde adentro del request del webhook de Kapso (y desde el endpoint de
     * simulación), así que no puede hacer nada caro: solo descarta lo que quedó obsoleto,
     * bumpea el token y encola.
     *
     * @param WhatsappChat      $chat   Chat con el mensaje entrante ya persistido.
     * @param WhatsappBotConfig $config Configuración del bot del dueño del chat.
     *
     * @return void
     */
    public function schedule_after_inbound(WhatsappChat $chat, WhatsappBotConfig $config): void
    {
        // Misma condición que hasta esta misión corría inline en WhatsappBotController:
        // la IA solo responde si el bot está activo a nivel empresa Y el chat puntual no la
        // tiene apagada. Si no, no se encola nada.
        if (! $config->is_active || ! $chat->ai_enabled) {
            Log::channel('daily')->debug('WhatsappAgentScheduler: omitido (bot inactivo o IA apagada en el chat).', [
                'chat_id'    => $chat->id,
                'is_active'  => (bool) $config->is_active,
                'ai_enabled' => (bool) $chat->ai_enabled,
            ]);

            return;
        }

        // El cliente siguió escribiendo: la respuesta que estaba esperando confirmación ya no
        // sirve y se borra (decisión D2 del plan). Si quedara, la IA la leería como un 'out'
        // que nunca se envió y creería que ya contestó.
        WhatsappChatHelper::discard_pending_ai_messages($chat);

        $token = $this->bump_token((int) $chat->id);
        $delay = (int) $config->ai_reply_delay_seconds;

        $pending_dispatch = GenerateWhatsappAiReplyJob::dispatch((int) $chat->id, $token);

        if ($delay > 0) {
            $pending_dispatch->delay(now()->addSeconds($delay));

            Log::channel('daily')->debug('WhatsappAgentScheduler: respuesta del agente reprogramada.', [
                'chat_id'       => $chat->id,
                'delay_seconds' => $delay,
                'token'         => $token,
            ]);

            return;
        }

        // Demora 0 = el comportamiento de siempre (contesta al toque). Se corre en sync +
        // afterResponse a propósito: sync para no depender de que haya un `queue:work`
        // andando, y afterResponse para que la llamada a Anthropic ocurra DESPUÉS de
        // devolverle el 200 a Kapso, que es todo el punto de sacar la IA del request.
        $pending_dispatch->onConnection('sync')->afterResponse();
    }

    /**
     * Invalida el job pendiente de un chat sin reprogramar ninguno.
     *
     * @param int $chat_id
     *
     * @return void
     */
    public function cancel(int $chat_id): void
    {
        $this->bump_token($chat_id);

        Log::channel('daily')->debug('WhatsappAgentScheduler: respuesta automática cancelada.', [
            'chat_id' => $chat_id,
        ]);
    }

    /**
     * Indica si el token que trae el job sigue siendo el último programado para ese chat.
     *
     * @param int $chat_id
     * @param int $token   Token capturado al encolar el job.
     *
     * @return bool
     */
    public function is_token_current(int $chat_id, int $token): bool
    {
        $current = Cache::get($this->cache_key($chat_id));

        return (int) $current === $token;
    }

    /**
     * Incrementa el token de programación del chat y lo persiste en caché.
     *
     * @param int $chat_id
     *
     * @return int Token nuevo, el que se le pasa al job recién encolado.
     */
    private function bump_token(int $chat_id): int
    {
        $cache_key = $this->cache_key($chat_id);
        $current = (int) Cache::get($cache_key, 0);
        $next = $current + 1;

        Cache::put($cache_key, $next, self::CACHE_TTL_SECONDS);

        return $next;
    }

    /**
     * Arma la clave de caché del token de debounce del chat.
     *
     * @param int $chat_id
     *
     * @return string
     */
    private function cache_key(int $chat_id): string
    {
        return self::CACHE_KEY_PREFIX.$chat_id;
    }
}
