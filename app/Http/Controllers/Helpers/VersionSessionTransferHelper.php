<?php

namespace App\Http\Controllers\Helpers;

use App\Models\VersionSessionTransfer;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Crea y consume tokens de un solo uso para replicar el login en otra versión (otro host de API).
 */
class VersionSessionTransferHelper
{
    /**
     * Minutos de validez del token en base de datos.
     *
     * @var int
     */
    protected static $ttl_minutes = 5;

    /**
     * Genera un token de transferencia para el usuario autenticado.
     *
     * @param int $user_id Id del usuario con sesión válida en la versión origen.
     * @param bool $master_login_bypass Si la sesión de origen tenía el bypass de login maestro
     *   activo (`master_login_bypass_user_last_activity`). Se propaga para que la API destino
     *   reproduzca el mismo estado en vez de arrancar como una sesión cualquiera del usuario.
     * @param bool $skip_offline_articles_sync Si la sesión de origen omitía la descarga de
     *   artículos offline (solo login maestro básico, ver AuthController::login()).
     * @return string Token en claro para enviar al SPA destino vía query string.
     */
    public static function create_for_user($user_id, $master_login_bypass = false, $skip_offline_articles_sync = false)
    {
        /** Token aleatorio que viaja en la URL del SPA destino. */
        $plain_token = Str::random(64);

        /** Solo se persiste el hash para evitar fugas si se filtra la base. */
        $token_hash = hash('sha256', $plain_token);

        /** Fecha límite de uso del token. */
        $expires_at = Carbon::now()->addMinutes(self::$ttl_minutes);

        VersionSessionTransfer::create([
            'token_hash' => $token_hash,
            'user_id' => $user_id,
            'master_login_bypass' => (bool) $master_login_bypass,
            'skip_offline_articles_sync' => (bool) $skip_offline_articles_sync,
            'expires_at' => $expires_at,
        ]);

        Log::info('Version session transfer creado para user_id: '.$user_id);

        return $plain_token;
    }

    /**
     * Valida el token, devuelve sus datos y elimina el registro (uso único).
     *
     * @param string $plain_token Token recibido desde el query string del SPA.
     * @return array|null Array con `user_id`, `master_login_bypass` y
     *   `skip_offline_articles_sync`, o null si el token no es válido.
     */
    public static function consume($plain_token)
    {
        if (!$plain_token || !is_string($plain_token)) {
            return null;
        }

        /** Hash del token entrante para comparar con la fila guardada. */
        $token_hash = hash('sha256', trim($plain_token));

        /** Busca un token vigente y aún no vencido. */
        $transfer = VersionSessionTransfer::where('token_hash', $token_hash)
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if (!$transfer) {
            Log::info('Version session transfer no encontrado o expirado.');
            return null;
        }

        $resultado = [
            'user_id' => (int) $transfer->user_id,
            'master_login_bypass' => (bool) $transfer->master_login_bypass,
            'skip_offline_articles_sync' => (bool) $transfer->skip_offline_articles_sync,
        ];

        $transfer->delete();

        Log::info('Version session transfer consumido para user_id: '.$resultado['user_id']);

        return $resultado;
    }
}
