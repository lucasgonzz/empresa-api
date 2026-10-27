<?php

namespace App\Services\MercadoLibre;

use App\Models\Platform;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Credenciales de la APLICACIÓN de ComercioCity en Mercado Libre y datos fijos del OAuth
 * (misión mercado-libre-integracion, 27/10/2026).
 *
 * Antes cada punta las resolvía por su lado: el callback exigía que estuvieran en la fila
 * `mercado_libre` de `platforms` (si no, 400), el refresh caía al `.env` con `env()` suelto (que
 * con config:cache devuelve null) y la URL de autorización salía vacía si `platforms` no tenía
 * `client_id`. Acá hay UNA definición, que usan el connect, el callback, el refresh y el token de
 * app.
 *
 * Orden: la fila `mercado_libre` de `platforms` si tiene `client_id` y un `client_secret` que se
 * pueda descifrar; si no, `config('services.mercadolibre.client_id'/'client_secret')` (que leen
 * `MERCADO_LIBRE_CLIENT_ID` / `MERCADO_LIBRE_CLIENT_SECRET`). Nunca se mezcla un id de un lado con
 * un secret del otro: son de la misma app o no sirven.
 *
 * 🔴 El secret no se loguea ni se devuelve en ninguna respuesta.
 */
class MercadoLibreAppCredentials
{
    /** URL de autorización de Mercado Libre Argentina (la abre el comercio en su navegador). */
    const AUTHORIZATION_URL = 'https://auth.mercadolibre.com.ar/authorization';

    /** Endpoint de canje / refresh de tokens. */
    const TOKEN_URL = 'https://api.mercadolibre.com/oauth/token';

    /** Clave de caché del token de app (client_credentials). */
    const CACHE_TOKEN_DE_APP = 'meli_token_de_app';

    /** Clave de caché que marca que el último pedido del token de app falló (evita reintentar en cada llamada). */
    const CACHE_TOKEN_DE_APP_FALLO = 'meli_token_de_app_fallo';

    /**
     * Fila `mercado_libre` del catálogo de plataformas, o null si la instalación no la tiene.
     *
     * @return Platform|null
     */
    public static function platform()
    {
        return Platform::where('slug', Platform::SLUG_MERCADO_LIBRE)->first();
    }

    /**
     * Par `[client_id, client_secret]` de la app de ComercioCity en Mercado Libre.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public static function credenciales()
    {
        $platform = self::platform();

        if ($platform && !empty($platform->client_id)) {
            // `client_secret` tiene cast `encrypted`: leerlo puede tirar si la fila quedó en texto
            // plano o cifrada con otra APP_KEY. En ese caso se cae al .env, nunca un 500.
            try {
                $client_secret = $platform->client_secret;
            } catch (\Throwable $e) {
                Log::error('MercadoLibreAppCredentials: no se pudo descifrar el client_secret de la plataforma mercado_libre: '.$e->getMessage());
                $client_secret = null;
            }

            if (!empty($client_secret)) {
                return [(string) $platform->client_id, (string) $client_secret];
            }
        }

        $client_id = config('services.mercadolibre.client_id');
        $client_secret = config('services.mercadolibre.client_secret');

        return [
            empty($client_id) ? null : (string) $client_id,
            empty($client_secret) ? null : (string) $client_secret,
        ];
    }

    /**
     * `client_id` de la app (o null si no está configurada).
     *
     * @return string|null
     */
    public static function client_id()
    {
        $credenciales = self::credenciales();

        return $credenciales[0];
    }

    /**
     * `client_secret` de la app (o null si no está configurado).
     *
     * @return string|null
     */
    public static function client_secret()
    {
        $credenciales = self::credenciales();

        return $credenciales[1];
    }

    /**
     * True si la app tiene id y secret: sin los dos no se puede conectar ni refrescar.
     *
     * @return bool
     */
    public static function configurada()
    {
        $credenciales = self::credenciales();

        return !empty($credenciales[0]) && !empty($credenciales[1]);
    }

    /**
     * True si el OAuth va por la URL central del admin (el relay).
     *
     * @return bool
     */
    public static function usa_relay()
    {
        return !empty(config('services.mercadolibre.oauth_relay_url'));
    }

    /**
     * `redirect_uri` para una conexión NUEVA (autorización y canje de un state de `oauth_states`):
     * la del relay si está configurada; si no, la callback propia de este backend.
     *
     * @return string|null
     */
    public static function redirect_uri_para_conectar()
    {
        if (self::usa_relay()) {
            return (string) config('services.mercadolibre.oauth_relay_url');
        }

        return self::redirect_uri_legacy();
    }

    /**
     * `redirect_uri` propia de este backend (`MERCADO_LIBRE_REDIRECT_URI`). Es la que hay que usar
     * para canjear un `state` del formato viejo (el id numérico del conector), que salió de una URL
     * de autorización armada con ella.
     *
     * @return string|null
     */
    public static function redirect_uri_legacy()
    {
        $redirect_uri = config('services.mercadolibre.redirect_uri');

        return empty($redirect_uri) ? null : (string) $redirect_uri;
    }

    /**
     * Token de APLICACIÓN (grant `client_credentials`) para las llamadas que no son de un vendedor
     * (catálogo de categorías y atributos) cuando el comercio todavía no conectó su cuenta.
     *
     * Se cachea hasta `expires_in - 300` segundos. Si ML lo niega, se recuerda el fallo 60
     * segundos para no golpear `oauth/token` en cada llamada, y se devuelve null (el llamador sigue
     * sin token). Nunca se loguea el token.
     *
     * @return string|null
     */
    public static function token_de_app()
    {
        try {
            $cacheado = Cache::get(self::CACHE_TOKEN_DE_APP);
            if (!empty($cacheado)) {
                return (string) $cacheado;
            }

            if (Cache::get(self::CACHE_TOKEN_DE_APP_FALLO)) {
                return null;
            }
        } catch (\Throwable $e) {
            // Sin caché se pide igual: el token de app es un lujo, no una guarda.
        }

        $credenciales = self::credenciales();
        if (empty($credenciales[0]) || empty($credenciales[1])) {
            return null;
        }

        try {
            $response = Http::withOptions(MercadoLibreService::http_client_options())
                ->asForm()
                ->post(self::TOKEN_URL, [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $credenciales[0],
                    'client_secret' => $credenciales[1],
                ]);
        } catch (\Throwable $e) {
            Log::warning('MercadoLibreAppCredentials: no se pudo pedir el token de app: '.$e->getMessage());
            self::recordar_fallo_del_token_de_app();

            return null;
        }

        $data = $response->successful() ? $response->json() : null;

        if (!is_array($data) || empty($data['access_token'])) {
            Log::warning('MercadoLibreAppCredentials: Mercado Libre negó el token de app. Status: '.$response->status());
            self::recordar_fallo_del_token_de_app();

            return null;
        }

        $segundos = isset($data['expires_in']) ? ((int) $data['expires_in'] - 300) : 3600;
        if ($segundos < 60) {
            $segundos = 60;
        }

        try {
            Cache::put(self::CACHE_TOKEN_DE_APP, (string) $data['access_token'], $segundos);
        } catch (\Throwable $e) {
            // Sin caché, el próximo pedido vuelve a canjear. No es un error.
        }

        return (string) $data['access_token'];
    }

    /**
     * Marca por 60 segundos que el token de app no se pudo conseguir.
     *
     * @return void
     */
    protected static function recordar_fallo_del_token_de_app()
    {
        try {
            Cache::put(self::CACHE_TOKEN_DE_APP_FALLO, true, 60);
        } catch (\Throwable $e) {
            // Nada: sin caché simplemente se reintenta la próxima vez.
        }
    }
}
