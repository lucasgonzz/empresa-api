<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\Order\CreateSaleOrderHelper;
use App\Models\TiendaNubeOrder;
use App\Services\TiendaNube\TiendaNubeOrderService;
use Illuminate\Http\Request;

class TiendaNubeOrderController extends Controller
{

    public function index() {

        $service = new TiendaNubeOrderService();
        $service->sincronizar_nuevos_pedidos();

        $models = TiendaNubeOrder::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    // FROM DATES
    // public function index($from_date = null, $until_date = null) {
    //     $models = TiendaNubeOrder::where('user_id', $this->userId())
    //                     ->orderBy('created_at', 'DESC')
    //                     ->withAll();
    //     if (!is_null($from_date)) {
    //         if (!is_null($until_date)) {
    //             $models = $models->whereDate('created_at', '>=', $from_date)
    //                             ->whereDate('created_at', '<=', $until_date);
    //         } else {
    //             $models = $models->whereDate('created_at', $from_date);
    //         }
    //     }

    //     $models = $models->get();
    //     return response()->json(['models' => $models], 200);
    // }

    public function show($id) {
        return response()->json(['model' => $this->fullModel('TiendaNubeOrder', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = TiendaNubeOrder::find($id);

        $previus_status = $model->tienda_nube_order_status_id;

        $model->notes                = $request->notes;
        $model->tienda_nube_order_status_id                = $request->tienda_nube_order_status_id;
        
        $model->save();

        // No se pueden actualizar cantidades porque ya fue cobrado
        // $this->update_articles($model);

        $this->confirmar_pedido($model, $previus_status);

        return response()->json(['model' => $this->fullModel('TiendaNubeOrder', $model->id)], 200);
    }

    public function destroy($id) {
        $model = TiendaNubeOrder::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('TiendaNubeOrder', $model->id);
        return response(null);
    }

    function confirmar_pedido($order, $previus_status) {
        if (
            $order->tienda_nube_order_status_id == 2
            && $previus_status == 1
        ) {

            CreateSaleOrderHelper::save_sale($order, $this, true);
        }
    }

    // function update_articles($order) {
    //     foreach ($request->articles as $article) {
    //         $order->articles()->attach($article['id'], [
    //             'amount'    => $article['pivot']['amount'],
    //         ]);
    //     }
    // }


}
