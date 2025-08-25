<?php

namespace App\Http\Controllers\CommonLaravel;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\UserHelper;
use Illuminate\Http\Request;

class PreviusNextController extends Controller
{

    function previusNext($_model_name, $index) {
        $model_name = GeneralHelper::getModelName($_model_name);
        $models = $model_name::where('user_id', UserHelper::userId())
                        ->withAll()
                        ->orderBy('id', 'DESC')
                        ->take($index)
                        ->get();
        if (count($models) >= 1) {
            $model = $models[count($models)-1];

            $this->set_sale_actualizado_por($model, $_model_name);
            
            return response()->json(['model' => $model]);

        }
        return response()->json(['model' => null]);
    }

    function set_sale_actualizado_por($model, $_model_name) {
        if ($_model_name == 'sale') {
            $model->actualizandose_por_id = $this->userId(false);
            $model->timestamps = false;
            $model->save();
        }
    }

    function getIndexPreviusNext($_model_name, $id) {
        $model_name = GeneralHelper::getModelName($_model_name);
        $model = $model_name::find($id);

        // if (!$this->check_sale_actualizado_por($model, $_model_name)) {

        //     return response()->json(['actualizandose_por' => $model->actualizandose_por]);

        // } 

        $models = $model_name::where('user_id', UserHelper::userId())
                                ->where('id', '>=', $model->id)
                                ->pluck('id');
        return response()->json(['index' => count($models)], 200);
    }

    function check_sale_actualizado_por($model, $_model_name) {
        if ($_model_name == 'sale') {
            if (
                !is_null($model->actualizandose_por_id)
                && $model->actualizandose_por_id != $this->userId(false)
            ) {
                return false;                
            }
        }
        return true;                
    }

}
