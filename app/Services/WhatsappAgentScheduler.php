<?php

namespace App\Services;

use App\Http\Controllers\Helpers\WhatsappChatHelper;
use App\Jobs\GenerateWhatsappAiReplyJob;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Programa la respuesta del agente de WhatsApp después de un mensaje entrante, con demora
 * configurable (`whatsapp_bot_configs.ai_reply_delay_seconds`) y reinicio de la espera
 * (debounce). Es lo que hace que el agente conteste UNA sola vez cuando el cliente manda
 * tres mensajes seguidos.
 *
 * Cómo funciona el debounce, porque es el corazón de todo esto y no es obvio leyendo el job
 * suelto: cada mensaje entrante incrementa un contador guardado en la columna
 * `whatsapp_chats.ai_schedule_token` (el "token") y despacha un job llevándose ese número.
 * Cuando el job se despierta, compara el token que trae contra el vigente: si no coincide es
 * porque después de él entró otro mensaje, así que se descarta solo sin generar nada. De los
 * tres jobs que dispararon los tres mensajes, solo el último sobrevive — y ese ve los tres
 * mensajes en el historial.
 *
 * Es el mismo patrón ya probado en producción en admin-api
 * (`LeadAiSuggestionScheduler` + `GenerateLeadAiSuggestionJob`), con una diferencia que NO
 * es cosmética y está explicada en `bump_token()`: allá el token vive en caché, acá tiene
 * que vivir en la base.
 */
class WhatsappAgentScheduler
{
    /** Tabla donde vive el token de debounce del chat. */
    private const TOKEN_TABLE = 'whatsapp_chats';

    /** Columna contador del token de debounce, por chat. */
    private const TOKEN_COLUMN = 'ai_schedule_token';

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
        // Los tokens válidos arrancan en 1. Un 0 significa "nunca se programó nada acá" (es
        // el default de la columna, y también lo que devuelve `bump_token()` cuando el chat
        // ya no existe), así que nunca puede estar vigente.
        if ($token <= 0) {
            return false;
        }

        $current = DB::table(self::TOKEN_TABLE)
            ->where('id', $chat_id)
            ->value(self::TOKEN_COLUMN);

        // Chat borrado: no hay token vigente y el job que lo traiga se descarta. El chequeo
        // explícito de null es necesario porque `(int) null` es 0 y daría un falso positivo
        // contra un token 0.
        if (is_null($current)) {
            return false;
        }

        return (int) $current === $token;
    }

    /**
     * Incrementa el token de programación del chat y devuelve el valor nuevo.
     *
     * 🔴 ESTE TOKEN VA A LA BASE Y NO A `Cache::`, Y NO ES POR GUSTO. Si estás por
     * "simplificar" esto volviendo a `Cache::get()` / `Cache::put()` como en admin-api,
     * leé esto primero: `empresa-api` corre con `CACHE_DRIVER=array` (ver `.env`), que es
     * una caché en memoria del proceso y muere con él. Y este token lo escribe UN proceso
     * (el request del webhook de Kapso) y lo tiene que leer OTRO (el worker de
     * `queue:work`, porque `QUEUE_CONNECTION=database`). Con `array`, el worker levanta con
     * la caché vacía, `Cache::get()` devuelve null, el token nunca coincide y el job se
     * descarta EN SILENCIO: con cualquier demora mayor a 0 el agente no contesta nunca y no
     * queda ni un error en el log. En admin-api el mismo código anda solo porque allá el
     * driver es `file`. Tampoco alcanza con cambiar el driver: `.env` no está versionado y
     * no controlamos el de cada cliente.
     *
     * Sobre la atomicidad, que es el otro motivo por el que esto no es un simple
     * `leer + 1 + guardar`: dos mensajes entrantes casi simultáneos (dos requests del
     * webhook en paralelo) no pueden llevarse el mismo token, porque los dos jobs se
     * creerían vigentes y el cliente recibiría dos respuestas. Por eso el bump corre dentro
     * de una transacción que primero toma el lock de la fila con `lockForUpdate()`
     * (`SELECT ... FOR UPDATE`): el segundo proceso queda esperando ahí hasta que el
     * primero commitea, así que lee el valor ya incrementado y se lleva el siguiente. El
     * `increment()` es además atómico en SQL (`SET col = col + 1`, no manda un valor
     * calculado en PHP), o sea que el contador nunca retrocede aunque el lock no estuviera.
     *
     * Se usa el query builder crudo y no Eloquent a propósito: `Model::update()` e
     * `increment()` de Eloquent tocan `updated_at`, y un bump de token no es una
     * modificación del chat que la interfaz tenga que ver.
     *
     * @param int $chat_id
     *
     * @return int Token nuevo, el que se le pasa al job recién encolado. 0 si el chat ya no
     *             existe (nunca es un token vigente, así que el job se auto-descarta).
     */
    private function bump_token(int $chat_id): int
    {
        return (int) DB::transaction(function () use ($chat_id) {
            $current = DB::table(self::TOKEN_TABLE)
                ->where('id', $chat_id)
                ->lockForUpdate()
                ->value(self::TOKEN_COLUMN);

            if (is_null($current)) {
                return 0;
            }

            DB::table(self::TOKEN_TABLE)
                ->where('id', $chat_id)
                ->increment(self::TOKEN_COLUMN);

            // El valor nuevo se sabe con certeza porque el lock ya es nuestro: nadie más
            // pudo tocar la fila entre el SELECT y el UPDATE. Por eso no hace falta releer.
            return (int) $current + 1;
        });
    }
}
