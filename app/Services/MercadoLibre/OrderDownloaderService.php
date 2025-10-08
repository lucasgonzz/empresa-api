<?php

namespace App\Services\MercadoLibre;

use App\Models\Article;
use App\Models\MeliBuyer;
use App\Models\MeliOrder;
use App\Models\MercadoLibreToken;
use App\Services\MercadoLibre\MercadoLibreService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class OrderDownloaderService extends MercadoLibreService
{

    function __construct($user_id = null) {
        parent::__construct($user_id);

        $this->token = MercadoLibreToken::where('user_id', env('USER_ID'))->first();
    }


    function get_all_orders() {

        $orders = $this->make_request('GET', "orders/search", [
            'seller' => $this->token->meli_user_id
        ]);

        Log::info('orders:');
        Log::info($orders);

        foreach ($orders['results'] as $order) {

            $this->store_or_update_order($order);
        }


    }

    public function store_or_update_order(array $order_data) {

        return DB::transaction(function () use ($order_data) {

            Log::info('order:');
            Log::info($order_data);

            // ==========================
            // 1️⃣ Crear / actualizar el buyer
            // ==========================
            $buyer_data = $order_data['buyer'] ?? null;
            $buyer = null;

            if ($buyer_data) {
                $buyer = MeliBuyer::updateOrCreate(
                    ['meli_buyer_id' => $buyer_data['id']],
                    [
                        'nickname' => $buyer_data['nickname'] ?? null,
                        'user_id'   => env('USER_ID'),
                    ]
                );
            }

            // ==========================
            // 2️⃣ Crear / actualizar la orden principal
            // ==========================
            $order = MeliOrder::updateOrCreate(
                ['meli_order_id' => $order_data['id']],
                [
                    'meli_created_at' => $order_data['date_created'] ?? null,
                    'meli_closed_at' => $order_data['date_closed'] ?? null,
                    'status' => $order_data['status'] ?? null,
                    'total_amount' => $order_data['total_amount'] ?? 0,
                    'shipping_cost' => $order_data['shipping_cost'] ?? 0,
                    'meli_buyer_id' => $buyer->id,
                    'user_id'       => env('USER_ID'),
                ]
            );

            // ==========================
            // 3️⃣ Asociar artículos
            // ==========================
            $order_items = $order_data['order_items'] ?? [];

            foreach ($order_items as $item_data) {
                $item = $item_data['item'] ?? null;
                if (!$item || empty($item['id'])) {
                    continue;
                }

                // Buscar artículo local por su meli_id
                $article = Article::where('me_li_id', $item['id'])->first();

                if ($article) {
                    $order->articles()->syncWithoutDetaching([
                        $article->id => [
                            'amount' => $item_data['quantity'] ?? 1,
                            'price' => $item_data['unit_price'] ?? 0,
                        ],
                    ]);
                } else {
                    Log::warning("Artículo no encontrado con meli_id: {$item['id']}");
                }
            }

            // ==========================
            // 4️⃣ Guardar tags
            // ==========================
            $tags = $order_data['tags'] ?? [];

            if (!empty($tags)) {
                // Eliminamos tags previos para mantener consistencia
                $order->tags()->delete();

                foreach ($tags as $tag) {
                    $order->tags()->create(['tag' => $tag]);
                }
            }

            // ==========================
            // 5️⃣ Guardar detalles de cancelación (si existen)
            // ==========================
            $cancel = $order_data['cancel_detail'] ?? null;

            if ($cancel) {
                $order->cancel_detail()->updateOrCreate(
                    ['meli_order_id' => $order->id],
                    [
                        'group' => $cancel['group'] ?? null,
                        'code' => $cancel['code'] ?? null,
                        'description' => $cancel['description'] ?? null,
                        'requested_by' => $cancel['requested_by'] ?? null,
                        'date' => isset($cancel['date'])
                            ? Carbon::parse($cancel['date'])
                            : null,
                        'application_id' => $cancel['application_id'] ?? null,
                    ]
                );
            }

            Log::info('order creada');
            // return $order->fresh(['buyer', 'articles', 'tags', 'cancel_detail']);
        });
    }
}
