<?php

namespace App\Services;

use App\Http\Controllers\Helpers\WhatsappChatHelper;
use App\Jobs\AutoSendWhatsappAiMessageJob;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Programa el envío automático de una respuesta ya generada por el agente, después de la
 * ventana de confirmación humana que configuró el dueño
 * (`whatsapp_bot_configs.ai_confirm_delay_seconds`).
 *
 * Mismo mecanismo de token en caché que `WhatsappAgentScheduler`, pero por MENSAJE en vez de
 * por chat: si una persona confirma o descarta el mensaje antes de que se cumpla la espera,
 * el token se invalida y el job pendiente se auto-descarta cuando se despierta. Así el
 * mensaje nunca se manda dos veces ni se manda uno que ya fue descartado.
 *
 * Réplica adaptada de `admin-api\app\Services\LeadAiSuggestionAutoSendScheduler`.
 */
class WhatsappAiAutoSendScheduler
{
    /** Prefijo de la clave de caché del token de auto-envío, por mensaje. */
    private const CACHE_KEY_PREFIX = 'whatsapp_ai_auto_send_token:';

    /** TTL del token en caché: tiene que cubrir demoras largas y una cola lenta. */
    private const CACHE_TTL_SECONDS = 7200;

    /**
     * Encola el envío automático de una respuesta recién generada por el agente.
     *
     * @param WhatsappChatMessage $message Mensaje `out` con `ai_status = 'a_confirmar'`.
     * @param WhatsappBotConfig   $config  Configuración del bot del dueño del chat.
     *
     * @return void
     */
    public function schedule_for_message(WhatsappChatMessage $message, WhatsappBotConfig $config): void
    {
        if ((string) $message->ai_status !== 'a_confirmar') {
            return;
        }

        $message_id = (int) $message->id;
        $token = $this->bump_token($message_id);
        $delay = (int) $config->ai_confirm_delay_seconds;

        if ($delay <= 0) {
            // Demora 0 = comportamiento de siempre: sale sin pasar por ninguna confirmación.
            // Va en sync PERO SIN afterResponse a propósito (decisión D4 del plan): a este
            // punto se llega desde adentro del job de respuesta, o sea desde un callback de
            // terminación o desde un worker de cola. Ahí ya no hay respuesta HTTP que
            // proteger, y anidar otro afterResponse es frágil y no determinístico.
            AutoSendWhatsappAiMessageJob::dispatch($message_id, $token)->onConnection('sync');

            Log::channel('daily')->debug('WhatsappAiAutoSendScheduler: envío automático inmediato.', [
                'message_id' => $message_id,
                'token'      => $token,
            ]);

            return;
        }

        $auto_send_at = now()->addSeconds($delay);

        $message->ai_auto_send_at = $auto_send_at;
        $message->save();

        AutoSendWhatsappAiMessageJob::dispatch($message_id, $token)->delay($auto_send_at);

        Log::channel('daily')->debug('WhatsappAiAutoSendScheduler: envío automático programado.', [
            'message_id'    => $message_id,
            'delay_seconds' => $delay,
            'auto_send_at'  => $auto_send_at->toIso8601String(),
            'token'         => $token,
        ]);

        // Se re-emite el mensaje ya con `ai_auto_send_at` cargado: el front lo recibió antes
        // por el broadcast del alta (sin la fecha) y necesita el dato para el contador regresivo.
        $chat = WhatsappChat::find($message->whatsapp_chat_id);
        if (! is_null($chat)) {
            WhatsappChatHelper::broadcast_update((int) $chat->user_id, $chat, $message);
        }
    }

    /**
     * Invalida el job de auto-envío pendiente de un mensaje (lo confirmó o lo descartó una
     * persona, o quedó obsoleto porque el cliente siguió escribiendo) y le saca la fecha de
     * auto-envío para que el front deje de mostrar el contador.
     *
     * Se usa `update()` por query builder en vez de `save()` porque el caller puede estar por
     * borrar la fila y no siempre tiene el modelo cargado.
     *
     * @param int $message_id
     *
     * @return void
     */
    public function cancel_for_message(int $message_id): void
    {
        $this->bump_token($message_id);

        WhatsappChatMessage::where('id', $message_id)->update(['ai_auto_send_at' => null]);
    }

    /**
     * Indica si el token que trae el job sigue vigente para ese mensaje.
     *
     * @param int $message_id
     * @param int $token
     *
     * @return bool
     */
    public function is_token_current(int $message_id, int $token): bool
    {
        $current = Cache::get($this->cache_key($message_id));

        return (int) $current === $token;
    }

    /**
     * Incrementa el token de auto-envío del mensaje y lo persiste en caché.
     *
     * @param int $message_id
     *
     * @return int
     */
    private function bump_token(int $message_id): int
    {
        $cache_key = $this->cache_key($message_id);
        $current = (int) Cache::get($cache_key, 0);
        $next = $current + 1;

        Cache::put($cache_key, $next, self::CACHE_TTL_SECONDS);

        return $next;
    }

    /**
     * Arma la clave de caché del token de auto-envío del mensaje.
     *
     * @param int $message_id
     *
     * @return string
     */
    private function cache_key(int $message_id): string
    {
        return self::CACHE_KEY_PREFIX.$message_id;
    }
}
