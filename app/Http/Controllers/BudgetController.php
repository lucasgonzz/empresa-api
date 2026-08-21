<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\Budget\BudgetDuplicarHelper;
use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Pdf\BudgetPdf;
use App\Models\Budget;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Exception;
use Illuminate\Support\Facades\Log;

class BudgetController extends Controller
{

    /**
     * Estados de presupuesto, de `BudgetStatusSeeder`: se siembran en este orden y la tabla
     * `budget_statuses` es GLOBAL, no por usuario, y de solo lectura (`BudgetStatusController` solo
     * expone `index()`). O sea que los ids son fijos y no los puede inventar un cliente.
     *
     * Se nombran acá porque el 1 y el 2 ya estaban hardcodeados en la SPA (`Budget.vue`
     * `show_btn_save`, `BtnActualizarEnVender` `:disabled`) y en el back no tenían nombre.
     *
     * ⚠️ `BudgetHelper::checkStatus()` compara por NOMBRE (`budget_status->name == 'Confirmado'`),
     * no por id. Son dos formas de preguntar lo mismo; si alguna vez se renombra un estado hay que
     * tocar los dos lados.
     */
    const ESTADO_SIN_CONFIRMAR = 1;
    const ESTADO_CONFIRMADO    = 2;

    public function index($from_date = null, $until_date = null) {
        $models = Budget::where('user_id', $this->userId())
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

    public function store(Request $request) {

        DB::beginTransaction();

        try {

            $model = Budget::create([
                'num'                       => $this->num('budgets'),
                'client_id'                 => $request->client_id,
                'start_at'                  => $request->start_at,
                'finish_at'                 => $request->finish_at,
                'observations'              => $request->observations,
                'price_type_id'             => $request->price_type_id,
                'sale_status_id'            => $request->sale_status_id,
                'discount_stock'            => !is_null($request->discount_stock) ? $request->discount_stock : 1,
                'iva_aplicado'              => !is_null($request->iva_aplicado) ? $request->iva_aplicado : 1,
                'total'                     => $request->total,
                'budget_status_id'          => $request->budget_status_id,
                'address_id'                => $request->address_id,
                'surchages_in_services'     => $request->surchages_in_services,
                'discounts_in_services'     => $request->discounts_in_services,
                'moneda_id'                 => $request->moneda_id,
                'valor_dolar'               => $request->valor_dolar,
                // 'omitir_en_cuenta_corriente'        => $request->omitir_en_cuenta_corriente,
                'employee_id'               => $this->userId(false),
                'user_id'                   => $this->userId(),
            ]);
            GeneralHelper::attachModels($model, 'discounts', $request->discounts, ['percentage'], false);
            GeneralHelper::attachModels($model, 'surchages', $request->surchages, ['percentage'], false);

            $previus_articles = $model->articles;

            BudgetHelper::attachArticles($model, $request->articles);

            BudgetHelper::attachServices($model, $request->services);
            BudgetHelper::attachPromocionVinotecas($model, $request->promocion_vinotecas);

            BudgetHelper::checkStatus($this->fullModel('Budget', $model->id), $previus_articles);

            $this->sendAddModelNotification('Budget', $model->id);



            $total_helper = (int)BudgetHelper::getTotal($model);
            $total_budget = (int)$model->total;

            // Calcula la diferencia absoluta
            $diferencia = abs($total_helper - $total_budget);

            if ($diferencia > 3) {
                Log::info('Total mal para presupuesto '.$model->id);
                Log::info('total_helper: '.$total_helper);
                Log::info('total_budget: '.$total_budget);

                $message = 'El total del presupuesto no corresponde con los productos ingresados';
                
                throw new Exception($message);
            }

            DB::commit();

            return response()->json(['model' => $this->fullModel('Budget', $model->id)], 201);

        } catch(\Throwable $e) {

            DB::rollBack();

            // El reporter de errores esta enganchado al handler global (Handler::register ->
            // reportable), que Laravel solo invoca para excepciones NO manejadas. Como esta la
            // capturamos nosotros para poder hacer rollback y responder 500, hay que empujarla a
            // mano con report(): sin esta linea el fallo no llega a errores/ y no existe para
            // nadie. Ya paso: dos actualizaciones de venta de Fenix que murieron por lock wait
            // timeout el 7/8/2026 no dejaron rastro fuera de este archivo de log.
            report($e);

            return response()->json(['error' => true, 'message' => $e->getMessage()], 500);
        }
    }  

    /**
     * Duplica un presupuesto existente (mismo usuario) en uno nuevo en estado sin confirmar.
     *
     * @param int|string $id Identificador del presupuesto origen.
     * @return \Illuminate\Http\JsonResponse Modelo creado o error 500.
     */
    public function duplicate($id) {

        if (!UserHelper::hasExtencion('duplicar_presupuestos')) {
            return response()->json(['error' => true, 'message' => 'No autorizado'], 403);
        }

        DB::beginTransaction();

        try {

            /** Origen con el mismo criterio de carga que el listado / alta. */
            $source = Budget::withAll()->find($id);
            if (is_null($source)) {
                throw new Exception('Presupuesto no encontrado');
            }

            $model = BudgetDuplicarHelper::duplicate($source, $this);

            $this->sendAddModelNotification('Budget', $model->id);

            $total_helper = (int) BudgetHelper::getTotal($model);
            $total_budget = (int) $model->total;

            $diferencia = abs($total_helper - $total_budget);

            if ($diferencia > 3) {
                Log::info('Total mal para presupuesto duplicado '.$model->id);
                Log::info('total_helper: '.$total_helper);
                Log::info('total_budget: '.$total_budget);

                throw new Exception('El total del presupuesto no corresponde con los productos ingresados');
            }

            DB::commit();

            return response()->json(['model' => $this->fullModel('Budget', $model->id)], 201);

        } catch (\Throwable $e) {

            DB::rollBack();

            // El reporter de errores esta enganchado al handler global (Handler::register ->
            // reportable), que Laravel solo invoca para excepciones NO manejadas. Como esta la
            // capturamos nosotros para poder hacer rollback y responder 500, hay que empujarla a
            // mano con report(): sin esta linea el fallo no llega a errores/ y no existe para
            // nadie. Ya paso: dos actualizaciones de venta de Fenix que murieron por lock wait
            // timeout el 7/8/2026 no dejaron rastro fuera de este archivo de log.
            report($e);

            return response()->json(['error' => true, 'message' => $e->getMessage()], 500);
        }
    }

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Budget', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Budget::find($id);

        if (is_null($model)) {
            return response()->json(['message' => 'El presupuesto no existe.'], 404);
        }

        /*
            Un presupuesto confirmado no se edita: primero se anula.

            La interfaz ya lo impedia por dos lados (Budget.vue esconde el boton de guardar cuando
            budget_status_id == 2, y BtnActualizarEnVender esta disabled para ese estado), pero el
            endpoint quedaba alcanzable con la sesion abierta. Y llegar aca con un presupuesto
            confirmado significaba borrar y recrear su venta, incluso si ya estaba facturada, porque
            deleteSale() llama a SaleController::destroy() y ese metodo no chequea el AfipTicket.
            Mismo criterio que SaleHelper::motivo_por_el_que_no_se_puede_editar(): la decision vive
            en el back, no solo en el front.
        */
        if ($model->budget_status_id == Self::ESTADO_CONFIRMADO) {

            return response()->json([
                'message' => 'El presupuesto esta confirmado. Anulalo antes de editarlo.',
            ], 422);
        }

        /*
            Se lee el estado GUARDADO antes de pisarlo con el del request: es lo que despues permite
            saber si el estado cambio de verdad en este update.
        */
        $estado_anterior = $model->budget_status_id;

        $model->client_id                 = $request->client_id;
        $model->start_at                  = $request->start_at;
        $model->finish_at                 = $request->finish_at;
        $model->observations              = $request->observations;
        $model->total                     = $request->total;
        $model->budget_status_id          = $request->budget_status_id;
        $model->address_id                = $request->address_id;
        // $model->omitir_en_cuenta_corriente                = $request->omitir_en_cuenta_corriente;

        $model->surchages_in_services     = $request->surchages_in_services;
        $model->discounts_in_services     = $request->discounts_in_services;
        $model->moneda_id                 = $request->moneda_id;
        $model->sale_status_id            = $request->sale_status_id;
        $model->discount_stock            = !is_null($request->discount_stock) ? $request->discount_stock : $model->discount_stock;
        $model->iva_aplicado              = !is_null($request->iva_aplicado) ? $request->iva_aplicado : $model->iva_aplicado;

        $model->save();
        GeneralHelper::attachModels($model, 'discounts', $request->discounts, ['percentage'], false);
        GeneralHelper::attachModels($model, 'surchages', $request->surchages, ['percentage'], false);
        
        $previus_articles = $model->articles;

        BudgetHelper::attachArticles($model, $request->articles, true);
        BudgetHelper::attachServices($model, $request->services);
        BudgetHelper::attachPromocionVinotecas($model, $request->promocion_vinotecas);

        /*
            🔴 checkStatus() SOLO si el estado cambio de verdad.

            Hasta el 21/8/2026 esto se llamaba en cada update, y checkStatus() arranca siempre por
            deleteCurrentAcount() + deleteSale() antes de mirar el estado. O sea que guardar un
            presupuesto confirmado —aunque no se le tocara nada— borraba su venta y creaba una
            nueva CON NUMERO NUEVO (saveSale usa $ct->num('sales')), devolviendo y volviendo a
            descontar el stock y rehaciendo el movimiento de cuenta corriente.

            El guard del estado confirmado de mas arriba ya corta el caso peor. Esta condicion cubre
            el resto: un update que no toca el estado no tiene por que pasar por el borrado.

            Por que condicional y no sacarlo del todo (decision de Lucas, 21/8/2026): asi una SPA
            todavia no actualizada, que confirma cambiando el select y guardando, sigue creando la
            venta igual. El orden de despliegue entre api y spa deja de importar.
        */
        if ($estado_anterior != $model->budget_status_id) {

            BudgetHelper::checkStatus($this->fullModel('Budget', $model->id), $previus_articles);
        }

        $this->sendAddModelNotification('Budget', $model->id);
        return response()->json(['model' => $this->fullModel('Budget', $model->id)], 200);
    }

    /**
     * Confirma un presupuesto y le crea la venta.
     *
     * Reemplaza al gesto viejo de cambiar el select de estado y guardar el presupuesto entero
     * (pedido de Lucas en el audio 2.6 del volcado y en prompt_161). La diferencia no es solo de
     * comodidad: por el camino de `update()` la confirmacion viajaba adentro de un guardado
     * completo, asi que confirmar y editar eran el mismo request y no se podian distinguir.
     *
     * Idempotente a proposito: confirmar dos veces no crea dos ventas.
     *
     * @param  int|string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function confirmar($id) {

        $model = Budget::find($id);

        if (is_null($model)) {
            return response()->json(['message' => 'El presupuesto no existe.'], 404);
        }

        /*
            Sin withTrashed: una venta borrada por una anulacion anterior no cuenta como venta
            existente, justamente para que se pueda volver a confirmar despues de anular.
        */
        $venta_existente = Sale::where('budget_id', $model->id)->first();

        if ($model->budget_status_id == Self::ESTADO_CONFIRMADO && !is_null($venta_existente)) {

            return response()->json(['model' => $this->fullModel('Budget', $model->id)], 200);
        }

        $model->budget_status_id = Self::ESTADO_CONFIRMADO;
        $model->save();

        /*
            saveSale() tiene su propio guard `is_null($budget->sale)`, asi que un presupuesto que
            quedo confirmado sin venta (estado inconsistente) se repara al confirmarlo de nuevo.

            El segundo parametro es $previus_articles, que checkStatus/saveSale/attachSaleArticles
            se pasan entre si y ninguno lee. Se manda la coleccion actual para no cambiar la firma,
            que la usan tambien store() y update().
        */
        BudgetHelper::saveSale($this->fullModel('Budget', $model->id), $model->articles);

        $this->sendAddModelNotification('Budget', $model->id);

        return response()->json(['model' => $this->fullModel('Budget', $model->id)], 200);
    }

    /**
     * Anula un presupuesto confirmado: le borra la venta y lo devuelve a "Sin confirmar".
     *
     * 🔴 Antes de esto NO habia forma de desconfirmar un presupuesto desde la interfaz. El select de
     * estado solo se puede cambiar guardando, y `Budget.vue` esconde el boton de guardar cuando el
     * presupuesto esta confirmado: un confirmado era un callejon sin salida.
     *
     * @param  int|string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function anular($id) {

        $model = Budget::find($id);

        if (is_null($model)) {
            return response()->json(['message' => 'El presupuesto no existe.'], 404);
        }

        if ($model->budget_status_id != Self::ESTADO_CONFIRMADO) {

            return response()->json(['message' => 'El presupuesto no esta confirmado.'], 422);
        }

        $sale = Sale::where('budget_id', $model->id)->first();

        /*
            🔴 Se valida ANTES de tocar nada. Un 422 no puede dejar el presupuesto desconfirmado con
            la venta viva: quedarian los dos estados peleados.

            Se pregunta por la regla completa y no solo por la factura (decision de Lucas,
            21/8/2026). El motivo es que BudgetHelper::deleteSale() llama a
            SaleController::destroy() con un Request vacio, o sea con compensar_caja en false: si la
            venta ya movio una caja, borrarla descuadra el arqueo sin dejar movimiento compensatorio.
            La regla de motivo_por_el_que_no_se_puede_editar() ya cubre ese caso y varios mas
            (facturada, cerrada, mas de un metodo de pago), y esta centralizada desde el 3/8/2026
            justamente para no duplicarla en cada controlador.
        */
        if (!is_null($sale)) {

            $motivo = SaleHelper::motivo_por_el_que_no_se_puede_editar($sale);

            if (!is_null($motivo)) {

                return response()->json(['message' => $motivo], 422);
            }

            BudgetHelper::deleteCurrentAcount($model);
            BudgetHelper::deleteSale($model);
        }

        $model->budget_status_id = Self::ESTADO_SIN_CONFIRMAR;
        $model->save();

        $this->sendAddModelNotification('Budget', $model->id);

        return response()->json(['model' => $this->fullModel('Budget', $model->id)], 200);
    }

    public function destroy($id) {

        $model = Budget::find($id);

        // Quito esto porque los presupuestos confirmados no se pueden eliminar, y los sin confirmar no impactan en la cuenta corriente
        // if (BudgetHelper::deleteCurrentAcount($model)) {
            
        //     CurrentAcountHelper::checkSaldos($model->credit_account_id);
        //     SaleHelper::deleteSaleFrom('budget', $model->id, $this);
        //     $this->sendAddModelNotification('client', $model->client_id, false);
        // }

        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Budget', $model->id);
        return response(null);
    }

    function pdf($id, $with_prices, $with_images) {
        $budget = Budget::find($id);

        if (is_null($budget)) {
            abort(404);
        }

        $pdf = new BudgetPdf($budget, $with_prices, $with_images);
    }
}
