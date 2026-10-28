<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\TenenciaDeLaTiendaHelper;
use App\Jobs\ProcessMeliOrderNotificationJob;
use App\Models\MeliOrder;
use App\Models\PlatformConnector;
use App\Services\MercadoLibre\ErrorHandler;
use App\Services\MercadoLibre\MercadoLibreLimiteException;
use App\Services\MercadoLibre\OrderDownloaderService;
use App\Services\MercadoLibre\ProductService;
use App\Services\MercadoLibre\VentaDelPedidoMeli;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Pedidos importados desde Mercado Libre: listado con polling, actualización y callback de notificaciones ML.
 */
class MeLiOrderController extends Controller
{
    /**
     * Lista pedidos locales y, si aplica, sincroniza órdenes pagas recientes desde la API ML.
     *
     * @param string|null $from_date
     * @param string|null $until_date
     * @return \Illuminate\Http\JsonResponse
     */
    public function index($from_date = null, $until_date = null)
    {
        // Misión mercado-libre-integracion (27/10/2026): el gate es SOLO la extensión
        // `usa_mercado_libre` del dueño (el mismo criterio que el Kernel y que encolar artículos;
        // el USA_MERCADO_LIBRE del .env ya no cuenta), y no se consulta a ML si el último poll (el
        // del comando de cada 5 minutos o el de otra apertura del listado) fue hace menos de 1 minuto.
        if (ProductService::modulo_habilitado((int) $this->userId())) {
            try {
                $connector = PlatformConnector::find_connected_mercado_libre_for_user((int) $this->userId());
                if ($connector && OrderDownloaderService::corresponde_consultar($connector, 1)) {
                    $downloader = new OrderDownloaderService($this->userId());
                    $downloader->sincronizar_pedidos_recientes();
                }
            } catch (MercadoLibreLimiteException $e) {
                // ML pidió esperar: el listado muestra lo que ya hay y la próxima consulta trae el resto.
                Log::warning('MeLiOrderController index: Mercado Libre pidió esperar: '.$e->getMessage());
            } catch (\Exception $e) {
                Log::warning('MeLiOrderController index: no se pudo sincronizar pedidos ML: '.$e->getMessage());
                ErrorHandler::notify_exception(
                    (int) $this->userId(),
                    $e,
                    'No se pudieron sincronizar pedidos desde Mercado Libre',
                    true
                );
            }
        }

        $models = MeliOrder::where('user_id', $this->userId())
            ->orderBy('created_at', 'DESC')
            ->withAll();
        if (!is_null($from_date)) {
            if (!is_null($until_date)) {
                $models = $models->whereDate('created_at', '>=', $from_date)
                    ->whereDate('created_at', '<=', $until_date);
            } else {
                $models = $models->whereDate('created_at', $from_date);
            }
        }

        $models = $models->get();

        return response()->json(['models' => $models], 200);
    }

    /**
     * Callback público de Mercado Libre (notificaciones orders_v2 / orders).
     * Responde 200 rápido y procesa el recurso en cola afterResponse.
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function receive_notification(Request $request)
    {
        $payloads = $this->normalize_meli_notification_payloads($request->all());

        foreach ($payloads as $row) {
            $topic = $row['topic'] ?? '';
            $resource = $row['resource'] ?? '';
            $meli_user_id = isset($row['user_id']) ? (string) $row['user_id'] : '';

            if ($resource === '' || $meli_user_id === '') {
                continue;
            }

            if (!in_array($topic, ['orders_v2', 'orders'], true)) {
                continue;
            }

            $connector = PlatformConnector::find_connected_mercado_libre_by_platform_user_id($meli_user_id);
            if (!$connector) {
                Log::info('receive_notification: sin conector ML para platform_user_id '.$meli_user_id);

                continue;
            }

            $resource_path = ltrim($resource, '/');

            ProcessMeliOrderNotificationJob::dispatch($connector->user_id, $resource_path)->afterResponse();
        }

        return response('', 200);
    }

    /**
     * Normaliza el cuerpo de notificación ML (objeto único o lista de eventos).
     *
     * @param array<string, mixed> $all
     * @return array<int, array<string, mixed>>
     */
    protected function normalize_meli_notification_payloads(array $all)
    {
        if ($all === []) {
            return [];
        }

        $is_list = array_keys($all) === range(0, count($all) - 1);

        if ($is_list) {
            return $all;
        }

        return [$all];
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $model = MeliOrder::create([
            'num' => $this->num('MeliOrder'),
            'name' => $request->name,
            'user_id' => $this->userId(),
        ]);
        $this->sendAddModelNotification('MeliOrder', $model->id);

        return response()->json(['model' => $this->fullModel('MeliOrder', $model->id)], 201);
    }

    /**
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $ajeno = $this->registro_ajeno_o_404($id);
        if ($ajeno) {
            return $ajeno;
        }

        return response()->json(['model' => $this->fullModel('MeliOrder', $id)], 200);
    }

    /**
     * Actualiza notas, dirección y estado interno; al pasar a Confirmado genera la venta (patrón Tienda Nube).
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $ajeno = $this->registro_ajeno_o_404($id);
        if ($ajeno) {
            return $ajeno;
        }

        $model = MeliOrder::find($id);

        $previous_status_id = $model->meli_order_status_id;

        $model->notes = $request->notes;
        $model->address_id = $request->address_id;
        $model->meli_order_status_id = $request->meli_order_status_id;
        $model->save();

        $ya_tenia_venta = $this->confirmar_pedido_meli($model, $previous_status_id);

        $this->sendAddModelNotification('MeliOrder', $model->id);

        // `ya_tenia_venta` (contrato con la SPA, Orders.vue): true si se pasó a Confirmado un pedido
        // que YA tenía venta (no se creó nada; la SPA muestra "Este pedido ya tenía venta").
        return response()->json([
            'model'          => $this->fullModel('MeliOrder', $model->id),
            'ya_tenia_venta' => $ya_tenia_venta,
        ], 200);
    }

    /**
     * Si el pedido pasa de Pendiente (1) a Confirmado (2) y está pago en ML, genera la Sale si
     * todavía no la tiene.
     *
     * @param MeliOrder $order
     * @param int|null $previous_status_id
     * @return bool true si era el paso a Confirmado y el pedido YA tenía venta (no se creó nada).
     */
    protected function confirmar_pedido_meli(MeliOrder $order, $previous_status_id)
    {
        $es_confirmacion = (int) $order->meli_order_status_id === 2
            && ($previous_status_id === null || (int) $previous_status_id === 1)
            && $order->status === 'paid';

        if (!$es_confirmacion) {
            return false;
        }

        // Con candado y re-chequeo (misión mercado-libre-integracion): la venta automática o el
        // botón "Crear venta" pueden estar creando la misma venta en este momento.
        $creada = VentaDelPedidoMeli::generar_si_falta($order, $this);

        return !$creada && $order->sale()->exists();
    }

    /**
     * @param int $id
     * @return \Illuminate\Http\Response|null
     */
    public function destroy($id)
    {
        $ajeno = $this->registro_ajeno_o_404($id);
        if ($ajeno) {
            return $ajeno;
        }

        $model = MeliOrder::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('MeliOrder', $model->id);

        return response(null);
    }

    /**
     * Genera la venta local manualmente para un pedido ML.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function create_sale($id)
    {
        $ajeno = $this->registro_ajeno_o_404($id);
        if ($ajeno) {
            return $ajeno;
        }

        $meli_order = MeliOrder::find($id);

        // Un pedido cancelado, cancelándose o inválido en Mercado Libre no lleva venta: el botón la
        // creaba igual y descontaba el stock de algo que no se vendió (misión mercado-libre-pruebas,
        // 27/10/2026). Si ya tenía venta de antes, se devuelve como está (ver abajo).
        if ($this->sin_venta_posible($meli_order)) {
            return $this->respuesta_sin_venta($meli_order);
        }

        // Si ya tiene venta (la automática, o un confirmar desde otra pantalla) no se crea otra:
        // se devuelve el pedido como está con `ya_tenia_venta: true` (misión
        // mercado-libre-integracion; la SPA lo lee en Orders.vue).
        $creada = VentaDelPedidoMeli::generar_si_falta($meli_order, $this);

        // Carrera: la consulta periódica lo canceló mientras tanto (el servicio lo re-chequea con la
        // fila tomada y no crea la venta). Se contesta lo mismo que si hubiera llegado cancelado.
        if (!$creada && $this->sin_venta_posible($meli_order->fresh())) {
            return $this->respuesta_sin_venta($meli_order->fresh());
        }

        return response()->json([
            'model'          => $this->fullModel('MeliOrder', $id),
            'ya_tenia_venta' => !$creada && $meli_order->sale()->exists(),
        ], 200);
    }

    /**
     * true si el pedido está en un estado de Mercado Libre que no lleva venta y todavía no la tiene.
     *
     * @param MeliOrder $meli_order
     * @return bool
     */
    protected function sin_venta_posible(MeliOrder $meli_order)
    {
        return in_array((string) $meli_order->status, VentaDelPedidoMeli::ESTADOS_SIN_VENTA, true)
            && !$meli_order->sale()->exists();
    }

    /**
     * El 422 de "Crear venta" para un pedido que no la admite, con el motivo en castellano (la SPA
     * muestra el `message` tal cual).
     *
     * @param MeliOrder $meli_order
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respuesta_sin_venta(MeliOrder $meli_order)
    {
        $motivos = [
            'cancelled'      => 'está cancelado',
            'pending_cancel' => 'se está cancelando',
            'invalid'        => 'figura como inválido',
        ];
        $motivo = isset($motivos[$meli_order->status]) ? $motivos[$meli_order->status] : 'no admite venta';

        return response()->json([
            'message' => 'Este pedido '.$motivo.' en Mercado Libre: no se le crea venta.',
        ], 422);
    }

    /**
     * El pedido de Mercado Libre tiene que ser del comercio de la sesión.
     *
     * Devuelve null si es del dueño de la sesión y, si no, el 404 que el método contesta tal cual
     * (ajeno, inexistente y no-id dan lo mismo). Ver `TenenciaDeLaTiendaHelper`.
     *
     * @param  mixed  $id  Lo que llegó en la ruta.
     * @return \Illuminate\Http\JsonResponse|null
     */
    protected function registro_ajeno_o_404($id)
    {
        return TenenciaDeLaTiendaHelper::respuesta_si_no_es_del_dueno(MeliOrder::class, $id, $this->userId(), 'El pedido de Mercado Libre no existe o no es de tu cuenta.');
    }
}
