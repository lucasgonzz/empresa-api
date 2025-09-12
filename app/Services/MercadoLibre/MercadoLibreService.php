<?php

namespace App\Services\MercadoLibre;

use Illuminate\Support\Facades\Http;

class MercadoLibreService
{
    protected string $access_token;

    public function __construct()
    {
        // Aquí podrías obtenerlo desde configuración o base de datos
        $this->access_token = env('MERCADO_LIBRE_TOKEN');
        // $this->access_token = config('services.mercadolibre.token');
    }

    protected function make_request(string $method, string $url, array $data = [])
    {
        $response = Http::withToken($this->access_token)->{$method}($url, $data);

        if (!$response->successful()) {
            throw new \Exception("Mercado Libre API error: " . $response->body());
        }

        return $response->json();
    }
}
