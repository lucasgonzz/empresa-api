<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\ArticlePdfObservation;
use Illuminate\Http\Request;

class ArticlePdfObservationController extends Controller
{

    public function index() {
        $models = ArticlePdfObservation::where('user_id', $this->userId())
                            ->orderBy('position', 'ASC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = ArticlePdfObservation::create([
            'text'                  => $request->text,
            'color'                  => $request->color,
            'background'                  => $request->background,
            'position'                  => $request->position,
            'user_id'               => $this->userId(),
        ]);
        return response()->json(['model' => $this->fullModel('ArticlePdfObservation', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ArticlePdfObservation', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = ArticlePdfObservation::find($id);
        $model->position                = $request->position;
        $model->text                = $request->text;
        $model->color                = $request->color;
        $model->background                = $request->background;
        $model->save();
        return response()->json(['model' => $this->fullModel('ArticlePdfObservation', $model->id)], 200);
    }

    public function destroy($id) {
        $model = ArticlePdfObservation::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('ArticlePdfObservation', $model->id);
        return response(null);
    }
}
