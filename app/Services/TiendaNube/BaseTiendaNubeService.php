<?php

namespace App\Services\TiendaNube;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

abstract class BaseTiendaNubeService
{
    protected ?int $store_id = null;
    protected ?string $user_agent = null;
    protected ?string $base_url = null;
    protected ?string $access_token = null;

    public function __construct()
    {
        $this->access_token = env('TN_ACCESS_TOKEN');
        $this->store_id     = env('TN_USER_ID');
        // $this->access_token = config('tiendanube.access_token');
        // $this->store_id     = config('tiendanube.store_id');
        $this->user_agent   = 'ComercioCity (comerciocity.erp@gmail.com)';
        $this->base_url     = 'https://api.tiendanube.com/2025-03';
        // $this->base_url     = 'https://api.tiendanube.com/v1';
        
    }

    /**
     * Cliente HTTP con headers requeridos por Tienda Nube.
     */
    protected function http()
    {
        return Http::withHeaders([
                'Authentication' => 'bearer ' . $this->access_token,
                'User-Agent'     => $this->user_agent,
                'Accept'         => 'application/json',
            ])
            ->baseUrl($this->base_url)
            ->timeout(60)
            ->retry(3, 500);
    }

    /**
     * Cliente HTTP con un solo intento y sin lanzar RequestException ante 4xx/5xx.
     *
     * Motivo: con retry(n>1) el PendingRequest de Laravel llama a throw() en respuestas
     * no exitosas; Tienda Nube devuelve 404 "Last page is 0" cuando no hay órdenes,
     * y ese caso debe tratarse como lista vacía, no como error fatal.
     *
     * @return \Illuminate\Http\Client\PendingRequest
     */
    protected function http_without_throw_on_error_status()
    {
        return Http::withHeaders([
                'Authentication' => 'bearer ' . $this->access_token,
                'User-Agent'     => $this->user_agent,
                'Accept'         => 'application/json',
            ])
            ->baseUrl($this->base_url)
            ->timeout(60)
            ->retry(1, 0);
    }
}
