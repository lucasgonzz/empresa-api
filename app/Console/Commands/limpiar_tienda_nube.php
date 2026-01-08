<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class limpiar_tienda_nube extends Command
{
    protected $signature = 'limpiar_tienda_nube';
    protected $description = 'Elimina todos los productos y categorías de Tiendanube';

    public function handle()
    {
        $access_token = env('TN_ACCESS_TOKEN');
        $store_id = env('TN_USER_ID');
        $base_url = "https://api.tiendanube.com/v1/$store_id";

        $headers = [
            'Authentication' => "bearer $access_token",
            'User-Agent' => 'LaravelApp (lucas@example.com)'
        ];

        $this->info("Eliminando productos...");
        $page = 1;
        $deleted_products = 0;

        while (true) {
            $response = Http::withHeaders($headers)
                            ->get("$base_url/products", ['page' => $page, 'per_page' => 200]);

            if (!$response->ok()) {
                $this->error("Error al obtener productos (código {$response->status()})");
                $this->error($response->body());
                break;
            }

            $products = $response->json();

            if (!is_array($products)) {
                $this->error("Respuesta inesperada al obtener productos.");
                $this->error(json_encode($products));
                break;
            }

            if (empty($products)) {
                break;
            }

            foreach ($products as $product) {
                if (!isset($product['id'])) {
                    $this->warn("Producto sin ID, ignorado: " . json_encode($product));
                    continue;
                }

                $this->line("Eliminando producto ID: {$product['id']}");
                Http::withHeaders($headers)->delete("$base_url/products/{$product['id']}");
                $deleted_products++;
            }

            $page++;
        }

        $this->info("Se eliminaron $deleted_products productos.");

        $this->info("Eliminando categorías...");
        $categories_response = Http::withHeaders($headers)->get("$base_url/categories");

        if ($categories_response->ok()) {
            $categories = $categories_response->json();

            if (is_array($categories)) {
                foreach ($categories as $category) {
                    if (!isset($category['id'])) {
                        $this->warn("Categoría sin ID, ignorada: " . json_encode($category));
                        continue;
                    }

                    $this->line("Eliminando categoría ID: {$category['id']}");
                    Http::withHeaders($headers)->delete("$base_url/categories/{$category['id']}");
                }
            } else {
                $this->warn("Respuesta inesperada al obtener categorías.");
            }
        } else {
            $this->error("Error al obtener categorías (código {$categories_response->status()})");
        }

        $this->info("Purgado completo.");
    }
}
