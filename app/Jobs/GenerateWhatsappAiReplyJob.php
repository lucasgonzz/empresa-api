<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\WhatsappChatHelper;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Services\WhatsappAgentScheduler;
use App\Services\WhatsappAiAutoSendScheduler;
use App\Services\WhatsappBotAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Genera la respuesta del agente de WhatsApp después de la demora configurada, y solo si
 * mientras tanto el cliente no siguió escribiendo (debounce por token, ver
 * `WhatsappAgentScheduler`).
 *
 * Hasta esta misión esto corría inline adentro del request del webhook de Kapso: la llamada
 * a Anthropic (y antes el RAG, que en MySQL carga todos los artículos en memoria) se comía
 * varios segundos del pedido HTTP que Kapso espera contestado. Ahora vive acá.
 */
class GenerateWhatsappAiReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var int ID del chat al que hay que responder.
     */
    private $chat_id;

    /**
     * @var int Token de debounce; tiene que seguir siendo el vigente al ejecutarse.
     */
    private $schedule_token;

    /**
     * @param int $chat_id
     * @param int $schedule_token
     */
    public function __construct(int $chat_id, int $schedule_token)
    {
        $this->chat_id = $chat_id;
        $this->schedule_token = $schedule_token;
    }

    /**
     * Genera la respuesta si el token sigue vigente y todavía hay algo sin contestar.
     *
     * @param WhatsappAgentScheduler $scheduler Inyectado por el container.
     *
     * @return void
     */
    public function handle(WhatsappAgentScheduler $scheduler): void
    {
        try {
            // ESTO ES LA AGRUPACIÓN: de los tres jobs que despacharon los tres mensajes
            // seguidos del cliente, solo el último trae el token vigente. Los dos primeros
            // se auto-descartan acá sin gastar una llamada a Anthropic.
            if (! $scheduler->is_token_current($this->chat_id, $this->schedule_token)) {
                Log::channel('daily')->debug('GenerateWhatsappAiReplyJob: omitido (token de debounce obsoleto).', [
                    'chat_id'        => $this->chat_id,
                    'schedule_token' => $this->schedule_token,
                ]);

                return;
            }

            $chat = WhatsappChat::find($this->chat_id);
            if (is_null($chat)) {
                Log::channel('daily')->warning('GenerateWhatsappAiReplyJob: chat no encontrado.', [
                    'chat_id' => $this->chat_id,
                ]);

                return;
            }

            $config = WhatsappBotConfig::where('user_id', $chat->user_id)->first();
            if (is_null($config)) {
                Log::channel('daily')->warning('GenerateWhatsappAiReplyJob: sin configuración de WhatsApp para la empresa.', [
                    'chat_id' => $this->chat_id,
                    'user_id' => $chat->user_id,
                ]);

                return;
            }

            // Se vuelve a chequear porque durante la espera pudieron apagar el bot de la
            // empresa o la IA de este chat puntual.
            if (! $config->is_active || ! $chat->ai_enabled) {
                Log::channel('daily')->debug('GenerateWhatsappAiReplyJob: omitido (bot inactivo o IA apagada en el chat).', [
                    'chat_id' => $this->chat_id,
                ]);

                return;
            }

            // Guard de la ventana de 24 h de Meta: con una demora configurada larga (o con la
            // cola atrasada) la ventana se pudo cerrar entre el mensaje entrante y este job.
            // Mandar texto libre fuera de ventana falla en Meta, así que ni se genera.
            if (! $chat->is_within_service_window()) {
                Log::channel('daily')->warning('GenerateWhatsappAiReplyJob: omitido (ventana de 24 h cerrada).', [
                    'chat_id'         => $this->chat_id,
                    'last_inbound_at' => (string) $chat->last_inbound_at,
                ]);

                return;
            }

            if (! $this->has_unanswered_inbound($chat)) {
                Log::channel('daily')->debug('GenerateWhatsappAiReplyJob: omitido (no hay mensajes del cliente sin responder).', [
                    'chat_id' => $this->chat_id,
                ]);

                return;
            }

            $texto = (new WhatsappBotAiService())->generate_response($chat, $config);

            if ($texto === '') {
                // Mismo comportamiento que tenía el controller: si la IA no devolvió nada
                // (error HTTP, sin API key, historial vacío) no se manda ni se persiste nada.
                Log::channel('daily')->warning('GenerateWhatsappAiReplyJob: respuesta IA vacía, no se persiste nada.', [
                    'chat_id' => $this->chat_id,
                ]);

                return;
            }

            // 🔴 Re-chequeo del token DESPUÉS de la llamada a Anthropic, y no es opcional: la
            // API tarda segundos y el cliente puede haber mandado otro mensaje mientras tanto.
            // Si pasó eso, esta respuesta ya está desactualizada — se tira y el job del token
            // nuevo va a generar una que sí contemple todo lo que escribió.
            if (! $scheduler->is_token_current($this->chat_id, $this->schedule_token)) {
                Log::channel('daily')->info('GenerateWhatsappAiReplyJob: respuesta descartada (llegaron mensajes nuevos mientras respondía la IA).', [
                    'chat_id'        => $this->chat_id,
                    'schedule_token' => $this->schedule_token,
                ]);

                return;
            }

            $message = WhatsappChatHelper::store_pending_ai_message($chat, $texto);

            // El auto-envío decide si sale al instante (ai_confirm_delay_seconds = 0) o si
            // espera la confirmación de una persona.
            (new WhatsappAiAutoSendScheduler())->schedule_for_message($message, $config);
        } catch (\Throwable $exception) {
            // No se re-lanza: el job no declara $tries, así que re-lanzar solo lo marcaría
            // como fallido sin reintentar nada. Todo lo caro (Anthropic, Kapso) ya tiene su
            // propio manejo de errores adentro de los services.
            Log::channel('daily')->error('GenerateWhatsappAiReplyJob: excepción al generar la respuesta del agente.', [
                'chat_id' => $this->chat_id,
                'error'   => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Indica si el último mensaje del chat es uno entrante del cliente, o sea si todavía hay
     * algo sin responder.
     *
     * Los mensajes `a_confirmar` se excluyen a propósito: todavía no se dijeron (nadie los
     * envió), así que no cuentan como respuesta. Es el mismo criterio que usa el historial
     * que ve la IA.
     *
     * @param WhatsappChat $chat
     *
     * @return bool
     */
    private function has_unanswered_inbound(WhatsappChat $chat): bool
    {
        $last_message = WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)
            ->where(function ($query) {
                $query->whereNull('ai_status')->orWhere('ai_status', '!=', 'a_confirmar');
            })
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->first();

        if (is_null($last_message)) {
            return false;
        }

        return $last_message->direction === 'in';
    }
}
