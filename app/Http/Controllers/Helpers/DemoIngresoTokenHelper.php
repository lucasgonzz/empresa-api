<?php

namespace App\Http\Controllers\Helpers;

use App\Models\DemoIngresoToken;
use Carbon\Carbon;

/**
 * Persiste y resuelve el token de ingreso con sesión iniciada que emite
 * admin-api para que un lead entre directo a una demo sin pantalla de login.
 *
 * A diferencia de VersionSessionTransferHelper (token de un solo uso), este
 * token NO se borra al usarse: vale durante toda la ventana del turno.
 */
class DemoIngresoTokenHelper
{
    /**
     * Guarda el token de ingreso emitido por admin-api para un usuario demo.
     *
     * Se llama al final de DemoSetupHelper::run(), después del migrate:fresh
     * y de crear el usuario (si se llamara antes, el migrate lo borraría).
     *
     * @param string $plain_token Token en claro recibido en el payload de admin-api.
     * @param int $user_id Id del usuario demo (config('app.USER_ID')).
     * @param string|null $expires_at Fecha de expiración recibida en el payload (string parseable por Carbon).
     * @return DemoIngresoToken
     */
    public static function guardar($plain_token, $user_id, $expires_at)
    {
        /** Solo se persiste el hash: el token en claro nunca se guarda de este lado. */
        $token_hash = hash('sha256', trim((string) $plain_token));

        /**
         * Fecha límite de vigencia. Si no vino o no se puede parsear, se usa
         * un fallback de 4 horas: nunca se guarda `expires_at` en null.
         */
        try {
            $parsed_expires_at = !empty($expires_at) ? Carbon::parse($expires_at) : null;
        } catch (\Exception $e) {
            $parsed_expires_at = null;
        }

        if (!$parsed_expires_at) {
            $parsed_expires_at = Carbon::now()->addHours(4);
        }

        /**
         * Se borran los tokens previos de este usuario antes de crear el nuevo.
         * En la práctica no debería haber ninguno porque el migrate:fresh del
         * setup vació la tabla, pero se deja idempotente por si se llama a
         * este método fuera del flujo de setup (regeneración manual, etc.).
         */
        DemoIngresoToken::where('user_id', $user_id)->delete();

        return DemoIngresoToken::create([
            'token_hash' => $token_hash,
            'user_id' => $user_id,
            'expires_at' => $parsed_expires_at,
            'revoked_at' => null,
        ]);
    }

    /**
     * Resuelve el token de ingreso vigente para el token en claro recibido.
     *
     * Vigente = el hash coincide, `expires_at` es posterior a ahora y
     * `revoked_at` está vacío. En local, las dos últimas condiciones se
     * ignoran (ver `bypass_vigencia_local()`): el hash siempre tiene que
     * coincidir con un token que de verdad se emitió.
     *
     * @param string $plain_token Token en claro recibido desde el request.
     * @return DemoIngresoToken|null Registro vigente o null si no existe/expiró/fue revocado.
     */
    public static function resolver($plain_token)
    {
        if (!$plain_token || !is_string($plain_token)) {
            return null;
        }

        /** Hash del token entrante para comparar contra la fila guardada. */
        $token_hash = hash('sha256', trim($plain_token));

        $query = DemoIngresoToken::where('token_hash', $token_hash);

        if (!self::bypass_vigencia_local()) {
            $query->where('expires_at', '>', Carbon::now())->whereNull('revoked_at');
        }

        return $query->first();
    }

    /**
     * true solo en entorno local: ahí el vencimiento y la revocación del token de ingreso
     * dejan de bloquear, para poder testear la demo sin pelearse con el horario del turno.
     *
     * Mismo criterio que `modo_prueba()` en `admin-api/DemoExperienciaController`: la
     * variable de entorno, nada más — así no se puede filtrar por accidente a producción,
     * ni depende de una carpeta o un host particular.
     *
     * 🔴 Esto NUNCA salta la EXISTENCIA del registro. Un token que nunca se emitió (o
     * inventado) sigue sin resolver nada, ni siquiera en local — lo único que se ignora es
     * CUÁNDO vence y si fue revocado. Lo consultan `resolver()` acá arriba y
     * `DemoSessionVigente::handle()`: una sola definición de la regla, no dos copias que
     * puedan divergir (ver el informe `20260817-link-ingreso-demo-sin-esquema.md`: cuando
     * una regla aparece escrita dos veces, hay que buscar la tercera que no la tiene).
     *
     * @return bool
     */
    public static function bypass_vigencia_local(): bool
    {
        return config('app.env') === 'local';
    }

    /**
     * Revoca manualmente un token de ingreso (por ejemplo, desde el admin).
     *
     * @param string $plain_token Token en claro a revocar.
     * @return bool true si se encontró y revocó un token; false si no existía.
     */
    public static function revocar($plain_token)
    {
        if (!$plain_token || !is_string($plain_token)) {
            return false;
        }

        $token_hash = hash('sha256', trim($plain_token));

        /** Se revoca aunque ya esté expirado, para dejar el estado explícito. */
        $token = DemoIngresoToken::where('token_hash', $token_hash)->first();

        if (!$token) {
            return false;
        }

        $token->revoked_at = Carbon::now();
        $token->save();

        return true;
    }
}
