<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * El audio que el dueño le manda al asistente desde la app (misión app-ia-y-memoria, 29/10/2026,
 * §4 E1.3 y E1.4 del plan): validarlo, guardarlo, describirlo en la respuesta y releerlo para
 * transcribirlo.
 *
 * El mensaje del dueño nace con `tipo = 'audio'`, `contenido = ''` y la transcripción `pendiente`. El
 * job de respuesta lo transcribe ANTES de llamar al modelo (TranscripcionDeAudioIaHelper) y deja el
 * texto en `contenido`: desde ahí, para el resto del sistema, es un mensaje de texto más.
 *
 * Los datos del audio van en la columna `ai_messages.audio` (json): `{path, nombre, ext, mime, tamano,
 * duracion_seg, transcripcion}`. Hacia afuera sale SOLO `{url, duracion_seg, transcripcion}`
 * (para_la_respuesta()): la ruta del disco no se muestra nunca.
 *
 * El binario va al disco `local` (privado): es la voz del dueño hablando de su negocio. Ruta:
 * `asistente_audios/{owner_id}/{ai_message_id}.{ext}`.
 */
class AudioDelMensajeIaHelper
{
    /** Formatos aceptados (contrato C5): los que graba MediaRecorder en Chrome, Firefox y Safari, y los comunes. */
    const FORMATOS = ['webm', 'ogg', 'oga', 'mp3', 'm4a', 'mp4', 'wav'];

    /** Peso máximo, en MB (contrato C5). */
    const MAX_MB = 10;

    /**
     * Además de cualquier `audio/*`, los mimes con que finfo reconoce un audio grabado por el navegador:
     * MediaRecorder de Chrome y Firefox graba en un contenedor webm, el ogg suele salir como
     * application/ogg y Safari graba en mp4.
     */
    const MIMES_DE_AUDIO_EXTRA = ['video/webm', 'application/ogg', 'video/mp4'];

    /** Duración máxima que se acepta como dato (una hora); más que eso es un dato roto. */
    const MAX_DURACION_SEG = 3600;

    const TRANSCRIPCION_PENDIENTE = 'pendiente';

    const TRANSCRIPCION_LISTA = 'lista';

    const TRANSCRIPCION_FALLIDA = 'fallida';

    /** Ruta del binario, relativa a la base del API (como FotosDelMensajeIaHelper::RUTA). */
    const RUTA = 'api/ai-mensajes';

    /**
     * El motivo del rechazo de un audio subido (texto para el 422), o null si sirve.
     *
     * @param  mixed  $audio  UploadedFile
     * @return string|null
     */
    public static function motivo_de_rechazo($audio)
    {
        if (is_null($audio) || is_array($audio) || !is_object($audio) || !method_exists($audio, 'isValid') || !$audio->isValid()) {

            return 'El audio no llegó completo.';
        }

        if ($audio->getSize() > self::MAX_MB * 1024 * 1024) {

            return 'El audio pesa más de ' . self::MAX_MB . ' MB.';
        }

        if (is_null(self::extension_de($audio))) {

            return 'Ese formato de audio no lo puedo escuchar. Se aceptan: ' . implode(', ', self::FORMATOS) . '.';
        }

        /*
         * 🔴 POR EL CONTENIDO, NO POR EL NOMBRE (corrección de la revisión, 29/10/2026): un HTML renombrado
         * `.webm` pasaba la extensión, quedaba guardado y se servía con el mime que adivinara el navegador.
         * finfo lee la firma del archivo.
         */
        if (is_null(self::mime_de_audio($audio->getRealPath()))) {

            return 'Ese archivo no es un audio que pueda escuchar.';
        }

        return null;
    }

    /**
     * El mime de un archivo según su CONTENIDO (finfo), si es de audio; null si no lo es.
     *
     * @param  string  $ruta
     * @return string|null
     */
    public static function mime_de_audio($ruta)
    {
        try {

            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file((string) $ruta);

        } catch (\Throwable $e) {

            return null;
        }

        return self::es_mime_de_audio($mime) ? $mime : null;
    }

    /**
     * true si el mime es de audio: `audio/*` o uno de MIMES_DE_AUDIO_EXTRA.
     *
     * @param  string  $mime
     * @return bool
     */
    public static function es_mime_de_audio($mime): bool
    {
        $mime = strtolower(trim((string) $mime));

        return strpos($mime, 'audio/') === 0 || in_array($mime, self::MIMES_DE_AUDIO_EXTRA, true);
    }

    /**
     * La extensión del audio entre las aceptadas, o null. Primero la del nombre (lo que manda la app),
     * después la que deduce Symfony de los bytes.
     *
     * @param  mixed  $audio
     * @return string|null
     */
    public static function extension_de($audio)
    {
        $candidatas = [
            strtolower((string) $audio->getClientOriginalExtension()),
            strtolower((string) $audio->guessExtension()),
        ];

        foreach ($candidatas as $extension) {

            if (in_array($extension, self::FORMATOS, true)) {

                return $extension;
            }
        }

        return null;
    }

    /**
     * Guarda el audio de un mensaje ya creado y devuelve los datos para la columna `audio`, o null si
     * no se pudo guardar.
     *
     * @param  \App\Models\AiMessage  $mensaje
     * @param  mixed  $audio  UploadedFile, ya validado por motivo_de_rechazo().
     * @param  int  $owner_id
     * @param  mixed  $duracion_seg  Lo que dice la app (opcional); se descarta si no es un número razonable.
     * @return array<string, mixed>|null
     */
    public static function guardar(AiMessage $mensaje, $audio, $owner_id, $duracion_seg = null)
    {
        $extension = self::extension_de($audio);

        $path = 'asistente_audios/' . (int) $owner_id . '/' . (int) $mensaje->id . '.' . $extension;

        try {

            Storage::disk('local')->put($path, (string) file_get_contents($audio->getRealPath()));

        } catch (\Throwable $e) {

            Log::warning('AudioDelMensajeIaHelper: no se pudo guardar el audio del mensaje', [
                'ai_message_id' => $mensaje->id,
                'error'         => $e->getMessage(),
            ]);

            return null;
        }

        return [
            'path'          => $path,
            'nombre'        => mb_substr(trim((string) $audio->getClientOriginalName()), 0, 120),
            'ext'           => $extension,
            /* El validado por el contenido (motivo_de_rechazo()): es el que se manda al servirlo. */
            'mime'          => (string) self::mime_de_audio($audio->getRealPath()),
            'tamano'        => (int) $audio->getSize(),
            'duracion_seg'  => self::duracion_valida($duracion_seg),
            'transcripcion' => self::TRANSCRIPCION_PENDIENTE,
        ];
    }

    /**
     * Una duración en segundos razonable, o null.
     *
     * @param  mixed  $valor
     * @return float|null
     */
    public static function duracion_valida($valor)
    {
        if (is_null($valor) || $valor === '' || !is_numeric($valor)) {

            return null;
        }

        $segundos = round((float) $valor, 1);

        return ($segundos > 0 && $segundos <= self::MAX_DURACION_SEG) ? $segundos : null;
    }

    /**
     * El binario del audio de un mensaje, o null si no hay o ya no está en el disco.
     *
     * @param  \App\Models\AiMessage  $mensaje
     * @return string|null
     */
    public static function binario(AiMessage $mensaje)
    {
        $audio = self::datos($mensaje);

        if (is_null($audio) || empty($audio['path'])) {

            return null;
        }

        try {

            if (!Storage::disk('local')->exists($audio['path'])) {

                return null;
            }

            $binario = Storage::disk('local')->get($audio['path']);

        } catch (\Throwable $e) {

            Log::warning('AudioDelMensajeIaHelper: no se pudo leer el audio', [
                'ai_message_id' => $mensaje->id,
                'error'         => $e->getMessage(),
            ]);

            return null;
        }

        return $binario === '' ? null : $binario;
    }

    /**
     * Los datos crudos del audio del mensaje (la columna), o null.
     *
     * @param  \App\Models\AiMessage  $mensaje
     * @return array<string, mixed>|null
     */
    public static function datos(AiMessage $mensaje)
    {
        $audio = $mensaje->audio;

        return is_array($audio) && count($audio) ? $audio : null;
    }

    /**
     * true si el mensaje trae un audio con la transcripción todavía pendiente.
     *
     * @param  \App\Models\AiMessage  $mensaje
     * @return bool
     */
    public static function falta_transcribir(AiMessage $mensaje): bool
    {
        $audio = self::datos($mensaje);

        return !is_null($audio) && (string) ($audio['transcripcion'] ?? '') === self::TRANSCRIPCION_PENDIENTE;
    }

    /**
     * Deja la transcripción en `lista` o `fallida` (y la duración, si la trajo la transcripción y la app
     * no la había mandado). Guarda el mensaje.
     *
     * @param  \App\Models\AiMessage  $mensaje
     * @param  string  $estado
     * @param  float|null  $duracion_seg
     * @return void
     */
    public static function marcar(AiMessage $mensaje, $estado, $duracion_seg = null)
    {
        $audio = self::datos($mensaje);

        if (is_null($audio)) {

            return;
        }

        $audio['transcripcion'] = $estado;

        if (is_null($audio['duracion_seg'] ?? null)) {
            $audio['duracion_seg'] = self::duracion_valida($duracion_seg);
        }

        $mensaje->audio = $audio;
        $mensaje->save();
    }

    /**
     * Cierra como `fallida` una transcripción que se quedó pendiente porque el turno no llegó a correr
     * (tope del plan, extensión apagada, job caído). Sin esto, la app mostraría "transcribiendo…" para
     * siempre debajo del audio.
     *
     * @param  \App\Models\AiMessage|null  $mensaje
     * @return void
     */
    public static function cerrar_si_quedo_pendiente($mensaje)
    {
        if (!($mensaje instanceof AiMessage) || !self::falta_transcribir($mensaje)) {

            return;
        }

        try {

            self::marcar($mensaje, self::TRANSCRIPCION_FALLIDA);

        } catch (\Throwable $e) {

            Log::warning('AudioDelMensajeIaHelper: no se pudo cerrar la transcripción pendiente', [
                'ai_message_id' => $mensaje->id,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    /**
     * Lo que viaja en el mensaje (contrato C5): `{url, duracion_seg, transcripcion}` o null.
     *
     * @param  \App\Models\AiMessage  $mensaje
     * @return array<string, mixed>|null
     */
    public static function para_la_respuesta(AiMessage $mensaje)
    {
        $audio = self::datos($mensaje);

        if (is_null($audio) || (int) $mensaje->id <= 0) {

            return null;
        }

        $estado = (string) ($audio['transcripcion'] ?? self::TRANSCRIPCION_PENDIENTE);

        return [
            'url'           => url(self::RUTA . '/' . (int) $mensaje->id . '/audio'),
            'duracion_seg'  => isset($audio['duracion_seg']) && is_numeric($audio['duracion_seg']) ? (float) $audio['duracion_seg'] : null,
            'transcripcion' => in_array($estado, [self::TRANSCRIPCION_PENDIENTE, self::TRANSCRIPCION_LISTA, self::TRANSCRIPCION_FALLIDA], true)
                ? $estado
                : self::TRANSCRIPCION_PENDIENTE,
        ];
    }
}
