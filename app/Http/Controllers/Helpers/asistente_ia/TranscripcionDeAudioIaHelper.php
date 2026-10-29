<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\AiTokenUsageHelper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pasar a texto un audio del dueño con la API de OpenAI (misión app-ia-y-memoria, 29/10/2026, §4 E1.4
 * del plan): `POST /v1/audio/transcriptions`, modelo `services.openai.modelo_transcripcion`
 * (OPENAI_MODELO_TRANSCRIPCION, default `gpt-4o-mini-transcribe`), en español.
 *
 * Lo usan dos lados: el job de respuesta, para un mensaje de audio de la app (antes de llamar al
 * modelo), y `ia-app/transcribir`, para el dictado del cuestionario.
 *
 * 🔴 NUNCA LANZA: devuelve null si no hay clave, si OpenAI falla o si contesta vacío. Un audio que no
 * se pudo transcribir es un hecho y se resuelve con un texto fijo (las mismas constantes que el
 * canal de WhatsApp: AdminSync\AsistenteController::AUDIO_SIN_TRANSCRIPCION y
 * RESPUESTA_AUDIO_SIN_TRANSCRIPCION), sin gastar una llamada a la IA.
 *
 * La clave es la de `services.openai` (la misma de los embeddings) y la configuración TLS la de
 * Anthropic, igual que ArticleEmbeddingService: un solo criterio para los entornos Windows/WAMP.
 */
class TranscripcionDeAudioIaHelper
{
    /** El endpoint de transcripción de OpenAI. */
    const URL = 'https://api.openai.com/v1/audio/transcriptions';

    /** Modelo por defecto si la config viene vacía. */
    const MODELO_POR_DEFECTO = 'gpt-4o-mini-transcribe';

    /**
     * Una nota de voz se transcribe en pocos segundos; más que esto ya es una falla.
     *
     * 🔴 Corre adentro del job de respuesta ANTES del loop del modelo, y el loop ya usa casi todo el
     * `$timeout` de 300 s del job (ResponderMensajeChatIaJob): PRESUPUESTO_SEGUNDOS (210) + una llamada en
     * vuelo (60) = 270. Con 30 acá, el peor caso del turno entero sigue abajo de 300.
     */
    const TIMEOUT_SEGUNDOS = 30;

    /** Techo del texto que se guarda: una nota de voz larga de verdad no llega a esto. */
    const MAX_CARACTERES = 10000;

    /** Proceso con el que se imputa el consumo en ai_token_usages. */
    const PROCESO = 'transcripcion_audio';

    /**
     * true si hay con qué transcribir (la clave de OpenAI cargada).
     *
     * @return bool
     */
    public static function disponible(): bool
    {
        return trim((string) config('services.openai.api_key', '')) !== '';
    }

    /**
     * El modelo de transcripción configurado.
     *
     * @return string
     */
    public static function modelo(): string
    {
        $modelo = trim((string) config('services.openai.modelo_transcripcion', ''));

        return $modelo === '' ? self::MODELO_POR_DEFECTO : $modelo;
    }

    /**
     * Transcribe un audio. Devuelve `['texto' => string, 'duracion_seg' => float|null]` o null si no se
     * pudo.
     *
     * @param  string  $binario
     * @param  string  $nombre_de_archivo  Con su extensión: OpenAI decide el formato por el nombre.
     * @param  array  $imputar  `user_id`, `auth_user_id`, `ai_conversation_id` para el metering (opcionales).
     * @return array{texto: string, duracion_seg: float|null}|null
     */
    public static function transcribir($binario, $nombre_de_archivo, array $imputar = [])
    {
        if (!self::disponible() || (string) $binario === '') {

            return null;
        }

        $modelo = self::modelo();

        try {

            $response = self::cliente_http()
                ->attach('file', (string) $binario, (string) $nombre_de_archivo)
                ->post(self::URL, [
                    'model'           => $modelo,
                    'language'        => 'es',
                    'response_format' => 'json',
                ]);

        } catch (\Throwable $e) {

            Log::warning('TranscripcionDeAudioIaHelper: falló la llamada a OpenAI', [
                'excepcion' => get_class($e),
                'error'     => $e->getMessage(),
            ]);

            return null;
        }

        if (!$response->successful()) {

            Log::warning('TranscripcionDeAudioIaHelper: OpenAI contestó con error', [
                'http'  => $response->status(),
                'error' => mb_substr((string) $response->body(), 0, 500),
            ]);

            return null;
        }

        $body = $response->json();

        if (!is_array($body)) {

            return null;
        }

        self::registrar_consumo($body, $modelo, $imputar);

        $texto = trim((string) ($body['text'] ?? ''));

        if ($texto === '') {

            return null;
        }

        $duracion = null;

        if (isset($body['duration']) && is_numeric($body['duration'])) {
            $duracion = (float) $body['duration'];
        } elseif (isset($body['usage']['seconds']) && is_numeric($body['usage']['seconds'])) {
            $duracion = (float) $body['usage']['seconds'];
        }

        return [
            'texto'        => mb_substr($texto, 0, self::MAX_CARACTERES),
            'duracion_seg' => $duracion,
        ];
    }

    /**
     * Registra el consumo de la transcripción, si hay a quién imputárselo. Protegido: el metering nunca
     * voltea una transcripción que salió bien.
     *
     * @param  array  $body
     * @param  string  $modelo
     * @param  array  $imputar
     * @return void
     */
    protected static function registrar_consumo(array $body, $modelo, array $imputar)
    {
        if (empty($imputar['user_id'])) {

            return;
        }

        try {

            AiTokenUsageHelper::registrar([
                'user_id'            => (int) $imputar['user_id'],
                'auth_user_id'       => isset($imputar['auth_user_id']) ? $imputar['auth_user_id'] : null,
                'proceso'            => self::PROCESO,
                'proveedor'          => 'openai',
                'modelo'             => $modelo,
                'body'               => $body,
                'ai_conversation_id' => isset($imputar['ai_conversation_id']) ? $imputar['ai_conversation_id'] : null,
            ]);

        } catch (\Throwable $e) {

            Log::warning('TranscripcionDeAudioIaHelper: no se pudo registrar el consumo -- ' . $e->getMessage());
        }
    }

    /**
     * El cliente HTTP de OpenAI, con la misma configuración TLS que usa ArticleEmbeddingService.
     *
     * @return \Illuminate\Http\Client\PendingRequest
     */
    protected static function cliente_http()
    {
        $http = Http::withToken((string) config('services.openai.api_key', ''))
            ->acceptJson()
            ->timeout(self::TIMEOUT_SEGUNDOS);

        $verify_ssl = (bool) config('services.anthropic.verify_ssl', true);
        $ca_bundle  = config('services.anthropic.ca_bundle');

        if (!$verify_ssl) {
            $http = $http->withoutVerifying();
        } elseif (is_string($ca_bundle) && $ca_bundle !== '' && is_file($ca_bundle)) {
            $http = $http->withOptions(['verify' => $ca_bundle]);
        }

        return $http;
    }
}
