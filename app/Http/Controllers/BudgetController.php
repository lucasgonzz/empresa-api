<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Pdf\BudgetPdf;
use App\Models\Budget;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Exception;
use Illuminate\Support\Facades\Log;

class BudgetController extends Controller
{

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
                'total'                     => $request->total,
                'budget_status_id'          => $request->budget_status_id,
                'address_id'                => $request->address_id,
                'surchages_in_services'     => $request->surchages_in_services,
                'discounts_in_services'     => $request->discounts_in_services,
                'moneda_id'                 => $request->moneda_id,
                'valor_dolar'               => $request->valor_dolar,
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
            $total_budget = 1;

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

            Log::info($e);

            return response()->json(['error' => true, 'message' => $e->getMessage()], 500);
        }
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Budget', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Budget::find($id);
        $model->client_id                 = $request->client_id;
        $model->start_at                  = $request->start_at;
        $model->finish_at                 = $request->finish_at;
        $model->observations              = $request->observations;
        $model->total                     = $request->total;
        $model->budget_status_id          = $request->budget_status_id;
        $model->address_id                = $request->address_id;

        $model->surchages_in_services     = $request->surchages_in_services;
        $model->discounts_in_services     = $request->discounts_in_services;
        $model->moneda_id                 = $request->moneda_id;

        $model->save();
        GeneralHelper::attachModels($model, 'discounts', $request->discounts, ['percentage'], false);
        GeneralHelper::attachModels($model, 'surchages', $request->surchages, ['percentage'], false);
        
        $previus_articles = $model->articles;

        BudgetHelper::attachArticles($model, $request->articles, true);
        BudgetHelper::attachServices($model, $request->services);
        BudgetHelper::attachPromocionVinotecas($model, $request->promocion_vinotecas);

        BudgetHelper::checkStatus($this->fullModel('Budget', $model->id), $previus_articles);
        
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
        $pdf = new BudgetPdf($budget, $with_prices, $with_images);
    }
}
