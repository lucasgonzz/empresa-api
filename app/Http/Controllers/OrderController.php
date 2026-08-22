<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\MessageHelper;
use App\Http\Controllers\Helpers\OrderHelper;
use App\Http\Controllers\Helpers\Order\CreateSaleOrderHelper;
use App\Http\Controllers\Pdf\OrderPdf;
use App\Models\Order;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{

    public function index($from_date = null, $until_date = null) {
        $models = Order::where('user_id', $this->userId())
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
        $models = OrderHelper::setArticlesVariant($models);
        return response()->json(['models' => $models], 200);
    }

    function indexUnconfirmed() {
        $models = Order::where('user_id', $this->userId())
                        ->where('order_status_id', 1)
                        ->orderBy('created_at', 'DESC')
                        ->withAll()
                        ->get();
        // $models = OrderHelper::setArticlesVariant($models);
        return response()->json(['models' => $models], 200);
    }

    function cancel(Request $request, $id) {
        $model = Order::find($id);
        $model->order_status_id = $this->getModelBy('order_statuses', 'name', 'Cancelado', false, 'id');
        $model->save();
        OrderHelper::restartArticleStock($model);
        // MessageHelper::sendOrderCanceledMessage($request->description, $model);
        return response()->json(['model' => $this->fullModel('Order', $model->id)], 200);
    }

    public function show($id) {
        $model = $this->fullModel('Order', $id);
        $model = OrderHelper::setArticlesVariant([$model])[0];
        return response()->json(['model' => $model], 200);
    }

    public function update(Request $request, $id) {
        /**
         * Pedido a actualizar.
         * Se usa como base para evaluar transición de estado y creación de venta.
         */
        $model = Order::find($id);

        /**
         * Estado previo del pedido antes de persistir cambios.
         */
        $prev_status = $model->order_status;

        $model->order_status_id = $request->order_status_id;

        /**
         * `address_id` y `articles` se tocan SOLO si la request los trae.
         *
         * 🔴 No lo simplifiques a la asignacion directa de antes. Este endpoint tiene dos
         * consumidores con payloads distintos: el formulario del pedido manda el modelo entero
         * (incluidos `articles` y `address_id`), y el boton "Confirmar pedido"
         * (`BtnStatus.vue`) manda unicamente `order_status_id`. Con la asignacion incondicional,
         * el boton dejaba `address_id` en null y —peor— `GeneralHelper::attachModels()` arranca
         * con un `detach()` incondicional (GeneralHelper.php:103), asi que el pedido perdia
         * todos sus renglones y la venta que nace despues salia vacia y sin descontar stock.
         */
        if ($request->has('address_id')) {
            $model->address_id = $request->address_id;
        }

        $model->save();

        /**
         * El total se recalcula SOLO cuando se tocaron los renglones.
         *
         * 🔴 Tampoco lo saques de adentro del if. `OrderHelper::get_total()` suma los pivotes de
         * `articles` y `promocion_vinotecas` e ignora cupones y descuentos/recargos de metodo de
         * pago, que si estan contemplados en el total con el que el pedido nace del lado de la
         * tienda. Recalcularlo cuando nadie toco los renglones pisaria ese total con otro numero.
         */
        if ($request->has('articles')) {

            GeneralHelper::attachModels($model, 'articles', $request->articles, ['price', 'amount']);

            $model->total = OrderHelper::get_total($model);
            $model->save();
        }

        /**
         * Estado nuevo del pedido luego del update.
         * Se carga para validar regla de transición y evitar crear venta al cancelar.
         */
        $model->load('order_status');

        /**
         * Indica si ya existe una venta vinculada al pedido.
         * Evita duplicados cuando el usuario vuelve a pasar por estados posteriores.
         */
        $has_sale = Sale::where('order_id', $model->id)->exists();

        Log::info('prev_status: '.$prev_status->name);
        Log::info('order_status: '.$model->order_status->name);
        Log::info('has_sale: '.$has_sale);

        /**
         * Solo crear venta en la primera transición desde "Sin confirmar"
         * hacia un estado distinto de "Cancelado" y sin venta previa.
         */
        if (
            $prev_status
            && $prev_status->name == 'Sin confirmar'
            && $model->order_status
            && $model->order_status->name != 'Cancelado'
            && !$has_sale
        ) {
            CreateSaleOrderHelper::save_sale($model, $this);
        }

        $this->sendAddModelNotification('Order', $model->id);
        return response()->json(['model' => $this->fullModel('Order', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Order::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Order', $model->id);
        return response(null);
    }

    function pdf($id) {
        $model = Order::find($id);

        if (is_null($model)) {
            abort(404);
        }

        new OrderPdf($model);
    }
}
