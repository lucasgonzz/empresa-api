<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\WhatsappChatHelper;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Services\WhatsappAiAutoSendScheduler;
use App\Services\WhatsappBotSendService;
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
 * Todo el control de "¿sigue vigente esto?" pasa por el token en caché de
 * `WhatsappAiAutoSendScheduler`: si una persona ya lo mandó a mano, si lo descartó, o si el
 * cliente siguió escribiendo y el mensaje se borró, el token quedó invalidado y este job se
 * descarta solo.
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
     * Envía el mensaje si sigue pendiente, el token no fue invalidado y la ventana de 24 h
     * de Meta sigue abierta.
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

            $wa_message_id = (new WhatsappBotSendService())->send_text($chat->phone, (string) $message->body, $config);

            WhatsappChatHelper::mark_ai_message_sent($message, $wa_message_id);

            Log::channel('daily')->info('AutoSendWhatsappAiMessageJob: respuesta del agente enviada automáticamente.', [
                'message_id'    => $this->message_id,
                'chat_id'       => $chat->id,
                'wa_message_id' => $wa_message_id,
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
