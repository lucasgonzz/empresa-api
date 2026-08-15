<?php

namespace App\Services;

use App\Http\Controllers\Helpers\WhatsappChatHelper;
use App\Jobs\AutoSendWhatsappAiMessageJob;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Programa el envío automático de una respuesta ya generada por el agente, después de la
 * ventana de confirmación humana que configuró el dueño
 * (`whatsapp_bot_configs.ai_confirm_delay_seconds`).
 *
 * Mismo mecanismo de token que `WhatsappAgentScheduler` —contador en la base, no en caché,
 * por el motivo que está escrito en `bump_token()`— pero por MENSAJE en vez de por chat
 * (columna `whatsapp_chat_messages.ai_auto_send_token`): si una persona confirma o descarta
 * el mensaje antes de que se cumpla la espera, el token se invalida y el job pendiente se
 * auto-descarta cuando se despierta. Así el mensaje nunca se manda dos veces ni se manda uno
 * que ya fue descartado.
 *
 * Réplica adaptada de `admin-api\app\Services\LeadAiSuggestionAutoSendScheduler`.
 */
class WhatsappAiAutoSendScheduler
{
    /** Tabla donde vive el token de auto-envío del mensaje. */
    private const TOKEN_TABLE = 'whatsapp_chat_messages';

    /** Columna contador del token de auto-envío, por mensaje. */
    private const TOKEN_COLUMN = 'ai_auto_send_token';

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
        // Los tokens válidos arrancan en 1. Un 0 significa "nunca se programó nada acá" (es
        // el default de la columna, y también lo que devuelve `bump_token()` cuando el
        // mensaje ya no existe), así que nunca puede estar vigente.
        if ($token <= 0) {
            return false;
        }

        $current = DB::table(self::TOKEN_TABLE)
            ->where('id', $message_id)
            ->value(self::TOKEN_COLUMN);

        // Mensaje borrado (el cliente siguió escribiendo y se descartó el pendiente): no hay
        // token vigente. El chequeo explícito de null es necesario porque `(int) null` es 0 y
        // daría un falso positivo contra un token 0.
        if (is_null($current)) {
            return false;
        }

        return (int) $current === $token;
    }

    /**
     * Incrementa el token de auto-envío del mensaje y devuelve el valor nuevo.
     *
     * 🔴 ESTE TOKEN VA A LA BASE Y NO A `Cache::`, Y NO ES POR GUSTO. Si estás por
     * "simplificar" esto volviendo a `Cache::get()` / `Cache::put()` como en admin-api,
     * leé esto primero: `empresa-api` corre con `CACHE_DRIVER=array` (ver `.env`), que es
     * una caché en memoria del proceso y muere con él. Y este token lo escribe UN proceso
     * (el que genera la respuesta) y lo tiene que leer OTRO (el worker de `queue:work` que
     * ejecuta `AutoSendWhatsappAiMessageJob`, porque `QUEUE_CONNECTION=database`). Con
     * `array`, el worker levanta con la caché vacía, `Cache::get()` devuelve null, el token
     * nunca coincide y el job se descarta EN SILENCIO: con cualquier demora de confirmación
     * mayor a 0 el mensaje no sale nunca y no queda ni un error en el log. En admin-api el
     * mismo código anda solo porque allá el driver es `file`. Tampoco alcanza con cambiar el
     * driver: `.env` no está versionado y no controlamos el de cada cliente.
     *
     * Sobre la atomicidad: el bump corre dentro de una transacción que primero toma el lock
     * de la fila con `lockForUpdate()` (`SELECT ... FOR UPDATE`), así dos llamadas casi
     * simultáneas (por ejemplo alguien confirmando el mensaje justo cuando el agente lo está
     * reprogramando) no se pueden llevar el mismo número: la segunda espera al commit de la
     * primera y lee el valor ya incrementado. El `increment()` es además atómico en SQL
     * (`SET col = col + 1`, no manda un valor calculado en PHP).
     *
     * Se usa el query builder crudo y no Eloquent a propósito: `Model::update()` e
     * `increment()` de Eloquent tocan `updated_at`, y un bump de token no es una
     * modificación del mensaje que la interfaz tenga que ver.
     *
     * @param int $message_id
     *
     * @return int Token nuevo. 0 si el mensaje ya no existe (nunca es un token vigente, así
     *             que el job que lo traiga se auto-descarta).
     */
    private function bump_token(int $message_id): int
    {
        return (int) DB::transaction(function () use ($message_id) {
            $current = DB::table(self::TOKEN_TABLE)
                ->where('id', $message_id)
                ->lockForUpdate()
                ->value(self::TOKEN_COLUMN);

            if (is_null($current)) {
                return 0;
            }

            DB::table(self::TOKEN_TABLE)
                ->where('id', $message_id)
                ->increment(self::TOKEN_COLUMN);

            // El valor nuevo se sabe con certeza porque el lock ya es nuestro: nadie más
            // pudo tocar la fila entre el SELECT y el UPDATE. Por eso no hace falta releer.
            return (int) $current + 1;
        });
    }
}
