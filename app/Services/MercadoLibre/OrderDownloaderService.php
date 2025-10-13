<?php

namespace App\Services\MercadoLibre;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\Order\CreateSaleOrderHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use App\Models\ArticlePurchase;
use App\Models\MeliBuyer;
use App\Models\MeliOrder;
use App\Models\MercadoLibreToken;
use App\Models\Sale;
use App\Models\SyncFromMeliOrder;
use App\Models\User;
use App\Notifications\GlobalNotification;
use App\Services\MercadoLibre\MercadoLibreService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderDownloaderService extends MercadoLibreService
{

    public $meli_order;

    function __construct($user_id = null) {
        
        if (!$user_id) {
            $user_id = env('USER_ID');
        }
        
        parent::__construct($user_id);

        $this->controller = new Controller();

        $this->user_id = $user_id;
        $this->user = User::find($user_id);

        $this->token = MercadoLibreToken::where('user_id', $user_id)->first();

        $this->hubo_error = false;
    }



    function obtener_order($resource) {

        Log::info('obtener_order, resource: '.$resource);
        
        $order = $this->make_request('GET', $resource);

        $this->store_or_update_order($order);

    }


    function get_all_orders($sync_from_meli_order_id) {

        $this->syncRecord = SyncFromMeliOrder::find($sync_from_meli_order_id);

        $orders = $this->make_request('GET', "orders/search", [
            'seller' => $this->token->meli_user_id
        ]);

        Log::info('orders:');
        Log::info($orders);

        foreach ($orders['results'] as $order) {

            if (!$this->hubo_error) {

                $this->store_or_update_order($order);
            }

        }

        if (!$this->hubo_error) {

            $this->syncRecord->status = 'exitosa';
            $this->syncRecord->save();

            $this->notificacion();
        }


    }

    function notificacion() {

        Log::info('Mandando niotificacion');

        $functions_to_execute = [
            [
                'btn_text'      => 'Ver mas detalles',
                'function_name' => 'go_to_sync_from_meli_order',
                'btn_variant'   => 'primary',
            ],
        ];

        $info_to_show = [
            [
                'title'     => 'Resultado de la operacion',
                'parrafos'  => [
                    count($this->syncRecord->meli_orders). ' pedidos creados',
                ],
            ],
        ];

        $this->user->notify(new GlobalNotification([
            'message_text'              => 'Sincronizacion ENTRANTE con Mercado Libre finalizada correctamente',
            'color_variant'             => 'success',
            'functions_to_execute'      => $functions_to_execute,
            'info_to_show'              => $info_to_show,
            'owner_id'                  => $this->user->id,
            'is_only_for_auth_user'     => false,
            ])
        );
    }

    function notificacion_error() {

        $functions_to_execute = [
            [
                'btn_text'      => 'Ver mas detalles',
                'function_name' => 'go_to_sync_from_meli_order',
                'btn_variant'   => 'primary',
            ],
        ];

        $info_to_show = [
            [
                'title'     => 'Error al sincronizar pedidos con Mercado Libre',
                'parrafos'  => [
                    $this->syncRecord->error_message_crudo,
                ],
            ],
        ];

        $this->user->notify(new GlobalNotification([
            'message_text'              => 'Error con Sincronizacion ENTRANTE con Mercado Libre',
            'color_variant'             => 'success',
            'functions_to_execute'      => $functions_to_execute,
            'info_to_show'              => $info_to_show,
            'owner_id'                  => $this->user->id,
            'is_only_for_auth_user'     => false,
            ])
        );
    }

    public function store_or_update_order(array $order_data) {

        try {

            DB::transaction(function () use ($order_data) {

                Log::info('order:');
                Log::info($order_data);

                $this->order_data = $order_data;


                $meli_buyer = $this->create_buyer();


                $this->update_or_create_meli_order($meli_buyer);


                $this->attach_articles();


                $this->create_tags();


                $this->check_cancel();


                $this->attachSyncPivot('success');

                if (env('CREAR_SALES_AL_DESCARGAR_MELI_ORDERS', false)) {
                    $this->create_sale($this->meli_order->id);
                }


                Log::info('meli_order creada');
            });

        } catch (\Exception $e) {

            $this->hubo_error = true;

            Log::error('Error al crear/actualizar orden desde MercadoLibre: ');

            
            Log::info($e->getTraceAsString());


            $this->syncRecord->error_message_crudo = $e->__toString();
            $this->syncRecord->error_message = $e->getMessage();

            $this->syncRecord->save();

            $this->attachSyncPivot('error', $e->getMessage());

            $this->notificacion_error();
        }
    }

    function create_buyer() {

        $buyer_data = $this->order_data['buyer'] ?? null;
        $buyer = null;

        if ($buyer_data) {
            $buyer = MeliBuyer::updateOrCreate(
                ['meli_buyer_id' => $buyer_data['id']],
                [
                    'nickname' => $buyer_data['nickname'] ?? null,
                    'user_id'   => $this->user_id,
                ]
            );
        }

        return $buyer;
    }

    function update_or_create_meli_order($meli_buyer) {

        // $existing = MeliOrder::where('meli_order_id', $order_data['id'])
        //                    ->where('user_id', $this->user_id)
        //                    ->first();

        // if ($existing) {
        //     return;
        // }

        $meli_order = MeliOrder::updateOrCreate(
            ['meli_order_id' => $this->order_data['id']],
            [
                // 'meli_created_at' => $this->order_data['date_created'] ?? null,
                // 'meli_closed_at' => $this->order_data['date_closed'] ?? null,

                'meli_created_at' => isset($order_data['date_created']) ? Carbon::parse($this->order_data['date_created']) : null,
                'meli_closed_at'  => isset($order_data['date_closed']) ? Carbon::parse($this->order_data['date_closed']) : null,

                'status' => $this->order_data['status'] ?? null,
                'total' => $this->order_data['total_amount'] ?? 0,
                'shipping_cost' => $this->order_data['shipping_cost'] ?? 0,
                'meli_buyer_id' => $meli_buyer->id,
                'created_at'    => $this->order_data['date_created'],
                'user_id'       => env('USER_ID'),
            ]
        );

        $this->meli_order = $meli_order;
    }

    function attach_articles() {

        $order_items = $this->order_data['order_items'] ?? [];

        foreach ($order_items as $item_data) {
            $item = $item_data['item'] ?? null;
            if (!$item || empty($item['id'])) {
                continue;
            }

            // Buscar artículo local por su meli_id
            $article = Article::where('me_li_id', $item['id'])->first();

            if ($article) {
                $this->meli_order->articles()->syncWithoutDetaching([
                    $article->id => [
                        'amount' => $item_data['quantity'] ?? 1,
                        'price' => $item_data['unit_price'] ?? 0,
                    ],
                ]);

            } else {
                Log::warning("Artículo no encontrado con meli_id: {$item['id']}");
            }
        }
    }

    function create_tags() {

        $tags = $this->order_data['tags'] ?? [];

        if (!empty($tags)) {
            // Eliminamos tags previos para mantener consistencia
            $this->meli_order->tags()->delete();

            foreach ($tags as $tag) {
                $this->meli_order->tags()->create(['tag' => $tag]);
            }
        }
    }

    function check_cancel() {

        $cancel = $this->order_data['cancel_detail'] ?? null;

        if ($cancel) {
            $this->meli_order->cancel_detail()->updateOrCreate(
                ['meli_order_id' => $this->meli_order->id],
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
        
            return true;
        }

        return false;
    }

    /**
     * Adjunta estado al pivote de la sincronización
     */
    protected function attachSyncPivot(string $status, ?string $error_code = null)
    {

        $this->syncRecord->meli_orders()->attach($this->meli_order->id, [
            'status'      => $status,
            'error_code'  => $error_code,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    function create_sale($id) {
        
        $meli_order = MeliOrder::find($id);

        CreateSaleOrderHelper::save_sale($meli_order, $this->controller, false, true, $this->user);

        return response()->json(['model' => $this->fullModel('MeliOrder', $id)], 200);

    }
}
