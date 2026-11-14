<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Helpers\PruebaHelper;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

class VerifyCsrfToken extends Middleware
{
    /** Prefijo del nombre de la cookie CSRF propia de una prueba (ver `nombre_de_la_cookie_propia`). */
    const PREFIJO_COOKIE_PROPIA = 'XSRF-';

    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        'login',
        'api/vender/*',
        'api/*',
    ];

    /** /login conserva la excepción histórica. Elevarlo por cookie exige el token de la sesión. */
    public function token_de_sesion_valido(Request $request): bool
    {
        return $request->hasSession() && $this->tokensMatch($request);
    }

    /**
     * El nombre de la cookie CSRF propia del sistema para el host de la API (misión prueba-e2e-campana-imperio,
     * ronda 4, X1, 14/11/2026): `XSRF-` + el host en minúsculas, sin puerto, con todo lo que no es letra o
     * número cambiado por `_`. `api-ferreteria-la-esquin.comerciocity.com` →
     * `XSRF-api_ferreteria_la_esquin_comerciocity_com`.
     *
     * 🔴 Contrato con empresa-spa (`src/utils/cookie_xsrf_propia.js`, `nombre_cookie_xsrf_propia`), que calcula
     * el mismo nombre con el hostname de `VUE_APP_API_URL`. Si cambia de un lado, cambia del otro.
     *
     * @param string $host El host del request (`Request::getHost()`, ya sin puerto y en minúsculas).
     * @return string
     */
    public static function nombre_de_la_cookie_propia($host)
    {
        return self::PREFIJO_COOKIE_PROPIA . preg_replace('/[^A-Za-z0-9]/', '_', strtolower((string) $host));
    }

    /**
     * ¿Esta INSTANCIA es una prueba gratuita vigente? Decide por la instancia, nunca por el usuario de la sesión:
     * `sanctum/csrf-cookie` y el login responden sin usuario, y si la cookie alternara entre `XSRF-TOKEN` y la
     * propia según haya sesión, el SPA mandaría un token viejo.
     *
     * - `PruebaHelper::puede_haber_prueba()`: `FOR_USER=prueba` en la config, sin tocar la base. En los clientes
     *   reales da false y no se paga ninguna consulta.
     * - `PruebaHelper::en_periodo_vigente()` del dueño de la instancia (`config('app.USER_ID')`, el mismo que
     *   usa el demo setup; sin él, `PruebaHelper::dueno()`): su `prueba_config` existe y `users.expired_at`
     *   está en el futuro. Vencida o contratada (`expired_at` nulo), vuelve a `XSRF-TOKEN`.
     *
     * Costo, solo en una instancia de prueba: dos consultas por id (`prueba_config` por `owner_id` y
     * `users.expired_at` por clave primaria), memoizadas por proceso en `PruebaHelper` (sus otros enganches del
     * mismo request ya las suelen haber hecho).
     *
     * @return bool
     */
    public static function instancia_en_prueba_vigente()
    {
        if (!PruebaHelper::puede_haber_prueba()) {
            return false;
        }

        try {
            $owner_id = (int) config('app.USER_ID');
            if ($owner_id <= 0) {
                $dueno = PruebaHelper::dueno();
                $owner_id = is_null($dueno) ? 0 : (int) $dueno->id;
            }

            return $owner_id > 0 && PruebaHelper::en_periodo_vigente($owner_id);
        } catch (\Throwable $e) {
            // Una base caída no tiene que cambiar el nombre de la cookie ni romper la respuesta.
            return false;
        }
    }

    /**
     * Agrega el token CSRF a la respuesta (misión prueba-e2e-campana-imperio, ronda 4, X1).
     *
     * Por qué: en una prueba el sistema y la tienda del negocio viven bajo el mismo dominio (`.comerciocity.com`)
     * y los dos escribían `XSRF-TOKEN`. Con el sistema abierto en otra pestaña, la tienda mandaba el token del
     * sistema y sus `POST /api/buyer` y `PUT /api/carts` daban 419. En una prueba vigente el token viaja en
     * una cookie con nombre propio (`nombre_de_la_cookie_propia`) y `XSRF-TOKEN` no se escribe: la de la tienda
     * queda intacta. La verificación no cambia (el framework lee el header `X-XSRF-TOKEN` sin mirar de qué
     * cookie salió).
     *
     * Atributos: los mismos que pone el framework a `XSRF-TOKEN` (valor = token de la sesión, vencimiento de la
     * sesión, `path`, `domain`, `secure` y `same_site` de `config('session')`, NO httpOnly: el SPA la lee).
     * La encripta `EncryptCookies` como a cualquier otra.
     *
     * Fuera de una prueba vigente: si el request trae la cookie propia (la prueba venció o contrató), se la
     * vence (mismo nombre, dominio y path), porque el SPA la prefiere mientras exista y un token viejo daría
     * 419 en `logout` y en `broadcasting/auth`. Sin la cookie propia en el request la respuesta es exactamente
     * la de siempre (`parent::addCookieToResponse`).
     *
     * @param \Illuminate\Http\Request $request
     * @param \Symfony\Component\HttpFoundation\Response $response
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function addCookieToResponse($request, $response)
    {
        $nombre = self::nombre_de_la_cookie_propia($request->getHost());

        if (!self::instancia_en_prueba_vigente()) {
            $response = parent::addCookieToResponse($request, $response);

            if ($request->cookies->has($nombre)) {
                $config = config('session');
                $response->headers->setCookie(new Cookie(
                    $nombre, null, 1, $config['path'], $config['domain'], $config['secure'], false, false, $config['same_site'] ?? null
                ));
            }

            return $response;
        }

        $config = config('session');

        if ($response instanceof Responsable) {
            $response = $response->toResponse($request);
        }

        $response->headers->setCookie(
            new Cookie(
                $nombre, $request->session()->token(), $this->availableAt(60 * $config['lifetime']),
                $config['path'], $config['domain'], $config['secure'], false, false, $config['same_site'] ?? null
            )
        );

        return $response;
    }
}
