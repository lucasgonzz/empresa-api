<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\WhatsappChatHelper;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Services\WhatsappAiAutoSendScheduler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Manda por WhatsApp una respuesta del agente que quedó esperando confirmación humana, si
 * nadie la confirmó ni la descartó antes de que se cumpliera `ai_confirm_delay_seconds`.
 *
 * El primer control de "¿sigue vigente esto?" es el token de `WhatsappAiAutoSendScheduler`,
 * que vive en la columna `whatsapp_chat_messages.ai_auto_send_token` (EN LA BASE, no en
 * caché: el motivo está escrito en `WhatsappAiAutoSendScheduler::bump_token()`). Si una
 * persona ya lo mandó a mano, si lo descartó, o si el cliente siguió escribiendo y el mensaje
 * se borró, el token quedó invalidado y este job se descarta solo al despertarse.
 *
 * 🔴 Pero el token NO alcanza por sí solo, y por eso abajo hay más chequeos. El token se lee
 * una vez, al principio, y el POST a Kapso sale varios pasos después: todo lo que pase en el
 * medio (que el operador confirme, que apaguen el bot) ocurre con el token ya leído. El
 * envío doble lo evita la transición de estado condicional de
 * `WhatsappChatHelper::send_pending_ai_message()`, que es la única punta que manda de verdad.
 */
class AutoSendWhatsappAiMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var int ID del WhatsappChatMessage pendiente de confirmación.
     */
    private $message_id;

    /**
     * @var int Token de auto-envío; tiene que seguir siendo el vigente al ejecutarse.
     */
    private $auto_send_token;

    /**
     * @param int $message_id
     * @param int $auto_send_token
     */
    public function __construct(int $message_id, int $auto_send_token)
    {
        $this->message_id = $message_id;
        $this->auto_send_token = $auto_send_token;
    }

    /**
     * Envía el mensaje si sigue pendiente, el token no fue invalidado, el bot de la empresa
     * y la IA del chat siguen prendidos, y la ventana de 24 h de Meta sigue abierta.
     *
     * @param WhatsappAiAutoSendScheduler $scheduler Inyectado por el container.
     *
     * @return void
     */
    public function handle(WhatsappAiAutoSendScheduler $scheduler): void
    {
        try {
            if (! $scheduler->is_token_current($this->message_id, $this->auto_send_token)) {
                Log::channel('daily')->debug('AutoSendWhatsappAiMessageJob: omitido (token obsoleto).', [
                    'message_id'      => $this->message_id,
                    'auto_send_token' => $this->auto_send_token,
                ]);

                return;
            }

            $message = WhatsappChatMessage::find($this->message_id);
            if (is_null($message)) {
                // La fila se borró: el cliente siguió escribiendo y se descartó el pendiente.
                return;
            }

            if ((string) $message->ai_status !== 'a_confirmar') {
                // Ya lo mandó una persona desde el módulo.
                return;
            }

            $chat = WhatsappChat::find($message->whatsapp_chat_id);
            if (is_null($chat)) {
                Log::channel('daily')->warning('AutoSendWhatsappAiMessageJob: chat no encontrado.', [
                    'message_id' => $this->message_id,
                ]);

                return;
            }

            $config = WhatsappBotConfig::where('user_id', $chat->user_id)->first();
            if (is_null($config)) {
                Log::channel('daily')->warning('AutoSendWhatsappAiMessageJob: sin configuración de WhatsApp para la empresa.', [
                    'message_id' => $this->message_id,
                    'user_id'    => $chat->user_id,
                ]);

                return;
            }

            // 🔴 Se revalidan las dos banderas de "¿el agente puede contestar en este chat?",
            // igual que hace GenerateWhatsappAiReplyJob después de su propia espera: entre que
            // se generó la respuesta y venció el plazo de confirmación pudieron apagar el bot
            // de toda la empresa (`is_active`) o la IA de este chat puntual (`ai_enabled`).
            // Sin esto, apagar la IA no frenaba nada: el mensaje salía igual al vencer el
            // plazo, encima del que ya había mandado el operador a mano.
            // No se borra la respuesta pendiente: apagar el bot no es descartarla. Queda en
            // 'a_confirmar' con el motivo escrito, y el operador decide si la manda o no.
            if (! $config->is_active || ! $chat->ai_enabled) {
                $message->send_error = 'No se envió automáticamente: la respuesta con IA quedó apagada en este chat o en la empresa. Confirmalo a mano si querés mandarlo igual.';
                $message->save();

                Log::channel('daily')->info('AutoSendWhatsappAiMessageJob: no se envió (bot inactivo o IA apagada en el chat).', [
                    'message_id' => $this->message_id,
                    'chat_id'    => $chat->id,
                    'is_active'  => (bool) $config->is_active,
                    'ai_enabled' => (bool) $chat->ai_enabled,
                ]);

                WhatsappChatHelper::broadcast_update((int) $chat->user_id, $chat, $message);

                return;
            }

            // Guard de la ventana de 24 h de Meta: entre que se generó la respuesta y venció
            // la espera de confirmación pudo cerrarse. Mandar texto libre ahí falla en Meta,
            // así que el mensaje se deja en 'a_confirmar' con el motivo escrito para que el
            // operador lo vea y lo resuelva con una plantilla.
            if (! $chat->is_within_service_window()) {
                $message->send_error = 'No se envió automáticamente: la ventana de 24 horas de WhatsApp se cerró. Respondé con una plantilla.';
                $message->save();

                Log::channel('daily')->warning('AutoSendWhatsappAiMessageJob: no se envió (ventana de 24 h cerrada).', [
                    'message_id'      => $this->message_id,
                    'chat_id'         => $chat->id,
                    'last_inbound_at' => (string) $chat->last_inbound_at,
                ]);

                WhatsappChatHelper::broadcast_update((int) $chat->user_id, $chat, $message);

                return;
            }

            // El envío entero (ganar la transición, mandar por Kapso y marcar el resultado)
            // vive en el helper, compartido con el endpoint de confirmación del operador. Es
            // el único lugar donde se resuelve quién de los dos manda, así que no se puede
            // volver a un `send_text()` suelto acá: serían dos envíos al cliente.
            $resultado = WhatsappChatHelper::send_pending_ai_message($message, $chat, $config);

            if ($resultado === WhatsappChatHelper::ENVIO_YA_TOMADO) {
                Log::channel('daily')->info('AutoSendWhatsappAiMessageJob: omitido (el operador lo confirmó justo antes).', [
                    'message_id' => $this->message_id,
                    'chat_id'    => $chat->id,
                ]);

                return;
            }

            if ($resultado === WhatsappChatHelper::ENVIO_FALLIDO) {
                // El helper ya dejó la fila marcada como fallida y logueó el motivo. No se
                // reintenta desde acá: el mensaje volvió a 'a_confirmar' y lo resuelve el
                // operador, que es quien puede ver si la clave de Kapso venció.
                Log::channel('daily')->warning('AutoSendWhatsappAiMessageJob: WhatsApp rechazó el envío, el mensaje quedó sin enviar.', [
                    'message_id' => $this->message_id,
                    'chat_id'    => $chat->id,
                ]);

                return;
            }

            Log::channel('daily')->info('AutoSendWhatsappAiMessageJob: respuesta del agente enviada automáticamente.', [
                'message_id'    => $this->message_id,
                'chat_id'       => $chat->id,
                'wa_message_id' => $message->wa_message_id,
            ]);
        } catch (\Throwable $exception) {
            // No se re-lanza por el mismo motivo que en GenerateWhatsappAiReplyJob: el envío
            // ya loguea sus propios errores adentro de WhatsappBotSendService.
            Log::channel('daily')->error('AutoSendWhatsappAiMessageJob: excepción al enviar la respuesta del agente.', [
                'message_id' => $this->message_id,
                'error'      => $exception->getMessage(),
            ]);
        }
    }
}
