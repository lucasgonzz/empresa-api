<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use Illuminate\Http\Request;

class GeneralController extends Controller
{
    function setComercioCityUser(Request $request) {
        $model_to_attach_name = GeneralHelper::getModelName($request->model_name_to_attach);
        $model = $model_to_attach_name::where($request->prop_to_find_model, $request->prop_value);
        if ($request->model_to_attach_name != 'user') {
            $model = $model->where('user_id', $this->userId());
        }
        $model = $model->first();
        if (!is_null($model)) {
            $prop_to_set = 'comercio_city_'.$request->model_name_to_attach.'_id';
            $model_name = GeneralHelper::getModelName($request->model_name);
            $client = $model_name::find($request->model_id);
            $client->{$prop_to_set} = $model->id;
            $client->save();
            return response()->json(['user_finded' => true, 'model' => $this->fullModel($request->model_name, $client->id), 200]);
        }
        return response()->json(['user_finded' => false, 200]);
    }
}
