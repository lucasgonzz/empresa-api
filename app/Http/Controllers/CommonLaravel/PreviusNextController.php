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
        $index = (int) $index;

        $count = $model_name::where('user_id', UserHelper::userId())->count();

        if ($count < 1 || $index < 1) {
            return response()->json(['model' => null]);
        }

        /*
            El min() es a proposito y conserva la semantica que habia antes: la consulta anterior
            hacia take($index)->get() y se quedaba con el ultimo, asi que cuando $index superaba el
            total devolvia todos los modelos y el ultimo era el mas viejo. Topando el offset en
            $count se devuelve exactamente ese mismo modelo, pero trayendo uno solo hidratado en vez
            de miles (San Cayetano va por el remito n° 35.364 y la request se moria).
        */
        $offset = min($index, $count) - 1;

        $model = $model_name::where('user_id', UserHelper::userId())
                        ->withAll()
                        ->orderBy('id', 'DESC')
                        ->skip($offset)
                        ->take(1)
                        ->first();

        if (is_null($model)) {
            return response()->json(['model' => null]);
        }

        $this->set_sale_actualizado_por($model, $_model_name);

        return response()->json(['model' => $model]);
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
