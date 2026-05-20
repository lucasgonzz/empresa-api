<?php

namespace App\Services\MercadoLibre;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\Order\CreateSaleOrderHelper;
use App\Models\Article;
use App\Models\MeliBuyer;
use App\Models\MeliOrder;
use App\Models\SyncFromMeliOrder;
use App\Models\User;
use App\Notifications\GlobalNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Descarga pedidos desde la API de Mercado Libre y los persiste como MeliOrder.
 * Puede ejecutarse ligado a un SyncFromMeliOrder (sincronización masiva) o en modo liviano (polling / webhooks).
 */
class OrderDownloaderService extends MercadoLibreService
{
    /** @var SyncFromMeliOrder|null Registro de sincronización masiva; null en polling o notificaciones */
    public $syncRecord;

    /** @var MeliOrder|null Última orden local procesada */
    public $meli_order;

    /** @var array<string, mixed>|null Payload de la orden ML en curso */
    public $order_data;

    /** @var bool Indica si falló el lote masivo (solo aplica con syncRecord) */
    public $hubo_error;

    /** @var int Usuario interno dueño del conector ML */
    public $user_id;

    /** @var User|null */
    public $user;

    /** @var Controller Instancia mínima para helpers que esperan Controller */
    public $controller;

    /**
     * @param int|null $user_id Usuario empresa; por defecto config app.USER_ID en jobs legacy
     */
    public function __construct($user_id = null)
    {
        if (!$user_id) {
            $user_id = config('app.USER_ID');
        }

        parent::__construct($user_id);

        $this->controller = new Controller();

        $this->user_id = $user_id;
        $this->user = User::find($user_id);

        $this->hubo_error = false;
        $this->syncRecord = null;
    }

    /**
     * Obtiene una orden por path de recurso (ej. orders/123) y la importa.
     *
     * @param string $resource Path relativo a api.mercadolibre.com (con o sin slash inicial)
     * @return void
     */
    public function obtener_order($resource)
    {
        $resource = ltrim($resource, '/');
        Log::info('obtener_order, resource: '.$resource);

        $order = $this->make_request('GET', $resource);

        $this->store_or_update_order($order);
    }

    /**
     * Descarga todas las órdenes del vendedor asociadas a un registro SyncFromMeliOrder.
     *
     * @param int $sync_from_meli_order_id
     * @return void
     */
    public function get_all_orders($sync_from_meli_order_id)
    {
        $this->syncRecord = SyncFromMeliOrder::find($sync_from_meli_order_id);

        $orders = $this->make_request('GET', 'orders/search', [
            'seller' => $this->meli_seller_id(),
        ]);

        Log::info('orders:');
        Log::info($orders);

        foreach ($orders['results'] as $order) {
            if (!$this->hubo_error) {
                $this->store_or_update_order($order);
            }
        }

        if ($this->syncRecord && !$this->hubo_error) {
            $this->syncRecord->status = 'exitosa';
            $this->syncRecord->save();

            $this->notificacion();
        }
    }

    /**
     * Importa órdenes pagas modificadas recientemente (polling al abrir el listado, estilo Tienda Nube).
     * No usa SyncFromMeliOrder ni envía notificaciones globales.
     *
     * @return void
     */
    public function get_recent_orders()
    {
        $this->syncRecord = null;

        $from_date = $this->resolve_orders_search_from_date();

        $query = [
            'seller' => $this->meli_seller_id(),
            'order.status' => 'paid',
            'sort' => 'date_desc',
            'order.date_last_updated.from' => $from_date,
        ];

        $orders = $this->make_request('GET', 'orders/search', $query);

        Log::info('get_recent_orders ML result count: '.(isset($orders['results']) ? count($orders['results']) : 0));

        if (empty($orders['results']) || !is_array($orders['results'])) {
            return;
        }

        foreach ($orders['results'] as $order) {
            try {
                $this->store_or_update_order($order);
            } catch (\Exception $e) {
                Log::warning('get_recent_orders: fallo en una orden: '.$e->getMessage());
            }
        }
    }

    /**
     * Fecha mínima para el filtro order.date_last_updated.from (con margen hacia atrás).
     *
     * @return string Formato ISO compatible con la API ML
     */
    protected function resolve_orders_search_from_date()
    {
        $last_local = MeliOrder::where('user_id', $this->user_id)->max('updated_at');
        if ($last_local) {
            return Carbon::parse($last_local)->subHours(2)->toIso8601String();
        }

        return Carbon::now()->subDays(7)->startOfDay()->toIso8601String();
    }

    /**
     * Notificación al usuario al finalizar una sincronización masiva exitosa.
     *
     * @return void
     */
    public function notificacion()
    {
        if (!$this->syncRecord || !$this->user) {
            return;
        }

        Log::info('Mandando niotificacion');

        $functions_to_execute = [
            [
                'btn_text' => 'Ver mas detalles',
                'function_name' => 'go_to_sync_from_meli_order',
                'btn_variant' => 'primary',
            ],
        ];

        $info_to_show = [
            [
                'title' => 'Resultado de la operacion',
                'parrafos' => [
                    count($this->syncRecord->meli_orders).' pedidos creados',
                ],
            ],
        ];

        $this->user->notify(new GlobalNotification([
            'message_text' => 'Sincronizacion ENTRANTE con Mercado Libre finalizada correctamente',
            'color_variant' => 'success',
            'functions_to_execute' => $functions_to_execute,
            'info_to_show' => $info_to_show,
            'owner_id' => $this->user->id,
            'is_only_for_auth_user' => false,
        ]));
    }

    /**
     * Notificación de error en sincronización masiva.
     *
     * @return void
     */
    public function notificacion_error()
    {
        if (!$this->syncRecord || !$this->user) {
            return;
        }

        $functions_to_execute = [
            [
                'btn_text' => 'Ver mas detalles',
                'function_name' => 'go_to_sync_from_meli_order',
                'btn_variant' => 'primary',
            ],
        ];

        $info_to_show = [
            [
                'title' => 'Error al sincronizar pedidos con Mercado Libre',
                'parrafos' => [
                    $this->syncRecord->error_message_crudo,
                ],
            ],
        ];

        $this->user->notify(new GlobalNotification([
            'message_text' => 'Error con Sincronizacion ENTRANTE con Mercado Libre',
            'color_variant' => 'danger',
            'functions_to_execute' => $functions_to_execute,
            'info_to_show' => $info_to_show,
            'owner_id' => $this->user->id,
            'is_only_for_auth_user' => false,
        ]));
    }

    /**
     * Persiste o actualiza una orden local a partir del JSON de ML (búsqueda o GET detalle).
     *
     * @param array<string, mixed> $order_data
     * @return void
     */
    public function store_or_update_order(array $order_data)
    {
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

            if ($this->syncRecord) {
                $this->syncRecord->error_message_crudo = $e->__toString();
                $this->syncRecord->error_message = $e->getMessage();
                $this->syncRecord->save();

                $this->attachSyncPivot('error', $e->getMessage());

                $this->notificacion_error();
            } else {
                Log::error('OrderDownloaderService sin syncRecord: '.$e->getMessage());
                $this->notify_meli_exception(
                    $e,
                    'Error al procesar pedido de Mercado Libre'
                );
            }
        }
    }

    /**
     * Crea o actualiza el comprador ML asociado al usuario empresa.
     *
     * @return MeliBuyer|null
     */
    public function create_buyer()
    {
        $buyer_data = $this->order_data['buyer'] ?? null;

        if (!$buyer_data || empty($buyer_data['id'])) {
            return null;
        }

        return MeliBuyer::updateOrCreate(
            [
                'meli_buyer_id' => (string) $buyer_data['id'],
                'user_id' => $this->user_id,
            ],
            [
                'nickname' => $buyer_data['nickname'] ?? null,
            ]
        );
    }

    /**
     * Upsert del modelo MeliOrder local.
     *
     * @param MeliBuyer|null $meli_buyer
     * @return void
     */
    public function update_or_create_meli_order($meli_buyer)
    {
        $order_id = $this->order_data['id'] ?? null;
        if ($order_id === null) {
            throw new \Exception('order_data sin id');
        }

        $meli_order = MeliOrder::updateOrCreate(
            [
                'meli_order_id' => (string) $order_id,
            ],
            [
                'meli_created_at' => isset($this->order_data['date_created'])
                    ? Carbon::parse($this->order_data['date_created'])
                    : null,
                'meli_closed_at' => isset($this->order_data['date_closed'])
                    ? Carbon::parse($this->order_data['date_closed'])
                    : null,
                'status' => $this->order_data['status'] ?? null,
                'status_detail' => $this->order_data['status_detail'] ?? null,
                'total' => $this->order_data['total_amount'] ?? 0,
                'shipping_cost' => $this->order_data['shipping_cost'] ?? 0,
                'meli_buyer_id' => $meli_buyer ? $meli_buyer->id : null,
                'user_id' => $this->user_id,
            ]
        );

        $this->meli_order = $meli_order;
    }

    /**
     * Vincula artículos locales por me_li_id con cantidades del pedido.
     *
     * @return void
     */
    public function attach_articles()
    {
        $order_items = $this->order_data['order_items'] ?? [];

        foreach ($order_items as $item_data) {
            $item = $item_data['item'] ?? null;
            if (!$item || empty($item['id'])) {
                continue;
            }

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

    /**
     * Sincroniza tags del pedido ML.
     *
     * @return void
     */
    public function create_tags()
    {
        $tags = $this->order_data['tags'] ?? [];

        if (!empty($tags)) {
            $this->meli_order->tags()->delete();

            foreach ($tags as $tag) {
                $this->meli_order->tags()->create(['tag' => $tag]);
            }
        }
    }

    /**
     * Persiste detalle de cancelación si viene en la orden.
     *
     * @return bool
     */
    public function check_cancel()
    {
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
     * Adjunta estado al pivote de la sincronización masiva (si aplica).
     *
     * @param string $status
     * @param string|null $error_code
     * @return void
     */
    protected function attachSyncPivot($status, $error_code = null)
    {
        if (!$this->syncRecord || !$this->meli_order) {
            return;
        }

        $this->syncRecord->meli_orders()->attach($this->meli_order->id, [
            'status' => $status,
            'error_code' => $error_code,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Genera la venta local a partir del pedido ML (uso manual o flag de entorno).
     *
     * @param int $id meli_orders.id
     * @return \Illuminate\Http\JsonResponse
     */
    public function create_sale($id)
    {
        $meli_order = MeliOrder::find($id);

        CreateSaleOrderHelper::save_sale($meli_order, $this->controller, false, true, $this->user);

        return response()->json(['model' => $this->controller->fullModel('MeliOrder', $id)], 200);
    }
}
