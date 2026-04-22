<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\DepositMovementHelper;
use App\Jobs\GenerateStockSuggestionChunksJob;
use App\Models\DepositMovement;
use App\Models\StockSuggestion;
use App\Models\StockSuggestionArticle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StockSuggestionController extends Controller
{

    public function index() {
        $models = StockSuggestion::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = StockSuggestion::create([
            'modo'                  => $request->modo,
            'origen'                => $request->origen,
            'limite_origen'         => $request->limite_origen,
            'status'                => 'pendiente',
            'user_id'               => $this->userId(),
        ]);

        dispatch(new GenerateStockSuggestionChunksJob($model->id));

        return response()->json(['model' => $this->fullModel('StockSuggestion', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('StockSuggestion', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = StockSuggestion::find($id);
        $model->modo                = $request->modo;
        $model->origen              = $request->origen;
        $model->limite_origen       = $request->limite_origen;
        $model->save();
        return response()->json(['model' => $this->fullModel('StockSuggestion', $model->id)], 200);
    }

    public function destroy($id) {
        $model = StockSuggestion::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('StockSuggestion', $model->id);
        return response(null);
    }

    public function create_deposit_movement(Request $request, $id) {
        Log::info('create_deposit_movement request:', $request->all());
        $article_ids = $request->input('article_ids', []);

        if (empty($article_ids)) {
            return response()->json(['message' => 'No se enviaron artículos.'], 422);
        }

        $suggestion_articles = StockSuggestionArticle::where('stock_suggestion_id', $id)
            ->whereIn('article_id', $article_ids)
            ->get();

        // Agrupa los artículos por par único from_address_id / to_address_id
        $groups = [];
        foreach ($suggestion_articles as $sa) {
            $key = $sa->from_address_id . '_' . $sa->to_address_id;
            $groups[$key][] = $sa;
        }

        $created_movements = [];

        foreach ($groups as $key => $group_articles) {
            $first = $group_articles[0];

            $deposit_movement = DepositMovement::create([
                'num'                        => $this->num('deposit_movements'),
                'from_address_id'            => $first->from_address_id,
                'to_address_id'              => $first->to_address_id,
                'deposit_movement_status_id' => 1,
                'stock_suggestion_id'        => $id,
                'user_id'                    => $this->userId(),
            ]);

            // Formatea los artículos en el formato que espera DepositMovementHelper
            $articles_to_attach = array_map(function ($sa) {
                return [
                    'id'    => $sa->article_id,
                    'pivot' => [
                        'amount'             => $sa->suggested_amount,
                        'article_variant_id' => null,
                    ],
                ];
            }, $group_articles);

            $helper = new DepositMovementHelper($deposit_movement);
            $helper->attach_articles($articles_to_attach);
            $helper->check_status();

            $this->sendAddModelNotification('DepositMovement', $deposit_movement->id);

            $created_movements[] = $this->fullModel('DepositMovement', $deposit_movement->id);
        }

        return response()->json(['deposit_movements' => $created_movements], 201);
    }
}
