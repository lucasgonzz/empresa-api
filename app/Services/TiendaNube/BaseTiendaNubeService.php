<?php

namespace App\Services\TiendaNube;

use Illuminate\Support\Facades\Http;

abstract class BaseTiendaNubeService
{
    protected string $base_url;
    protected int $store_id;
    protected string $access_token;
    protected string $user_agent;

    public function __construct()
    {
        $this->access_token = env('TN_ACCESS_TOKEN');
        $this->store_id     = env('TN_USER_ID');
        $this->user_agent   = 'ComercioCity (comerciocity.erp@gmail.com)';
        $this->base_url     = 'https://api.tiendanube.com/v1';
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
}
