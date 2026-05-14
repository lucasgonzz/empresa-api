<?php

namespace App\Services\MercadoLibre;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\MercadoLibreToken;
use App\Models\Platform;
use App\Models\PlatformConnector;
use App\Services\MercadoLibre\ErrorHandler;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MercadoLibreService
{
    protected $token;

    protected string $base_url = 'https://api.mercadolibre.com/';

    public function __construct($user_id = null)
    {

        if (is_null($user_id)) {
            $user_id = UserHelper::userId();
        }

        $this->token = MercadoLibreToken::where('user_id', $user_id)->first();

        if (!$this->token) {
            throw new \Exception("No existe token guardado para el usuario $user_id");
        }

        // Refrescar si ya venció (solo si hay fecha de expiración persistida).
        if ($this->token->expires_at && $this->token->expires_at->isPast()) {
            $this->refresh_token();
        }
    }

    protected function make_request(string $method, string $endpoint, array $data = [])
    {
        
        $url = $this->base_url . ltrim($endpoint, '/');

        Log::info('make_request: ');
        Log::info($url);

        Log::info('params: ');
        Log::info($data);

        Log::info('method: ');
        Log::info($method);
        // Log::info('con el token: ');
        // Log::info($this->token->access_token);

        $response = Http::withToken($this->token->access_token)->{$method}($url, $data);

        if ($response->status() === 401) {
            // Si devuelve UNAUTHORIZED, intento refrescar
            $this->refresh_token();
            $response = Http::withToken($this->token->access_token)->{$method}($url, $data);
        }

        // ⚠️ Si Mercado Libre devuelve 404 (por ejemplo, descripción no encontrada)
        if ($response->status() === 404) {
            Log::warning("Mercado Libre devolvió 404 para {$url}");
            return null; // 👈 devolvemos null en lugar de lanzar excepción
        }

        if (!$response->successful()) {
            ErrorHandler::send_notification($response);
            throw new \Exception("Mercado Libre API error: " . $response->body());
        }
        
        return $response->json();
    }

    /**
     * Renueva el access_token de Mercado Libre usando refresh_token.
     *
     * Notas:
     * - Preferimos credenciales del `PlatformConnector` conectado del mismo `user_id`.
     * - Si no hay conector con secret, se usa transitoriamente el .env (migración gradual).
     *
     * @return void
     */
    protected function refresh_token()
    {

        Log::info('refresh_token');

        list($client_id, $client_secret) = $this->resolve_meli_oauth_app_credentials();

        $response = Http::asForm()->post('https://api.mercadolibre.com/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'refresh_token' => $this->token->refresh_token,
        ]);

        if (!$response->successful()) {
            throw new \Exception("No se pudo refrescar el token de Mercado Libre: " . $response->body());
        }

        $data = $response->json();

        Log::info('data:');
        Log::info($data);
        
        $this->token->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $this->token->refresh_token, // a veces no viene
            'expires_at'   => Carbon::now()->addSeconds($data['expires_in']),
        ]);

        $this->sync_mercado_libre_platform_connector_tokens($data);
    }

    /**
     * Obtiene client_id y client_secret para el flujo OAuth de ML.
     *
     * @return array{0: string|null, 1: string|null}
     */
    protected function resolve_meli_oauth_app_credentials(): array
    {
        $connector = PlatformConnector::with('platform')
            ->where('user_id', $this->token->user_id)
            ->where('status', PlatformConnector::STATUS_CONECTADO)
            ->whereHas('platform', function ($q) {
                $q->where('slug', Platform::SLUG_MERCADO_LIBRE);
            })
            ->first();
        if ($connector && $connector->platform && $connector->platform->client_id && $connector->platform->client_secret) {
            return [$connector->platform->client_id, $connector->platform->client_secret];
        }

        return [env('MERCADO_LIBRE_CLIENT_ID'), env('MERCADO_LIBRE_CLIENT_SECRET')];
    }

    /**
     * Si existe conector ML conectado para el usuario, replica los tokens recién emitidos.
     *
     * @param array $data Respuesta JSON del endpoint oauth/token (refresh).
     * @return void
     */
    protected function sync_mercado_libre_platform_connector_tokens(array $data): void
    {
        $connector = PlatformConnector::with('platform')
            ->where('user_id', $this->token->user_id)
            ->where('status', PlatformConnector::STATUS_CONECTADO)
            ->whereHas('platform', function ($q) {
                $q->where('slug', Platform::SLUG_MERCADO_LIBRE);
            })
            ->first();
        if (!$connector) {
            return;
        }
        $connector->access_token = $data['access_token'] ?? $connector->access_token;
        if (!empty($data['refresh_token'])) {
            $connector->refresh_token = $data['refresh_token'];
        }
        if (isset($data['expires_in'])) {
            $connector->expires_at = Carbon::now()->addSeconds((int) $data['expires_in']);
        }
        $connector->save();
    }
}
