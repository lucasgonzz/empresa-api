<?php

namespace App\Services\MercadoLibre;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Platform;
use App\Models\PlatformConnector;
use App\Services\MercadoLibre\ErrorHandler;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente base hacia api.mercadolibre.com usando OAuth del `PlatformConnector` del tenant.
 */
class MercadoLibreService
{
    /** @var PlatformConnector Conector ML conectado del usuario */
    protected $platform_connector;

    /** @var int Usuario interno dueño del conector (para notificaciones) */
    protected $user_id;

    /**
     * Si es false, los errores HTTP de make_request no disparan GlobalNotification
     * (el llamador notifica una sola vez al marcar fallida la sync del artículo).
     *
     * @var bool
     */
    protected $notify_on_api_error = true;

    /** @var string URL base de la API pública de Mercado Libre */
    protected string $base_url = 'https://api.mercadolibre.com/';

    /**
     * Resuelve el conector ML del usuario y refresca el token si expiró.
     *
     * @param int|null $user_id Usuario interno; por defecto el autenticado o config USER_ID.
     * @return void
     */
    public function __construct($user_id = null)
    {
        if (is_null($user_id)) {
            $user_id = UserHelper::userId();
        }

        $this->user_id = (int) $user_id;

        $this->platform_connector = PlatformConnector::find_connected_mercado_libre_for_user($this->user_id);

        if (!$this->platform_connector) {
            ErrorHandler::notify_plain_message(
                $this->user_id,
                'Mercado Libre no conectado',
                'Conectá tu cuenta en Integraciones → Conector de plataforma antes de usar esta función.'
            );
            throw new \Exception("No existe conector de Mercado Libre conectado para el usuario {$this->user_id}");
        }

        if (
            $this->platform_connector->expires_at
            && $this->platform_connector->expires_at->isPast()
        ) {
            $this->refresh_token();
        }
    }

    /**
     * Opciones Guzzle/cURL para peticiones a Mercado Libre (ver config/services.php).
     *
     * @return array<string, mixed>
     */
    public static function http_client_options(): array
    {
        $ca_bundle = (string) config('services.mercadolibre.guzzle_ca_bundle', '');
        if ($ca_bundle !== '') {
            return ['verify' => $ca_bundle];
        }

        return [
            'verify' => (bool) config('services.mercadolibre.guzzle_verify', true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function meli_http_options(): array
    {
        return self::http_client_options();
    }

    /**
     * Cliente HTTP Laravel con opciones SSL del entorno (WAMP/local vs producción).
     *
     * @return \Illuminate\Http\Client\PendingRequest
     */
    protected function meli_http()
    {
        return Http::withOptions($this->meli_http_options());
    }

    /**
     * Ejecuta una petición autenticada y devuelve la respuesta HTTP cruda (permite leer headers como x-version).
     *
     * @param string $method Verbo HTTP en minúsculas (get, post, put, ...).
     * @param string $endpoint Path relativo al host de ML.
     * @param array<string, mixed> $data Query o body según el verbo.
     * @param array<string, string> $request_headers Headers adicionales (p. ej. x-version).
     * @return \Illuminate\Http\Client\Response
     */
    protected function meli_authenticated_request(string $method, string $endpoint, array $data = [], array $request_headers = [])
    {
        $url = $this->base_url.ltrim($endpoint, '/');

        Log::info('meli_authenticated_request: ');
        Log::info($url);
        Log::info('params: ');
        Log::info($data);
        Log::info('method: ');
        Log::info($method);

        $http = $this->meli_http()->withToken($this->credencial_del_conector('access_token'));
        if (count($request_headers) > 0) {
            $http = $http->withHeaders($request_headers);
        }

        $response = $http->{$method}($url, $data);

        if ($response->status() === 401) {
            $this->refresh_token();
            $http = $this->meli_http()->withToken($this->credencial_del_conector('access_token'));
            if (count($request_headers) > 0) {
                $http = $http->withHeaders($request_headers);
            }
            $response = $http->{$method}($url, $data);
        }

        return $response;
    }

    /**
     * Ejecuta una petición autenticada contra la API de Mercado Libre.
     *
     * @param string $method Verbo HTTP en minúsculas (get, post, put, ...).
     * @param string $endpoint Path relativo al host de ML.
     * @param array<string, mixed> $data Query o body según el verbo.
     * @param array<string, string> $request_headers Headers adicionales (p. ej. x-version).
     * @return array<string, mixed>|null JSON decodificado; null en 404.
     */
    protected function make_request(string $method, string $endpoint, array $data = [], array $request_headers = [])
    {
        $url = $this->base_url.ltrim($endpoint, '/');
        $response = $this->meli_authenticated_request($method, $endpoint, $data, $request_headers);

        if ($response->status() === 404) {
            Log::warning("Mercado Libre devolvió 404 para {$url}");

            return null;
        }

        if (!$response->successful()) {
            if ($this->notify_on_api_error) {
                ErrorHandler::send_notification($response, $this->user_id);
            }
            throw new \Exception('Mercado Libre API error: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Renueva el access_token usando refresh_token y persiste en `platform_connectors`.
     *
     * @return void
     */
    protected function refresh_token()
    {
        Log::info('refresh_token');

        list($client_id, $client_secret) = $this->resolve_meli_oauth_app_credentials();

        $response = $this->meli_http()->asForm()->post('https://api.mercadolibre.com/oauth/token', [
            'grant_type'    => 'refresh_token',
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'refresh_token' => $this->credencial_del_conector('refresh_token'),
        ]);

        if (!$response->successful()) {
            ErrorHandler::send_notification(
                $response,
                $this->user_id,
                'No se pudo renovar la sesión de Mercado Libre'
            );
            throw new \Exception('No se pudo refrescar el token de Mercado Libre: '.$response->body());
        }

        $data = $response->json();

        // 🔴 NO SE LOGUEA `$data`. Hasta la mision de ABM -> Integraciones acá habia un
        // `Log::info($data)` con el JSON completo del OAuth, o sea el `access_token` y el
        // `refresh_token` en texto plano dentro de `storage/logs/laravel.log`, en CADA refresh.
        // Cifrar la columna y despues dejar el token legible en el log no sirve de nada. Se deja
        // solo lo que sirve para diagnosticar y no es secreto.
        Log::info('refresh_token ok: expires_in='.(isset($data['expires_in']) ? $data['expires_in'] : 's/d').
                  ', refresh rotado='.(!empty($data['refresh_token']) ? 'si' : 'no'));

        $this->platform_connector->access_token = $data['access_token'];
        if (!empty($data['refresh_token'])) {
            $this->platform_connector->refresh_token = $data['refresh_token'];
        }
        if (isset($data['expires_in'])) {
            $this->platform_connector->expires_at = Carbon::now()->addSeconds((int) $data['expires_in']);
        }
        $this->platform_connector->status = PlatformConnector::STATUS_CONECTADO;
        $this->platform_connector->error_message = null;
        $this->platform_connector->save();
    }

    /**
     * Obtiene client_id y client_secret de la plataforma asociada al conector.
     *
     * @return array{0: string|null, 1: string|null}
     */
    protected function resolve_meli_oauth_app_credentials(): array
    {
        $platform = $this->platform_connector->platform;

        if ($platform && $platform->client_id) {
            // `client_secret` tiene cast `encrypted` desde la mision de ABM -> Integraciones:
            // leerlo puede tirar. Si no se puede descifrar no se propaga la excepcion, se cae al
            // .env — que es la rama que este metodo ya tenia para "la plataforma no tiene las
            // credenciales cargadas".
            try {
                $client_secret = $platform->client_secret;
            } catch (\Throwable $e) {
                Log::error('MercadoLibreService: no se pudo descifrar el client_secret de la plataforma '.$platform->slug.': '.$e->getMessage());
                $client_secret = null;
            }

            if ($client_secret) {
                return [$platform->client_id, $client_secret];
            }
        }

        return [env('MERCADO_LIBRE_CLIENT_ID'), env('MERCADO_LIBRE_CLIENT_SECRET')];
    }

    /**
     * Lee un atributo cifrado del conector traduciendo un fallo de descifrado a un error que se
     * entienda.
     *
     * Desde la mision de ABM -> Integraciones, `access_token` y `refresh_token` tienen cast
     * `encrypted`, asi que leerlos puede tirar `DecryptException` si la fila quedo en texto
     * plano (la migracion `encrypt_platform_connector_tokens` no corrio) o si esta cifrada con
     * otra APP_KEY. Sin esto, el sintoma era un 500 pelado en cualquier llamada a Mercado Libre,
     * sin ninguna pista de que el problema era el descifrado.
     *
     * @param string $atributo Nombre del atributo cifrado (`access_token` / `refresh_token`).
     * @return string|null
     */
    protected function credencial_del_conector(string $atributo)
    {
        try {
            return $this->platform_connector->{$atributo};
        } catch (\Throwable $e) {
            Log::error(
                'MercadoLibreService: no se pudo descifrar '.$atributo.' del platform_connector '.
                $this->platform_connector->id.': '.$e->getMessage()
            );

            throw new \RuntimeException(
                'No se pudo leer la credencial de Mercado Libre del conector '.$this->platform_connector->id.'. '.
                'Puede estar guardada sin cifrar (falta correr la migracion '.
                'encrypt_platform_connector_tokens) o cifrada con otra APP_KEY. Reconectar la '.
                'cuenta desde ABM -> Integraciones la vuelve a dejar bien.'
            );
        }
    }

    /**
     * Id del vendedor en Mercado Libre (`platform_user_id` del conector).
     *
     * @return string
     */
    protected function meli_seller_id(): string
    {
        return (string) $this->platform_connector->platform_user_id;
    }

    /**
     * Notifica al usuario un error de excepción (salvo duplicados de API ML).
     *
     * @param \Throwable $exception Excepción capturada.
     * @param string $message_text Título del modal.
     * @return void
     */
    protected function notify_meli_exception(\Throwable $exception, string $message_text = 'Error en Mercado Libre'): void
    {
        ErrorHandler::notify_exception($this->user_id, $exception, $message_text, true);
    }
}
