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
        $products = Http::withHeaders($headers)->get("$base_url/products")->json();

        $this->info(count($products).' productos');
        foreach ($products as $product) {
            $this->line("Eliminando producto ID: {$product['id']}");
            Http::withHeaders($headers)->delete("$base_url/products/{$product['id']}");
        }

        $this->info("Eliminando categorías...");
        $categories = Http::withHeaders($headers)->get("$base_url/categories")->json();

        foreach ($categories as $category) {
            $this->line("Eliminando categoría ID: {$category['id']}");
            Http::withHeaders($headers)->delete("$base_url/categories/{$category['id']}");
        }

        $this->info("Purgado completo.");
    }
}
