<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\ArticlePreImportRange;
use Illuminate\Http\Request;

class ArticlePreImportRangeController extends Controller
{

    public function index() {
        $models = ArticlePreImportRange::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = ArticlePreImportRange::create([
            // 'name'                  => $request->name,
            'min'                   => $request->min,
            'max'                   => $request->max,
            'color'                 => $request->color,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('ArticlePreImportRange', $model->id);
        return response()->json(['model' => $this->fullModel('ArticlePreImportRange', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ArticlePreImportRange', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = ArticlePreImportRange::find($id);
        $model->min                 = $request->min;
        $model->max                 = $request->max;
        $model->color               = $request->color;
        $model->save();
        $this->sendAddModelNotification('ArticlePreImportRange', $model->id);
        return response()->json(['model' => $this->fullModel('ArticlePreImportRange', $model->id)], 200);
    }

    public function destroy($id) {
        $model = ArticlePreImportRange::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('ArticlePreImportRange', $model->id);
        return response(null);
    }
}
