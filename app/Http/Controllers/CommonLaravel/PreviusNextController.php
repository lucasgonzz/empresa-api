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

    /**
     * Devuelve un modelo por su id, scopeado por usuario.
     *
     * Existe porque abrir una venta guardada para editarla se hacia con dos requests y ninguna
     * usaba el id: primero se pedia la POSICION de la venta en el listado ordenado por id DESC y
     * despues la venta que estaba en esa posicion. Ese segundo paso hace
     * `withAll()->take($index)->get()`, o sea que para abrir una venta vieja en un comercio con
     * 35 mil ventas hidrataba miles de ventas completas en una sola request.
     *
     * Marca `actualizandose_por_id` igual que previusNext(), que es lo que sostiene el bloqueo de
     * edicion. NO devuelve `actualizandose_por`: ese chequeo esta inerte desde hace tiempo (el
     * codigo que lo devolvia esta comentado en getIndexPreviusNext) y activarlo ahora empezaria a
     * bloquear ventas que no esta editando nadie, por los valores viejos que quedaron en
     * produccion.
     *
     * @param  string  $_model_name
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    function byId($_model_name, $id) {
        $model_name = GeneralHelper::getModelName($_model_name);

        $model = $model_name::where('user_id', UserHelper::userId())
                        ->where('id', $id)
                        ->withAll()
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
