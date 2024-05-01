<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\ProductionMovementHelper;
use App\Models\OrderProductionStatus;
use App\Models\ProductionMovement;
use App\Models\Recipe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProductionMovementController extends Controller
{

    function currentAmountsAllArticles() {
        $recipes = Recipe::where('user_id', $this->userId())
                            ->get();

        $cantidades_actuales = [];
        foreach ($recipes as $recipe) {
            if (!is_null($recipe->article)) {
                $article = [
                    'name'  => $recipe->article->name,
                ];
                $article['cantidades_actuales'] = $this->currentAmounts($recipe->article_id, false);
                $cantidades_actuales[] = $article;
            }
        }
        return response()->json(['cantidades_actuales' => $cantidades_actuales], 200);
    }

    function currentAmounts($article_id, $return_response = true) {
        $response = [];
        $order_production_statuses = OrderProductionStatus::where('user_id', $this->userId())
                                                            ->whereNotNull('position')
                                                            ->orderBy('position', 'ASC')
                                                            ->get();
        foreach ($order_production_statuses as $order_production_status) {
            $model = ProductionMovement::where('article_id', $article_id)
                                    ->where('order_production_status_id', $order_production_status->id)
                                    ->orderBy('created_at', 'DESC')
                                    ->first();
            if (!is_null($model)) {
                $response[] = [
                    'order_production_status'   => $order_production_status,
                    'current_amount'            => $model->current_amount,
                ];
            }
        }

        if (!$return_response) {
            return $response;
        }
        return response()->json(['response' => $response], 200);
    }

    public function index($from_date = null, $until_date = null) {
        $models = ProductionMovement::where('user_id', $this->userId())
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
        $model = ProductionMovement::create([
            'num'                           => $this->num('production_movements'),
            'employee_id'                   => $request->employee_id,
            'article_id'                    => $request->article_id,
            'order_production_status_id'    => $request->order_production_status_id,
            'amount'                        => $request->amount,
            'current_amount'                => $request->amount,
            'notes'                         => $request->notes,
            'user_id'                       => $this->userId(),
        ]);
        ProductionMovementHelper::checkRecipe($model, $this);
        ProductionMovementHelper::setCurrentAmount($model, $this);
        $this->sendAddModelNotification('production_movement', $model->id);
        ProductionMovementHelper::checkArticleAddresses($model, $this);
        return response()->json(['model' => $this->fullModel('ProductionMovement', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ProductionMovement', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = ProductionMovement::find($id);
        // $last_amount = $model->amount;
        // $model->employee_id                   = $request->employee_id;
        // $model->article_id                    = $request->article_id;
        // $model->order_production_status_id    = $request->order_production_status_id;
        // // $model->address_id                    = $request->address_id;
        // $model->amount                        = $request->amount;
        // $model->save();
        // ProductionMovementHelper::checkRecipe($model, $this, $last_amount);
        // ProductionMovementHelper::setCurrentAmount($model, $this, $last_amount);
        // $this->sendAddModelNotification('production_movement', $model->id);
        return response()->json(['model' => $this->fullModel('ProductionMovement', $model->id)], 200);
    }

    public function destroy($id) {
        $model = ProductionMovement::find($id);
        ProductionMovementHelper::checkRecipe($model, $this, 0, true);
        ProductionMovementHelper::delete($model, $this);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('production_movement', $model->id);
        return response(null);
    }
}
