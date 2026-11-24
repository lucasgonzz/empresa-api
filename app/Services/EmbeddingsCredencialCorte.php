<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Corte automático de la generación de embeddings cuando OpenAI rechaza la credencial.
 *
 * 🔴 POR QUÉ EXISTE. Con la clave revocada, cada artículo pendiente se despachaba una vez por pasada
 * del scheduler (cada 30 min), llamaba a OpenAI tres veces (`$tries = 3`) y dejaba una fila en
 * `failed_jobs`: 15.512 (Ferretotal) + 12.276 (Pesca) fallidos "Incorrect API key provided". Un error
 * de credencial es permanente: el primer rechazo ya dice todo lo que hay que saber. Este corte guarda
 * ese primer rechazo en el Cache por defecto y, mientras dure, nadie llama a OpenAI ni despacha jobs.
 *
 * QUÉ ABRE EL CORTE. Sólo respuestas con CUERPO DE ERROR DE OPENAI (JSON con `error.message`):
 *  - 401;
 *  - 403 (p. ej. país o proyecto no autorizado);
 *  - 429 con `error.code = insufficient_quota` (sin crédito).
 * NO lo abren: un 401/403 sin ese cuerpo (la página HTML de un proxy/WAF, que puede ser pasajera), los
 * 5xx, los timeouts, el 400 ni el 429 de rate limit. Esos siguen el camino de siempre (reintento).
 *
 * CUÁNTO DURA. `EMBEDDINGS_CORTE_MINUTOS` (config `services.openai.embeddings_corte_minutos`, default
 * 60). Vencido el TTL, la siguiente pasada del comando levanta los mismos artículos pendientes sin
 * intervención. El corte es POR INSTALACIÓN (cada una tiene su Cache).
 *
 * 🔴 SALIDA MANUAL (si la clave ya se corrigió y no se quiere esperar al vencimiento): en la carpeta
 * del frente, `php artisan cache:forget embeddings_credencial_rechazada`. Laravel pone el prefijo solo.
 * No se automatiza a propósito: es una decisión operativa.
 *
 * 🔴 TECHO REAL. Hasta 1 llamada rechazada POR WORKER que esté procesando embeddings en paralelo en el
 * instante de la apertura, más las búsquedas del bot que coincidan: dos workers pueden mandar su
 * llamada antes de que el primero escriba el Cache. No es "1 por instalación".
 *
 * 🔴 SI `CACHE_DRIVER` ES `array`, EL CORTE NO CRUZA PROCESOS (cada worker/request ve el suyo vacío) y
 * se vuelve a ~1 rechazo por job. Hay que verificar el driver efectivo del cliente al desplegar.
 */
class EmbeddingsCredencialCorte
{
    /** Clave del corte en el Cache por defecto (también es la que se pasa a `cache:forget`). */
    const CLAVE = 'embeddings_credencial_rechazada';

    /** Minutos que dura el corte si la configuración no dice otra cosa. */
    const MINUTOS_POR_DEFECTO = 60;

    /** Largo máximo del mensaje de OpenAI que se guarda (y se loguea). */
    const LARGO_MAXIMO_MENSAJE = 120;

    /**
     * Decide si una respuesta fallida de OpenAI tiene que abrir el corte.
     *
     * Exige el cuerpo de error de OpenAI (JSON con `error` objeto y `error.message` texto, la misma
     * forma que lee `ArticleEmbeddingService::generate_embedding`): lo que no la cumple (HTML de un
     * proxy, cuerpo vacío) no abre nada y sigue el camino de reintento de siempre.
     *
     * @param int   $status HTTP de la respuesta.
     * @param mixed $cuerpo Resultado de `$response->json()` (array, o null si no es JSON).
     *
     * @return bool
     */
    public static function debe_abrir(int $status, $cuerpo): bool
    {
        if (! is_array($cuerpo) || ! isset($cuerpo['error']) || ! is_array($cuerpo['error'])) {
            return false;
        }

        if (! isset($cuerpo['error']['message']) || ! is_string($cuerpo['error']['message'])) {
            return false;
        }

        if ($status === 401 || $status === 403) {
            return true;
        }

        // 429: sólo la cuota agotada es permanente; el rate limit se resuelve esperando.
        return $status === 429
            && isset($cuerpo['error']['code'])
            && $cuerpo['error']['code'] === 'insufficient_quota';
    }

    /**
     * Abre el corte con el primer rechazo del período.
     *
     * `Cache::add` hace que sólo la PRIMERA apertura del período escriba y loguee: las siguientes (otro
     * worker que llegó tarde) no pisan el estado ni repiten la línea de error.
     *
     * @param int    $status  HTTP del rechazo.
     * @param string $mensaje Mensaje de error de OpenAI (se recorta y se le tapa cualquier clave).
     *
     * @return void
     */
    public static function abrir(int $status, string $mensaje): void
    {
        $mensaje = self::mensaje_seguro($mensaje);
        $minutos = self::minutos();

        try {
            $abrio = Cache::add(self::CLAVE, [
                'desde'   => Carbon::now()->toIso8601String(),
                'status'  => $status,
                'mensaje' => $mensaje,
            ], $minutos * 60);
        } catch (\Throwable $exception) {
            // Si el Cache no responde el corte no puede guardarse: se avisa y el camino sigue como
            // antes (reintentos), que es lo que había sin esta protección.
            Log::channel('daily')->warning('EmbeddingsCredencialCorte: no se pudo guardar el corte en el Cache.', [
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        if (! $abrio) {
            return;
        }

        Log::channel('daily')->error(
            'OpenAI rechazó la credencial de embeddings (HTTP ' . $status . '): se corta la generación por '
            . $minutos . ' min, sin reintentos ni llamadas a OpenAI. Para reintentar antes (con la clave ya corregida): '
            . 'php artisan cache:forget ' . self::CLAVE,
            ['status' => $status, 'mensaje' => $mensaje, 'minutos' => $minutos]
        );
    }

    /**
     * Indica si hay un corte abierto (vigente) en esta instalación.
     *
     * @return bool
     */
    public static function abierto(): bool
    {
        return self::estado() !== null;
    }

    /**
     * Estado del corte: `{desde, status, mensaje}`, o null si está cerrado (o el Cache no responde).
     *
     * @return array|null
     */
    public static function estado(): ?array
    {
        try {
            $estado = Cache::get(self::CLAVE);
        } catch (\Throwable $exception) {
            return null;
        }

        return is_array($estado) ? $estado : null;
    }

    /**
     * Minutos de duración del corte, desde la configuración (nunca con env() directo: con
     * config:cache env() fuera de config/ devuelve el default). Mínimo 1.
     *
     * @return int
     */
    public static function minutos(): int
    {
        $minutos = (int) config('services.openai.embeddings_corte_minutos', self::MINUTOS_POR_DEFECTO);

        return $minutos > 0 ? $minutos : self::MINUTOS_POR_DEFECTO;
    }

    /**
     * Recorta el mensaje de OpenAI y le tapa cualquier clave: OpenAI suele devolver "Incorrect API key
     * provided: sk-proj-…" con un eco parcial de la clave, y eso no tiene que llegar ni al Cache ni al log.
     *
     * @param string $mensaje
     *
     * @return string
     */
    protected static function mensaje_seguro(string $mensaje): string
    {
        // Cualquier cosa con forma de clave de OpenAI (sk-…, sk-proj-…, con o sin asteriscos).
        $mensaje = (string) preg_replace('/sk-[A-Za-z0-9_\-*]+/', 'sk-***', $mensaje);

        // La clave configurada, por si el eco no tiene el formato esperado.
        $clave = trim((string) config('services.openai.api_key'));
        if (strlen($clave) >= 8) {
            $mensaje = str_replace($clave, '***', $mensaje);
        }

        return mb_substr($mensaje, 0, self::LARGO_MAXIMO_MENSAJE);
    }
}
