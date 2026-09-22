<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\AiTokenUsageHelper;
use App\Http\Controllers\Helpers\asistente_ia\ProveedorIaHelper;
use App\Models\AiConversation;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Infiere el título de una conversación del chat a partir de su primer
 * mensaje (misión chat-ia-y-modulo-ia, D19).
 *
 * Lo despacha send_message SOLO cuando la conversación tiene titulo null y
 * es su primer mensaje de usuario. Llamada corta y barata: el modelo
 * "general" del proveedor que eligió el DUEÑO de la conversación (misión
 * proveedores-ia-deepseek: Claude o DeepSeek, `services.<proveedor>.model`,
 * resuelto por ProveedorIaHelper — no se hardcodea uno distinto), max_tokens
 * 40, sin tools y sin system.
 *
 * Un título es COSMÉTICO: cualquier falla degrada a titulo null (la SPA
 * muestra "Nueva conversación") sin marcar error visible en ningún lado.
 * Este job nunca deja rastro de error en la conversación.
 */
class InferirTituloConversacionIaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Sin reintentos: si la inferencia falla, el título queda null para
     * siempre y no pasa nada.
     *
     * @var int
     */
    public $tries = 1;

    /** Techo de tokens de la respuesta: un título de 6 palabras sobra. */
    const MAX_TOKENS = 40;

    /** Timeout de la llamada HTTP, en segundos (respuesta cortísima). */
    const TIMEOUT_SEGUNDOS = 30;

    /** Largo máximo del título persistido (el de la columna titulo). */
    const MAX_LARGO_TITULO = 150;

    /** @var int */
    protected $ai_conversation_id;

    /** @var string Texto del primer mensaje del usuario, capturado al despachar. */
    protected $texto_primer_mensaje;

    /**
     * @param int $ai_conversation_id
     * @param string $texto_primer_mensaje
     */
    public function __construct($ai_conversation_id, $texto_primer_mensaje)
    {
        $this->ai_conversation_id = $ai_conversation_id;
        $this->texto_primer_mensaje = $texto_primer_mensaje;
    }

    public function handle()
    {
        $conversation = AiConversation::find($this->ai_conversation_id);

        // Conversación borrada entre el dispatch y el worker: nada que titular.
        if (!$conversation) {
            return;
        }

        // Guard de no pisar: si otro camino ya le puso título (p. ej. una
        // conversación de sugerencia), no se gasta la llamada.
        if (!is_null($conversation->titulo)) {
            return;
        }

        // El proveedor lo elige el dueño de la conversación; el modelo es el
        // general de ese proveedor y el gasto se registra con él.
        $eleccion  = ProveedorIaHelper::modelo_general(User::find($conversation->user_id));
        $proveedor = $eleccion['proveedor'];

        // Sin clave no se sale a la red: el título queda null y la SPA sigue
        // mostrando "Nueva conversación".
        if (! ProveedorIaHelper::hay_credenciales($proveedor)) {
            return;
        }

        try {
            $prompt = 'Resumí en un título de hasta 6 palabras, sin comillas y sin punto final, '
                . 'de qué trata este mensaje que una persona le escribió al asistente de su sistema de gestión. '
                . 'Respondé solo el título.'
                . "\n\n<mensaje>\n" . $this->texto_primer_mensaje . "\n</mensaje>";

            // El mismo payload para los dos proveedores; `thinking` solo viaja con DeepSeek.
            $response = ProveedorIaHelper::cliente_http($proveedor, self::TIMEOUT_SEGUNDOS)
                ->post(ProveedorIaHelper::url_messages($proveedor), ProveedorIaHelper::agregar_thinking([
                    'model'      => $eleccion['modelo'],
                    'max_tokens' => self::MAX_TOKENS,
                    'messages'   => [
                        [
                            'role'    => 'user',
                            'content' => $prompt,
                        ],
                    ],
                ], $eleccion['thinking']));

            if (!$response->successful()) {
                Log::warning('InferirTituloConversacionIaJob: la API devolvió error, el título queda null', [
                    'ai_conversation_id' => $this->ai_conversation_id,
                    'status'             => $response->status(),
                ]);

                return;
            }

            $body = $response->json();

            // El título también se paga: una fila de consumo por inferencia.
            AiTokenUsageHelper::registrar([
                'user_id'            => $conversation->user_id,
                'auth_user_id'       => $conversation->auth_user_id,
                'proceso'            => 'chat_titulo',
                'proveedor'          => $proveedor,
                'modelo'             => $eleccion['modelo'],
                'body'               => is_array($body) ? $body : [],
                'ai_conversation_id' => $conversation->id,
            ]);

            $titulo = $this->limpiar_titulo(
                isset($body['content'][0]['text']) ? (string) $body['content'][0]['text'] : ''
            );

            if ($titulo === '') {
                return;
            }

            $conversation->titulo = $titulo;
            $conversation->save();
        } catch (\Throwable $e) {
            // Cosmético: se loguea y el título queda null, sin error visible.
            Log::warning('InferirTituloConversacionIaJob: falló la inferencia, el título queda null', [
                'ai_conversation_id' => $this->ai_conversation_id,
                'message'            => $e->getMessage(),
            ]);
        }
    }

    /**
     * trim, comillas de los extremos afuera (rectas y tipográficas: los
     * modelos a veces titulan entre comillas aunque se les pida que no) y
     * recorte al largo de la columna.
     *
     * @param string $texto
     * @return string
     */
    protected function limpiar_titulo($texto)
    {
        $titulo = trim($texto);

        /*
         * preg_replace con /u y no trim($s, '"\'“”'): trim recorta de a BYTES
         * y con comillas tipográficas multibyte dejaría un carácter roto.
         */
        $titulo = (string) preg_replace('/^[\'"“”‘’]+|[\'"“”‘’]+$/u', '', $titulo);

        return mb_substr(trim($titulo), 0, self::MAX_LARGO_TITULO);
    }

    /*
     * El cliente HTTP que este job armaba a mano (una copia local del patrón de
     * ResumenIaService, porque en su misión no podía tocar el núcleo) ya no hace
     * falta: lo arma ProveedorIaHelper::cliente_http() para el proveedor que
     * corresponda, con el mismo bloque TLS de la casa.
     */
}
