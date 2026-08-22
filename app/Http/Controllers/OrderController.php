<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\LimiteCreditoHelper;
use App\Http\Controllers\Helpers\MessageHelper;
use App\Http\Controllers\Helpers\OrderHelper;
use App\Http\Controllers\Helpers\Order\CreateSaleOrderHelper;
use App\Http\Controllers\Pdf\OrderPdf;
use App\Models\Order;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        /**
         * Todo el update va en una transacción (prompt 610).
         *
         * El chequeo de límite de crédito no puede correr antes de escribir: el estado nuevo y —si
         * la request los trae— los renglones y el total se resuelven acá adentro, y el total es
         * justamente el número contra el que hay que comparar. Así que corre abajo, con el pedido
         * ya actualizado, y un rechazo hace `DB::rollBack()` explícito para no dejar el pedido
         * confirmado sin su venta. Mismo patrón que `SaleController::update()`.
         */
        DB::beginTransaction();

        try {

            /**
             * Este endpoint acepta payloads PARCIALES: cada campo se toca solo si la request lo trae.
             *
             * 🔴 No lo simplifiques a las asignaciones directas de antes. Tiene dos consumidores con
             * payloads distintos: el formulario del pedido manda el modelo entero (con `articles` y
             * `address_id`), y el boton "Confirmar pedido" (`BtnStatus.vue`) manda unicamente
             * `order_status_id`. Con las asignaciones incondicionales, ese boton dejaba `address_id`
             * en null y —peor— `GeneralHelper::attachModels()` arranca con un `detach()`
             * incondicional (GeneralHelper.php:103), asi que el pedido perdia TODOS sus renglones y
             * la venta que nace mas abajo salia vacia, sin descontar stock.
             *
             * `order_status_id` entra en la misma regla y no por simetria: `orders.order_status_id` es
             * NOT NULL, asi que un payload que no lo traiga rompia con un QueryException 500.
             *
             * Se pregunta por `is_null()` y no por `has()` por el mismo motivo que los renglones usan
             * `is_array()`: `has()` da true tambien cuando la clave viene con valor null, que es
             * justo el caso que la columna no acepta. Una guarda que deja pasar lo que dice frenar no
             * es una guarda.
             */
            if (!is_null($request->order_status_id)) {
                $model->order_status_id = $request->order_status_id;
            }

            if ($request->has('address_id')) {
                $model->address_id = $request->address_id;
            }

            $model->save();

            /**
             * Los renglones se re-adjuntan —y el total se recalcula— SOLO cuando la request los trae.
             *
             * Se pregunta por `is_array()` y no por `has()`: `has()` da true tambien para
             * `articles: null`, y ahi el `detach()` de attachModels ya borro todo antes de que el
             * `foreach(null)` tire el error. La guarda tiene que impedir que se entre, no avisar
             * despues.
             *
             * 🔴 Y el recalculo del total va adentro del if, no afuera. Si nadie toco los renglones no
             * hay nada que recalcular: escribir `total` igual es pisar un dato que lo puso otro (el
             * pedido nace del lado de la tienda) con el resultado de una cuenta propia. Para un pedido
             * de la tienda hoy los dos numeros coinciden —`orders.total` guarda el subtotal de
             * articulos, misma cuenta que `OrderHelper::get_total()`; ver el docblock de
             * `OrderTotalsHelper` en tienda-api—, pero eso es una coincidencia de formulas, no un
             * contrato, y no es motivo para reescribir el campo en cada cambio de estado.
             */
            if (is_array($request->articles)) {

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
                /**
                 * Límite de crédito del cliente al confirmar el pedido (prompt 610).
                 *
                 * Hasta este prompt el límite sólo se miraba en `SaleController::store()`/`update()`.
                 * La venta de un pedido la crea `CreateSaleOrderHelper` sin pasar por ahí, así que un
                 * cliente al tope de su límite no podía comprar en el mostrador y sí desde la tienda.
                 *
                 * 🔴 Va ANTES de `save_sale()` y no adentro. `SaleHelper::attachProperies()` descuenta
                 * stock (`attachArticles`) antes de llegar a `create_current_acount()`: un chequeo
                 * más abajo dejaría el stock ido. Y va en el controller y no en el helper porque los
                 * caminos de Tienda Nube y Mercado Libre también pasan por `save_sale()` y no tienen
                 * a quién devolverle un 422 (`OrderDownloaderService` corre sin request).
                 */
                $error_limite_credito = $this->chequear_limite_credito_del_pedido($model, $request);

                if (!is_null($error_limite_credito)) {

                    DB::rollBack();
                    return response()->json($error_limite_credito, 422);
                }

                CreateSaleOrderHelper::save_sale($model, $this);
            }

            DB::commit();

        } catch (\Throwable $e) {

            DB::rollBack();

            // Igual que en SaleController::store(): como la excepción se captura acá, el handler
            // global no la ve y hay que empujarla a mano para que quede registrada.
            report($e);

            throw $e;
        }

        $this->sendAddModelNotification('Order', $model->id);
        return response()->json(['model' => $this->fullModel('Order', $model->id)], 200);
    }

    /**
     * Cuerpo del 422 del límite de crédito, o null si el pedido se puede confirmar.
     *
     * 🔴 El aviso es SALTEABLE (decisión de Lucas, 22/8/2026): con `ignorar_limite_credito` en el
     * request, el dueño confirma igual. Es la diferencia con el mostrador, donde el mismo límite
     * es freno duro (misión 160): allá hay un vendedor con el cliente adelante y se corrige en el
     * momento; acá el pedido ya lo mandó el comprador y el que confirma está mirando una tanda.
     *
     * Los tres valores se derivan con los MISMOS helpers que `CreateSaleOrderHelper::createSale()`
     * va a usar un instante después, para que el chequeo no mida una cosa y se guarde otra.
     *
     * @param  \App\Models\Order  $model  Pedido ya pasado al estado nuevo.
     * @param  \Illuminate\Http\Request  $request
     * @return array|null
     */
    private function chequear_limite_credito_del_pedido($model, $request) {

        if ($request->ignorar_limite_credito) {
            return null;
        }

        // El pedido puede quedar en un estado que igual no crea venta (por ejemplo con
        // `save_sale_after_finish_order` apagado): ahí no hay nada que chequear.
        if (!CreateSaleOrderHelper::va_a_crear_venta($model)) {
            return null;
        }

        return LimiteCreditoHelper::validar_pedido_confirmado(
            CreateSaleOrderHelper::get_client_id($model, false, false),
            CreateSaleOrderHelper::get_to_check(),
            $model->total
        );
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
