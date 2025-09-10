<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\TiendaNube\BaseTiendaNubeService;
use Illuminate\Support\LazyCollection;

class TiendaNubeDeleteProductsService extends BaseTiendaNubeService
{

    public function __construct()
    {
        parent::__construct();
        $this->per_page     = 200;

    }

    /**
     * Recorre todos los productos paginados como LazyCollection.
     * GET /v1/{store_id}/products?page=N&per_page=M
     */
    public function fetch_all_products(): LazyCollection
    {
        $per_page = $this->per_page;

        return LazyCollection::make(function () use ($per_page) {
            $page = 1;

            while (true) {
                $response = $this->http()->get("/{$this->store_id}/products", [
                    'page'     => $page,
                    'per_page' => $per_page,
                ]);

                if ($response->failed()) {
                    $message = 'error_listado_productos';
                    Log::warning($message, [
                        'status' => $response->status(),
                        'body'   => $response->body(),
                        'page'   => $page,
                    ]);
                    throw new \RuntimeException("TiendaNube: {$message} (status {$response->status()})");
                }

                $data = $response->json();
                if (empty($data)) {
                    break;
                }

                foreach ($data as $product) {
                    yield $product; // <- generador nativo de PHP
                }

                $page++;
            }
        });
    }

    /**
     * Elimina un producto por ID.
     * DELETE /v1/{store_id}/products/{product_id}
     */
    public function delete_product(int $product_id): bool
    {
        // Manejo simple de 429 (rate limit) con backoff exponencial
        $max_intentos = 5;
        $espera_ms = 500;

        for ($i = 1; $i <= $max_intentos; $i++) {
            $response = $this->http()->delete("/{$this->store_id}/products/{$product_id}");

            if ($response->status() === 404) {
                // Ya no existe: lo consideramos "ok" para idempotencia
                return true;
            }

            if ($response->successful() || $response->status() === 204) {
                return true;
            }

            // Si recibimos 429, esperamos y reintentamos
            if ($response->status() === 429 && $i < $max_intentos) {
                usleep($espera_ms * 1000);
                $espera_ms *= 2;
                continue;
            }

            Log::warning('error_eliminar_producto', [
                'product_id' => $product_id,
                'status'     => $response->status(),
                'body'       => $response->body(),
                'intento'    => $i,
            ]);

            break;
        }

        return false;
    }

    /**
     * Obtiene todos los productos y los elimina.
     * Retorna métricas de la corrida.
     */
    public function delete_all_products(callable $on_progress = null): array
    {
        $eliminados = 0;
        $fallidos   = 0;
        $errores    = [];

        $this->fetch_all_products()->each(function ($product) use (&$eliminados, &$fallidos, &$errores, $on_progress) {
            $product_id = (int) ($product['id'] ?? 0);

            if (!$product_id) {
                $fallidos++;
                $errores[] = 'producto_sin_id';
                return;
            }

            $ok = $this->delete_product($product_id);

            if (is_callable($on_progress)) {
                try {
                    $on_progress($product_id, $ok);
                } catch (\Throwable $e) {
                    // No rompemos el flujo si el callback falla
                    Log::debug('on_progress_exception', ['e' => $e->getMessage()]);
                }
            }

            if ($ok) {
                $eliminados++;
            } else {
                $fallidos++;
            }

            // Pequeña espera para ser amables con la API (reduce 429)
            usleep(250000); // 0.25s
        });

        return [
            'eliminados' => $eliminados,
            'fallidos'   => $fallidos,
            'errores'    => $errores,
        ];
    }
}
