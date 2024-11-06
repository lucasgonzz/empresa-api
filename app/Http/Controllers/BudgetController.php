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
        $model = Budget::create([
            'num'                       => $this->num('budgets'),
            'client_id'                 => $request->client_id,
            'start_at'                  => $request->start_at,
            'finish_at'                 => $request->finish_at,
            'observations'              => $request->observations,
            'total'                     => $request->total,
            'budget_status_id'          => $request->budget_status_id,
            'user_id'                   => $this->userId(),
        ]);
        GeneralHelper::attachModels($model, 'discounts', $request->discounts, ['percentage'], false);
        GeneralHelper::attachModels($model, 'surchages', $request->surchages, ['percentage'], false);

        $previus_articles = $model->articles;

        BudgetHelper::attachArticles($model, $request->articles);
        BudgetHelper::checkStatus($this->fullModel('Budget', $model->id), $previus_articles);

        $this->sendAddModelNotification('Budget', $model->id);
        return response()->json(['model' => $this->fullModel('Budget', $model->id)], 201);
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
        $model->save();
        GeneralHelper::attachModels($model, 'discounts', $request->discounts, ['percentage'], false);
        GeneralHelper::attachModels($model, 'surchages', $request->surchages, ['percentage'], false);
        
        $previus_articles = $model->articles;

        BudgetHelper::attachArticles($model, $request->articles);
        BudgetHelper::checkStatus($this->fullModel('Budget', $model->id), $previus_articles);
        
        $this->sendAddModelNotification('Budget', $model->id);
        return response()->json(['model' => $this->fullModel('Budget', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Budget::find($id);
        if (BudgetHelper::deleteCurrentAcount($model)) {
            CurrentAcountHelper::checkSaldos('client', $model->client_id);
            SaleHelper::deleteSaleFrom('budget', $model->id, $this);
            $this->sendAddModelNotification('client', $model->client_id, false);
        }
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Budget', $model->id);
        return response(null);
    }

    function pdf($id, $with_prices) {
        $budget = Budget::find($id);
        $pdf = new BudgetPdf($budget, $with_prices);
    }
}
