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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Descarga pedidos desde la API de Mercado Libre y los persiste como MeliOrder.
 * Puede ejecutarse ligado a un SyncFromMeliOrder (sincronización masiva) o en modo liviano (polling / webhooks).
 *
 * Misión mercado-libre-integracion (27/10/2026):
 * - `sincronizar_pedidos_recientes()`: la consulta periódica (cada 5 minutos desde el comando
 *   `meli_sync_orders`, y al abrir el listado si pasó más de un minuto). Trae los pedidos
 *   ACTUALIZADOS desde el último poll (menos 10 minutos de margen), sin filtrar por `paid`, para
 *   que lleguen también las cancelaciones.
 * - El pedido se busca por `meli_order_id` + `user_id` (antes solo por id: en una base compartida
 *   el pedido de un comercio podía pisar el de otro) y guarda `pack_id` y `shipping_id`.
 * - Venta automática: un pedido PAGO, sin venta y con todos sus renglones vinculados a artículos
 *   genera la venta solo (y baja el stock), bajo un candado por pedido y re-chequeando adentro
 *   (el webhook y la consulta no pueden crear dos ventas). Solo para pedidos posteriores a la
 *   conexión de la cuenta: los de antes ya los atendió el comercio por fuera del sistema.
 * - Avisos al dueño: renglón sin vincular (el pedido queda Pendiente) y pedido cancelado en ML
 *   que ya tenía venta (la venta no se anula sola).
 */
class OrderDownloaderService extends MercadoLibreService
{
    /** Minutos de margen hacia atrás sobre el último poll. */
    const MINUTOS_DE_MARGEN = 10;

    /** Días hacia atrás en el primer poll. */
    const DIAS_PRIMER_POLL = 7;

    /** Tope de pedidos por consulta (4 páginas de 50). */
    const MAXIMO_POR_CONSULTA = 200;

    /** @var string|null Estado del pedido en ML antes de esta actualización (null si es nuevo) */
    protected $estado_anterior = null;

    /** @var array<int, string> Renglones del pedido sin artículo vinculado (para el aviso) */
    protected $renglones_sin_vincular = [];

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

        // 404 (pedido de otra cuenta o borrado): no hay nada que guardar. Antes esto llegaba como
        // null a store_or_update_order(array) y tiraba un TypeError en el job.
        if (!is_array($order)) {
            Log::warning('obtener_order: Mercado Libre no devolvió el pedido '.$resource);

            return;
        }

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

        // Solo la cantidad: el JSON de los pedidos trae datos de los compradores.
        Log::info('get_all_orders: '.(isset($orders['results']) && is_array($orders['results']) ? count($orders['results']) : 0).' pedidos');

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
     * LEGACY: nombre viejo de la consulta al abrir el listado. Hace lo mismo que
     * `sincronizar_pedidos_recientes()`.
     *
     * @return void
     */
    public function get_recent_orders()
    {
        $this->sincronizar_pedidos_recientes();
    }

    /**
     * True si corresponde consultar pedidos para ese conector: nunca se consultó o el último poll
     * fue hace más de `$minutos`.
     *
     * @param \App\Models\PlatformConnector $connector
     * @param int $minutos
     * @return bool
     */
    public static function corresponde_consultar($connector, $minutos)
    {
        $extra = is_array($connector->extra_config) ? $connector->extra_config : [];
        $ultimo = isset($extra['meli']['ultimo_poll_pedidos']) ? $extra['meli']['ultimo_poll_pedidos'] : null;

        if (empty($ultimo)) {
            return true;
        }

        try {
            return Carbon::parse($ultimo)->lte(Carbon::now()->subMinutes((int) $minutos));
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Consulta periódica de pedidos: `orders/search` por `order.date_last_updated.from` (último poll
     * menos 10 minutos, o 7 días si nunca se consultó), del más nuevo al más viejo, paginando de a
     * 50 hasta 200. Sin filtrar por estado: así llegan las cancelaciones.
     *
     * El momento del poll se guarda en `extra_config.meli.ultimo_poll_pedidos` recién al terminar
     * (si la consulta se corta, la próxima vuelve a mirar desde el poll anterior).
     *
     * @return array{procesados: int, total: int}
     */
    public function sincronizar_pedidos_recientes()
    {
        $this->syncRecord = null;

        $inicio = Carbon::now();
        $ultimo = $this->extra_meli('ultimo_poll_pedidos');

        $desde = null;
        if (!empty($ultimo)) {
            try {
                $desde = Carbon::parse($ultimo)->subMinutes(self::MINUTOS_DE_MARGEN);
            } catch (\Throwable $e) {
                $desde = null;
            }
        }
        if (is_null($desde)) {
            $desde = Carbon::now()->subDays(self::DIAS_PRIMER_POLL);
        }

        $offset = 0;
        $limit = 50;
        $procesados = 0;
        $total = 0;

        do {
            $respuesta = $this->make_request('GET', 'orders/search', [
                'seller'                       => $this->meli_seller_id(),
                'order.date_last_updated.from' => $desde->format('Y-m-d\TH:i:s.vP'),
                'sort'                         => 'date_desc',
                'limit'                        => $limit,
                'offset'                       => $offset,
            ]);

            $resultados = (is_array($respuesta) && isset($respuesta['results']) && is_array($respuesta['results']))
                ? $respuesta['results']
                : [];

            $total = (is_array($respuesta) && isset($respuesta['paging']['total']))
                ? (int) $respuesta['paging']['total']
                : count($resultados);

            foreach ($resultados as $order) {
                if (!is_array($order)) {
                    continue;
                }

                $this->store_or_update_order($order);
                $procesados++;
            }

            $offset += $limit;
        } while (count($resultados) > 0 && $offset < $total && $offset < self::MAXIMO_POR_CONSULTA);

        $this->guardar_extra_meli(['ultimo_poll_pedidos' => $inicio->toIso8601String()]);

        Log::info('meli pedidos: '.$procesados.' procesados (total en ML '.$total.') para el comercio '.$this->user_id);

        return ['procesados' => $procesados, 'total' => $total];
    }

    /**
     * Fecha mínima del pedido para generarle la venta sola: la de la conexión de la cuenta
     * (`extra_config.meli.conectado_en`). Un conector viejo sin esa fecha la toma de ahora, una
     * sola vez: los pedidos de antes de activar el módulo no se convierten en ventas solos.
     *
     * @return Carbon
     */
    protected function fecha_desde_la_que_se_crean_ventas()
    {
        $conectado_en = $this->extra_meli('conectado_en');

        if (empty($conectado_en)) {
            $conectado_en = Carbon::now()->toIso8601String();
            $this->guardar_extra_meli(['conectado_en' => $conectado_en]);
        }

        try {
            return Carbon::parse($conectado_en);
        } catch (\Throwable $e) {
            return Carbon::now();
        }
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
        $this->renglones_sin_vincular = [];
        $this->estado_anterior = null;
        $this->meli_order = null;

        try {
            DB::transaction(function () use ($order_data) {
                $this->order_data = $order_data;

                $meli_buyer = $this->create_buyer();

                $this->update_or_create_meli_order($meli_buyer);

                $this->attach_articles();

                $this->create_tags();

                $this->check_cancel();

                $this->attachSyncPivot('success');
            });
        } catch (\Exception $e) {
            $this->hubo_error = true;

            Log::error('Error al crear/actualizar orden desde MercadoLibre: '.$e->getMessage());
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

            return;
        }

        $this->despues_de_guardar_el_pedido();
    }

    /**
     * Lo que pasa con el pedido ya guardado: aviso de renglones sin vincular (solo la primera vez
     * que entra), aviso de cancelación con venta y la venta automática.
     *
     * @return void
     */
    protected function despues_de_guardar_el_pedido()
    {
        $meli_order = $this->meli_order;

        if (!$meli_order) {
            return;
        }

        $order_id = (string) $meli_order->meli_order_id;

        if (count($this->renglones_sin_vincular) > 0 && $meli_order->wasRecentlyCreated) {
            ErrorHandler::notify(
                (int) $this->user_id,
                'Entró un pedido de Mercado Libre con un artículo que no está vinculado',
                [[
                    'title'    => 'Pedido '.$order_id,
                    'parrafos' => array_merge(
                        ['El pedido quedó Pendiente: la venta no se generó sola porque estos renglones no tienen un artículo del sistema con esa publicación:'],
                        $this->renglones_sin_vincular
                    ),
                ]],
                'warning'
            );
        }

        if (
            (string) $meli_order->status === 'cancelled'
            && !is_null($this->estado_anterior)
            && $this->estado_anterior !== 'cancelled'
            && $meli_order->sale()->exists()
        ) {
            ErrorHandler::notify(
                (int) $this->user_id,
                'Se canceló en Mercado Libre un pedido que ya tenía venta',
                [[
                    'title'    => 'Pedido '.$order_id,
                    'parrafos' => ['La venta del sistema no se anula sola: revisala y, si corresponde, hacé la devolución o la nota de crédito.'],
                ]],
                'warning'
            );
        }

        if ($this->venta_automatica_prendida()) {
            $this->crear_venta_automatica($meli_order);
        }
    }

    /**
     * True si la venta automática está prendida (`MERCADO_LIBRE_CREAR_VENTA_AUTOMATICA`, o el
     * legado `CREAR_SALES_AL_DESCARGAR_MELI_ORDERS`).
     *
     * @return bool
     */
    protected function venta_automatica_prendida()
    {
        return (bool) config('services.mercadolibre.crear_venta_automatica', true)
            || (bool) config('services.mercadolibre.crear_sales_al_descargar_legacy', false);
    }

    /**
     * Genera la venta del pedido si está pago, no tiene venta, TODOS sus renglones están
     * vinculados y es posterior a la conexión de la cuenta. Bajo un candado por pedido, con el
     * re-chequeo de la venta adentro y en una transacción (la venta y el estado Confirmado van
     * juntos).
     *
     * @param MeliOrder $meli_order
     * @return bool true si creó la venta.
     */
    protected function crear_venta_automatica(MeliOrder $meli_order)
    {
        if ((string) $meli_order->status !== 'paid') {
            return false;
        }

        if (count($this->renglones_sin_vincular) > 0) {
            return false;
        }

        $cantidad_de_renglones = (isset($this->order_data['order_items']) && is_array($this->order_data['order_items']))
            ? count($this->order_data['order_items'])
            : 0;

        if ($cantidad_de_renglones === 0) {
            return false;
        }

        $fecha_del_pedido = $meli_order->meli_closed_at ?: $meli_order->meli_created_at;
        if ($fecha_del_pedido && Carbon::parse($fecha_del_pedido)->lt($this->fecha_desde_la_que_se_crean_ventas())) {
            return false;
        }

        if ($meli_order->sale()->exists()) {
            return false;
        }

        $lock = Cache::lock('meli_order_'.$meli_order->id, 30);

        if (!$lock->get()) {
            // Otro proceso (el webhook o la consulta) está creando esta misma venta.
            return false;
        }

        try {
            $meli_order->refresh();

            if ($meli_order->sale()->exists()) {
                return false;
            }

            $meli_order->load('articles');

            DB::transaction(function () use ($meli_order) {
                CreateSaleOrderHelper::save_sale($meli_order, $this->controller, false, true, $this->user);

                $meli_order->meli_order_status_id = 2;
                $meli_order->save();
            });

            Log::info('meli pedidos: venta automática creada para el pedido '.$meli_order->meli_order_id);

            return true;
        } catch (\Throwable $e) {
            Log::error('meli pedidos: no se pudo crear la venta automática del pedido '.$meli_order->meli_order_id.': '.$e->getMessage());

            ErrorHandler::notify(
                (int) $this->user_id,
                'No se pudo generar la venta de un pedido de Mercado Libre',
                [[
                    'title'    => 'Pedido '.$meli_order->meli_order_id,
                    'parrafos' => ['El pedido quedó Pendiente. Podés generar la venta a mano desde el módulo de Mercado Libre.'],
                ]],
                'warning'
            );

            return false;
        } finally {
            $lock->release();
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

        // Por id de ML Y comercio (misión mercado-libre-integracion): antes solo por id, y en una
        // base compartida la consulta de un comercio podía pisar el pedido de otro.
        $meli_order = MeliOrder::firstOrNew([
            'meli_order_id' => (string) $order_id,
            'user_id'       => $this->user_id,
        ]);

        $this->estado_anterior = $meli_order->exists ? $meli_order->status : null;

        $shipping = (isset($this->order_data['shipping']) && is_array($this->order_data['shipping']))
            ? $this->order_data['shipping']
            : [];

        $status_detail = $this->order_data['status_detail'] ?? null;

        $meli_order->fill([
            'meli_created_at' => isset($this->order_data['date_created'])
                ? Carbon::parse($this->order_data['date_created'])
                : null,
            'meli_closed_at' => isset($this->order_data['date_closed'])
                ? Carbon::parse($this->order_data['date_closed'])
                : null,
            'status' => $this->order_data['status'] ?? null,
            'status_detail' => is_scalar($status_detail) ? $status_detail : null,
            'total' => $this->order_data['total_amount'] ?? 0,
            'shipping_cost' => $this->order_data['shipping_cost'] ?? 0,
            'meli_buyer_id' => $meli_buyer ? $meli_buyer->id : null,
            'pack_id' => !empty($this->order_data['pack_id']) ? (string) $this->order_data['pack_id'] : null,
            'shipping_id' => !empty($shipping['id']) ? (string) $shipping['id'] : null,
        ]);

        // Un pedido nuevo entra Pendiente; al actualizarlo no se toca el estado interno (lo
        // manejan el comercio o la venta automática).
        if (!$meli_order->exists) {
            $meli_order->meli_order_status_id = 1;
        }

        $meli_order->save();

        $this->meli_order = $meli_order;
    }

    /**
     * Vincula artículos locales DEL COMERCIO por me_li_id con cantidades del pedido. Los renglones
     * sin artículo quedan anotados en `renglones_sin_vincular` (para el aviso y para no generar
     * una venta incompleta).
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

            $article = Article::where('me_li_id', $item['id'])
                ->where('user_id', $this->user_id)
                ->first();

            if ($article) {
                $this->meli_order->articles()->syncWithoutDetaching([
                    $article->id => [
                        'amount' => $item_data['quantity'] ?? 1,
                        'price' => $item_data['unit_price'] ?? 0,
                    ],
                ]);
            } else {
                $titulo = isset($item['title']) ? (string) $item['title'] : 'Publicación';
                $this->renglones_sin_vincular[] = '- '.$titulo.' ('.$item['id'].')';
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
