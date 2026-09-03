<?php

namespace App\Services\PlatformConnector;

use App\Models\OauthState;
use App\Models\Platform;
use App\Models\PlatformConnector;
use App\Services\MercadoLibre\MercadoLibreService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Completa el flujo OAuth para un `PlatformConnector` tras el callback HTTP.
 *
 * Responsabilidad:
 * - Intercambiar `code` por tokens según la plataforma.
 * - Actualizar el conector (`status`, tokens, `platform_user_id`).
 */
class PlatformConnectorOAuthService
{
    /**
     * Procesa el callback de Mercado Libre (query `code`, `state`).
     *
     * @param Request $request Request HTTP entrante.
     * @return \Illuminate\Http\Response
     */
    public function handle_mercado_libre_callback(Request $request)
    {
        return $this->handle_callback_internal(
            $request,
            Platform::SLUG_MERCADO_LIBRE,
            function (PlatformConnector $connector, string $code) {
                $redirect_uri = env('MERCADO_LIBRE_REDIRECT_URI');
                if (empty($redirect_uri)) {
                    throw new \RuntimeException('Falta MERCADO_LIBRE_REDIRECT_URI en configuración.');
                }
                $platform = $connector->platform;
                if (!$platform || empty($platform->client_id) || empty($platform->client_secret)) {
                    throw new \RuntimeException('La plataforma Mercado Libre no tiene client_id o client_secret en catálogo.');
                }
                $response = Http::withOptions(MercadoLibreService::http_client_options())
                    ->asForm()
                    ->post('https://api.mercadolibre.com/oauth/token', [
                    'grant_type'    => 'authorization_code',
                    'client_id'     => $platform->client_id,
                    'client_secret' => $platform->client_secret,
                    'code'          => $code,
                    'redirect_uri'  => $redirect_uri,
                ]);
                if (!$response->successful()) {
                    throw new \RuntimeException('Mercado Libre rechazó el intercambio de token: ' . $response->body());
                }
                $data = $response->json();
                $connector->auth_code = $code;
                $connector->access_token = $data['access_token'];
                $connector->refresh_token = $data['refresh_token'] ?? null;
                $connector->expires_at = Carbon::now()->addSeconds((int) ($data['expires_in'] ?? 0));
                $connector->platform_user_id = isset($data['user_id']) ? (string) $data['user_id'] : null;
                $connector->status = PlatformConnector::STATUS_CONECTADO;
                $connector->error_message = null;
                $connector->save();
            }
        );
    }

    /**
     * Procesa el callback de Tienda Nube (query `code`, `state`).
     *
     * @param Request $request Request HTTP entrante.
     * @return \Illuminate\Http\Response
     */
    public function handle_tienda_nube_callback(Request $request)
    {
        return $this->handle_callback_internal(
            $request,
            Platform::SLUG_TIENDA_NUBE,
            function (PlatformConnector $connector, string $code) {
                $platform = $connector->platform;
                if (!$platform || empty($platform->client_id) || empty($platform->client_secret)) {
                    throw new \RuntimeException('La plataforma Tienda Nube no tiene client_id o client_secret en catálogo.');
                }
                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ])->post('https://www.tiendanube.com/apps/authorize/token', [
                    'client_id'     => $platform->client_id,
                    'client_secret' => $platform->client_secret,
                    'grant_type'    => 'authorization_code',
                    'code'          => $code,
                ]);
                if (!$response->successful()) {
                    throw new \RuntimeException('Tienda Nube rechazó el intercambio de token: ' . $response->body());
                }
                $data = $response->json();
                $connector->auth_code = $code;
                $connector->access_token = $data['access_token'] ?? null;
                $connector->refresh_token = null;
                $connector->expires_at = null;
                $connector->platform_user_id = isset($data['user_id']) ? (string) $data['user_id'] : null;
                $connector->status = PlatformConnector::STATUS_CONECTADO;
                $connector->error_message = null;
                $connector->save();
            }
        );
    }

    /**
     * Lógica común de callback: valida parámetros, localiza conector y ejecuta intercambio.
     *
     * @param Request $request Request HTTP.
     * @param string $expected_platform_slug Slug esperado en `platforms.slug`.
     * @param callable $exchange Callable(PlatformConnector $connector, string $code): void
     * @return \Illuminate\Http\Response
     */
    protected function handle_callback_internal(Request $request, string $expected_platform_slug, callable $exchange)
    {
        $code = $request->query('code');
        $state = $request->query('state');
        if (!$code || !$state) {
            return response('Faltan parámetros code o state en la URL de retorno.', 400);
        }
        $connector = $this->resolver_conector_del_state((string) $state);
        if (!$connector) {
            return response('Conector no encontrado.', 404);
        }
        if (!$connector->platform || $connector->platform->slug !== $expected_platform_slug) {
            return response('La plataforma del conector no coincide con esta URL de callback.', 400);
        }

        // `client_secret` tiene cast `encrypted` desde la mision de ABM -> Integraciones, asi que
        // LEERLO PUEDE TIRAR. Esta lectura vive FUERA del try de mas abajo, asi que sin esta
        // guarda una fila con el secreto en texto plano (APP_KEY rotada, o una instalacion donde
        // la migracion de cifrado no corrio) devolvia un 500 pelado en el callback de ML/TN, sin
        // ninguna pista de que el problema era el descifrado.
        try {
            $client_secret = $connector->platform->client_secret;
        } catch (\Throwable $e) {
            Log::error('PlatformConnector OAuth: no se pudo descifrar el client_secret de la plataforma '.$connector->platform->slug.': '.$e->getMessage());

            return response(
                'No se pudo leer el client_secret de la plataforma. Puede estar guardado sin cifrar '.
                '(falta correr la migracion encrypt_platform_connector_tokens) o cifrado con otra APP_KEY.',
                500
            );
        }

        if (empty($connector->platform->client_id) || empty($client_secret)) {
            return response('La plataforma no tiene client_id o client_secret configurados en el catálogo.', 400);
        }
        try {
            $exchange($connector, $code);
        } catch (\Throwable $e) {
            Log::error('PlatformConnector OAuth error: ' . $e->getMessage());
            $connector->status = PlatformConnector::STATUS_ERROR;
            $connector->error_message = $e->getMessage();
            $connector->save();

            return response('Error al completar la conexión: ' . $e->getMessage(), 500);
        }

        return response(
            '<html><body><h1>Conexión exitosa</h1><p>Podés cerrar esta ventana y volver a Comercio City.</p></body></html>',
            200,
            ['Content-Type' => 'text/html; charset=UTF-8']
        );
    }

    /**
     * Resuelve el conector a partir del `state` del callback aceptando LOS DOS FORMATOS.
     *
     * 1. State aleatorio de `oauth_states` (el bueno, el que usa Mercado Pago desde el prompt
     *    598 y al que van a migrar ML y TN): se busca la fila, se valida que no haya vencido ni
     *    se haya usado, y se consume. El conector sale de `platform_connector_id`, no de la URL.
     * 2. Id del conector como state (el formato viejo, el unico que ML y TN mandan HOY en
     *    produccion): `PlatformConnector::find((int) $state)`.
     *
     * Los dos tienen que seguir andando: cuando esta mision se despliegue va a haber ventanas de
     * autorizacion de ML/TN ya abiertas en el navegador de algun comercio, con el id del conector
     * pegado en la URL de Mercado Libre. Si el callback dejara de aceptarlo, esas conexiones
     * fallarian sin que nadie entienda por que.
     *
     * 🔴 LOS DOS FORMATOS SE DISTINGUEN CON `ctype_digit()`, NO CON EL CAST A ENTERO. Este
     * metodo tenia acá un comentario que afirmaba que `(int)` sobre un state aleatorio da
     * siempre 0 "asi que no hay ambiguedad". Es FALSO y esta medido: `Str::random()` devuelve
     * alfanumerico, asi que un state que empieza con digitos castea a ese numero — sobre 10.000
     * states generados con el binario 7.4, **1429 (14%) dan un entero distinto de cero**, y 3,
     * 4, 5 y 9 son ids reales de `platform_connectors`. Sin la guarda, un replay de un state
     * aleatorio ya consumido (que `consume()` rechaza, como corresponde) se caia igual en el
     * `find()` del formato viejo y conectaba el conector de OTRO comercio.
     *
     * @param string $state Valor recibido en el query `state`.
     * @return PlatformConnector|null
     */
    protected function resolver_conector_del_state(string $state)
    {
        $oauth_state = OauthState::consume($state);

        if ($oauth_state && !empty($oauth_state->platform_connector_id)) {
            return PlatformConnector::with('platform')->find((int) $oauth_state->platform_connector_id);
        }

        // Formato viejo: el state ES el id del conector, o sea SOLO digitos. Cualquier otra cosa
        // -incluido un state aleatorio ya consumido- no se intenta resolver por id.
        if (!ctype_digit($state)) {
            return null;
        }

        return PlatformConnector::with('platform')->find((int) $state);
    }
}
