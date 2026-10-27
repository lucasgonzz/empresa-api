<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\Order\CreateSaleOrderHelper;
use App\Http\Controllers\Helpers\TenenciaDeLaTiendaHelper;
use App\Jobs\ProcessMeliOrderNotificationJob;
use App\Models\MeliOrder;
use App\Models\PlatformConnector;
use App\Services\MercadoLibre\ErrorHandler;
use App\Services\MercadoLibre\MercadoLibreLimiteException;
use App\Services\MercadoLibre\OrderDownloaderService;
use App\Services\MercadoLibre\ProductService;
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
        // Misión mercado-libre-integracion (27/10/2026): el gate es la extensión `usa_mercado_libre`
        // del dueño (o el USA_MERCADO_LIBRE viejo), y no se consulta a ML si el último poll (el del
        // comando de cada 5 minutos o el de otra apertura del listado) fue hace menos de 1 minuto.
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

        $this->confirmar_pedido_meli($model, $previous_status_id);

        $this->sendAddModelNotification('MeliOrder', $model->id);

        return response()->json(['model' => $this->fullModel('MeliOrder', $model->id)], 200);
    }

    /**
     * Si el pedido pasa de Pendiente (1) a Confirmado (2), está pago en ML y aún no tiene venta, genera la Sale.
     *
     * @param MeliOrder $order
     * @param int|null $previous_status_id
     * @return void
     */
    protected function confirmar_pedido_meli(MeliOrder $order, $previous_status_id)
    {
        $order->load('sale');

        if (
            (int) $order->meli_order_status_id === 2
            && ($previous_status_id === null || (int) $previous_status_id === 1)
            && $order->status === 'paid'
            && !$order->sale
        ) {
            CreateSaleOrderHelper::save_sale($order, $this, false, true);
        }
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

        CreateSaleOrderHelper::save_sale($meli_order, $this, false, true);

        return response()->json(['model' => $this->fullModel('MeliOrder', $id)], 200);
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
