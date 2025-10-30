<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Jobs\GenerateStockSuggestionChunksJob;
use App\Models\StockSuggestion;
use App\Models\StockSuggestionArticle;
use Illuminate\Http\Request;

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
}
