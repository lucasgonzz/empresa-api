<?php

namespace App\Services\MercadoLibre;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\MercadoLibreToken;
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

        // Refrescar si ya venció
        if ($this->token->expires_at->isPast()) {
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

    protected function refresh_token()
    {

        Log::info('refresh_token');

        $response = Http::asForm()->post('https://api.mercadolibre.com/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => env('MERCADO_LIBRE_CLIENT_ID'),
            'client_secret' => env('MERCADO_LIBRE_CLIENT_SECRET'),
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
    }
}
