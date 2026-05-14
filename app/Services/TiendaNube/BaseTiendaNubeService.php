<?php

namespace App\Services\TiendaNube;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Platform;
use App\Models\PlatformConnector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Base HTTP para servicios de Tienda Nube.
 *
 * Responsabilidad:
 * - Configurar `access_token`, `store_id` y `base_url` para las llamadas a la API.
 * - Resolver credenciales desde `PlatformConnector` (OAuth ABM) con fallback a variables de entorno.
 */
abstract class BaseTiendaNubeService
{
    /** ID de tienda en TN (equivale a `user_id` en la respuesta del token OAuth). */
    protected ?int $store_id = null;

    /** User-Agent exigido por la API de Tienda Nube. */
    protected ?string $user_agent = null;

    /** Prefijo base de la API REST de Tienda Nube. */
    protected ?string $base_url = null;

    /** Bearer token actual para autenticar requests. */
    protected ?string $access_token = null;

    /**
     * Usuario del ERP cuyo conector TN debe usarse (jobs, requests HTTP, etc.).
     *
     * @var int|null
     */
    protected ?int $tn_context_user_id = null;

    /**
     * Conector persistido usado para credenciales (si existe y está conectado).
     *
     * @var PlatformConnector|null
     */
    protected ?PlatformConnector $tn_platform_connector = null;

    /**
     * @param int|null $user_id Usuario dueño; si es null se usa `UserHelper::userId()`.
     */
    public function __construct($user_id = null)
    {
        $this->tn_context_user_id = $user_id !== null ? (int) $user_id : (int) UserHelper::userId();

        $this->tn_platform_connector = PlatformConnector::where('user_id', $this->tn_context_user_id)
            ->where('status', PlatformConnector::STATUS_CONECTADO)
            ->whereHas('platform', function ($q) {
                $q->where('slug', Platform::SLUG_TIENDA_NUBE);
            })
            ->first();

        if (
            $this->tn_platform_connector
            && $this->tn_platform_connector->access_token
            && $this->tn_platform_connector->platform_user_id
        ) {
            $this->access_token = $this->tn_platform_connector->access_token;
            $this->store_id = (int) $this->tn_platform_connector->platform_user_id;
        } else {
            $this->access_token = env('TN_ACCESS_TOKEN');
            $store_from_env = env('TN_USER_ID');
            $this->store_id = $store_from_env !== null && $store_from_env !== '' ? (int) $store_from_env : null;
            if (!$this->tn_platform_connector) {
                Log::info('BaseTiendaNubeService: sin PlatformConnector TN conectado; usando credenciales de entorno si existen.');
            }
        }

        $this->user_agent = 'ComercioCity (comerciocity.erp@gmail.com)';
        $this->base_url = 'https://api.tiendanube.com/2025-03';
    }

    /**
     * Cliente HTTP con headers requeridos por Tienda Nube.
     *
     * @return \Illuminate\Http\Client\PendingRequest
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
