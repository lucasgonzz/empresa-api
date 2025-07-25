<?php

namespace App\Http\Controllers\CommonLaravel;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\SearchController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\ArticleHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UpdateController extends Controller
{
    function update(Request $request, $model_name) {
        $models = [];
        $formated_model_name = GeneralHelper::getModelName($model_name);
        if ($request->from_filter) {
            $search_ct = new SearchController();
            $models = $search_ct->search($request, $model_name, $request->filter_form);
        } else {
            foreach ($request->models_id as $id) {
                $models[] = $formated_model_name::find($id);
            }
        }

        $models_response = [];
        if (count($models) < 3000) {

            foreach ($models as $model) {
                foreach ($request->update_form as $form) {
                    if ($form['type'] == 'number' && str_contains($form['key'], 'decrement') && $form['value'] != '') {
                        $value = $model->{substr($form['key'], 10)} * (float)$form['value'] / 100;
                        $model->{substr($form['key'], 10)} -= $value;
                        if ($form['round']) {
                            $model->{substr($form['key'], 10)} = round($model->{substr($form['key'], 10)}, 0, PHP_ROUND_HALF_UP);
                        }
                        $model->save();
                        // Log::info('Se disminuyo '.substr($form['key'], 10).' de '.$model->name.', quedo en '.$model->{substr($form['key'], 10)});
                    } else if ($form['type'] == 'number' && str_contains($form['key'], 'increment') && $form['value'] != '') {
                        $value = $model->{substr($form['key'], 10)} * (float)$form['value'] / 100;
                        $model->{substr($form['key'], 10)} += $value; 
                        if ($form['round']) {
                            $model->{substr($form['key'], 10)} = round($model->{substr($form['key'], 10)}, 0, PHP_ROUND_HALF_UP);
                        }
                        $model->save();
                        // Log::info('Se aumento '.substr($form['key'], 10).' de '.$model->name.', quedo en '.$model->{substr($form['key'], 10)});
                    } else if ($form['type'] == 'number' && str_contains($form['key'], 'set_') && $form['value'] != '') {
                        $model->{substr($form['key'], 4)} = (float)$form['value'];
                        $model->save();
                        Log::info('Se seteo '.substr($form['key'], 4).' de '.$model->name.', quedo en '.$model->{substr($form['key'], 4)});
                    } else if ($form['type'] == 'search' && str_contains($form['key'], '_id') && $form['value'] != '' && $form['value'] != 0) {
                        $model->{$form['key']} = $form['value'];
                        $model->save();
                        // Log::info('Se seteo '.$form['key'].' de '.$model->name.', quedo en '.$model->{$form['key']});
                    } else if ($form['type'] == 'select' && str_contains($form['key'], '_id') && $form['value'] != '' && $form['value'] != 0) {
                        $model->{$form['key']} = $form['value'];
                        $model->save();
                        // Log::info('Se seteo '.$form['key'].' de '.$model->name.', quedo en '.$model->{$form['key']});
                    }
                }
                if ($model_name == 'article') {
                    ArticleHelper::setFinalPrice($model);
                }
                $models_response[] = $this->fullModel($model_name, $model->id);
            }
            Log::info('se actualizaron '.count($models).' '.$model_name.' desde updateController');
        } else {
            Log::info('NO se permitio actualizar los '.count($models).' '.$model_name);
        }
        return response()->json(['models' => $models_response], 200);
    }
}
