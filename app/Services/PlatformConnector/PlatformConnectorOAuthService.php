<?php

namespace App\Services\PlatformConnector;

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
        $connector = PlatformConnector::with('platform')->find((int) $state);
        if (!$connector) {
            return response('Conector no encontrado.', 404);
        }
        if (!$connector->platform || $connector->platform->slug !== $expected_platform_slug) {
            return response('La plataforma del conector no coincide con esta URL de callback.', 400);
        }
        if (empty($connector->platform->client_id) || empty($connector->platform->client_secret)) {
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

}
