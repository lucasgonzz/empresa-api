<?php

namespace App\Services\TiendaNube;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use App\Models\TiendaNubeOrder;
use App\Services\TiendaNube\BaseTiendaNubeService;
use Illuminate\Support\Facades\Log;

class TiendaNubeOrderService extends BaseTiendaNubeService
{

    public function sincronizar_nuevos_pedidos(): array
    {
        $nuevos = [];

        Log::info('Consultando órdenes en Tienda Nube:', [
            'store_id' => $this->store_id,
            'url' => "/{$this->store_id}/orders"
        ]);

        // Un solo intento: evita que retry(3) de http() dispare throw() ante 404 de TN (sin pedidos).
        $response = $this->http_without_throw_on_error_status()->get("/{$this->store_id}/orders", [
            'page' => 1,
        ]);

        if ($response->status() === 404) {
            // TN: sin órdenes suele responder 404 con description "Last page is 0".
            Log::info('No hay pedidos en Tienda Nube (404 o listado vacío).');
            return [];
        }

        if (!$response->successful()) {
            Log::warning('Tienda Nube orders: respuesta no exitosa al sincronizar.', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return [];
        }

        $ordenes = $response->json();

        Log::info('orders de tienda nube:');
        Log::info($ordenes);

        foreach ($ordenes as $orden_data) {
            $ya_existe = TiendaNubeOrder::where('external_id', $orden_data['id'])->exists();
            if ($ya_existe) {
                continue;
            }

            $pedido = TiendaNubeOrder::create([
                'external_id'   => $orden_data['id'],
                'customer_name' => $orden_data['billing_address']['name'] ?? null,
                'total'         => $orden_data['total'],
                'data'          => json_encode($orden_data),
                'address_id'    => 0,
                'payment_status'          => $this->traducir_payment_status($orden_data['payment_status']),
                'tienda_nube_order_status_id' => 1,
                'user_id'       => UserHelper::userId(),
            ]);

            foreach ($orden_data['products'] ?? [] as $producto) {
                $article = Article::where('tiendanube_product_id', $producto['product_id'])->first();
                if ($article) {
                    $pedido->articles()->attach($article->id, [
                        'price'     => $producto['price'],
                        'amount'     => $producto['quantity'],
                    ]);
                }
            }

            $nuevos[] = $pedido;
        }

        return $nuevos;
    }

    function traducir_payment_status(string $status): string
    {
        switch ($status) {
            case 'pending':
                return 'Pendiente';
            case 'authorized':
                return 'Autorizado';
            case 'paid':
                return 'Pagado';
            case 'voided':
                return 'Anulado';
            case 'refunded':
                return 'Reembolsado';
            case 'abandoned':
                return 'Abandonado';
            default:
                return ucfirst($status); // Fallback si llega uno desconocido
        }
    }

}
