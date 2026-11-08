<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Cookie;

class VinculacionMaestra
{
    public const COOKIE = '__Host-asistente_maestro';

    private const SESION = 'vinculacion_asistente_maestro';
    private const SEGUNDOS_CODIGO = 600;
    private const SEGUNDOS_COOKIE = 30 * 86400;

    public static function origen_valido(Request $request): bool
    {
        $origen = rtrim(trim((string) config('acceso_soporte.asistente_maestro_origen')), '/');

        return $request->isSecure() && strpos($origen, 'https://') === 0 && $request->header('Origin') === $origen;
    }

    public static function disponible(): bool
    {
        $hash = self::hash_maestro();

        return password_get_info($hash)['algoName'] !== 'unknown'
            && (string) config('app.key') !== ''
            && (string) config('app.url') !== '';
    }

    public static function iniciar(Request $request): ?string
    {
        $pendiente = self::pendiente($request);
        if ($pendiente && !self::cache()->has($pendiente['clave'] . ':canjeado')) {
            return $pendiente['codigo'];
        }

        $codigo = implode('-', str_split(strtoupper(bin2hex(random_bytes(6))), 4));
        $clave = self::clave_codigo($codigo);
        $entrada = [
            'sesion' => self::firma('sesion:' . $request->session()->getId()),
            'vence' => now()->timestamp + self::SEGUNDOS_CODIGO,
        ];

        if (!self::cache()->add($clave, $entrada, self::SEGUNDOS_CODIGO)) {
            return null;
        }

        $request->session()->put(self::SESION, $codigo);

        return $codigo;
    }

    public static function aprobar(string $codigo, int $dueno_id): bool
    {
        $codigo = strtoupper(trim($codigo));
        if (!self::disponible() || !preg_match('/^[0-9A-F]{4}(?:-[0-9A-F]{4}){2}$/D', $codigo)) {
            return false;
        }

        $duenos = User::whereNull('owner_id')->limit(2)->pluck('id');
        if ($dueno_id <= 0 || $duenos->count() !== 1 || (int) $duenos->first() !== $dueno_id) {
            return false;
        }

        $clave = self::clave_codigo($codigo);
        $entrada = self::cache()->get($clave);
        $restan = (int) ($entrada['vence'] ?? 0) - now()->timestamp;

        return $restan > 0 && self::cache()->add($clave . ':aprobado', $dueno_id, $restan);
    }

    public static function canjear(Request $request): array
    {
        $pendiente = self::pendiente($request);
        if (!$pendiente || self::cache()->has($pendiente['clave'] . ':canjeado')) {
            return [410, null];
        }

        $dueno_id = self::cache()->get($pendiente['clave'] . ':aprobado');
        if (!$dueno_id) {
            return [409, null];
        }

        $restan = $pendiente['vence'] - now()->timestamp;
        if ($restan <= 0 || !self::cache()->add($pendiente['clave'] . ':canjeado', true, $restan)) {
            return [410, null];
        }

        $request->session()->forget(self::SESION);
        $vence = now()->timestamp + self::SEGUNDOS_COOKIE;
        $valor = $vence . '.' . self::firma('cookie:' . $dueno_id . ':' . $vence);

        // EncryptCookies cifra el valor. Symfony evita que CookieJar herede SESSION_DOMAIN.
        return [200, new Cookie(self::COOKIE, $valor, $vence, '/', null, true, true, false, 'lax')];
    }

    public static function cookie_vigente(Request $request, int $dueno_id): bool
    {
        $valor = $request->cookie(self::COOKIE);
        if (!is_string($valor) || !preg_match('/^(\d{10})\.([0-9a-f]{64})$/D', $valor, $partes)) {
            return false;
        }

        return (int) $partes[1] > now()->timestamp
            && hash_equals(self::firma('cookie:' . $dueno_id . ':' . $partes[1]), $partes[2]);
    }

    private static function pendiente(Request $request): ?array
    {
        $codigo = $request->session()->get(self::SESION);
        if (!is_string($codigo)) {
            return null;
        }

        $clave = self::clave_codigo($codigo);
        $entrada = self::cache()->get($clave);
        if (!$entrada || $entrada['vence'] <= now()->timestamp
            || !hash_equals($entrada['sesion'], self::firma('sesion:' . $request->session()->getId()))) {
            return null;
        }

        return ['codigo' => $codigo, 'clave' => $clave, 'vence' => $entrada['vence']];
    }

    private static function clave_codigo(string $codigo): string
    {
        return 'asistente-maestro:vinculo:' . self::firma('codigo:' . $codigo);
    }

    private static function firma(string $valor): string
    {
        return hash_hmac('sha256', config('app.url') . ':' . self::hash_maestro() . ':' . $valor, (string) config('app.key'));
    }

    private static function hash_maestro(): string
    {
        return (string) config('acceso_soporte.asistente_maestro_password_hash', '');
    }

    private static function cache()
    {
        // El navegador y Artisan corren en procesos distintos. El store local persiste incluso
        // si CACHE_DRIVER es array, y mantiene el vínculo en el mismo frente que guarda la sesión.
        return Cache::store('file');
    }
}
