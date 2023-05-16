<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Models\CurrentAcount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PagadoPorController extends Controller
{
    function index($model_name, $model_id, $debe_id, $haber_id) {
        CurrentAcountHelper::checkPagos($model_name, $model_id);
        $models = DB::table('pagado_por')
                    ->orderBy('created_at', 'ASC');
        if ($debe_id != 0) {
            $models = $models->where('debe_id', $debe_id);
        } else if ($haber_id) {
            $models = $models->where('haber_id', $haber_id);
        }
        $models = $models->get();
        $models = $this->setModels($models);
        return response()->json(['models' => $models], 200);
    }

    function setModels($models) {
        foreach ($models as $model) {
            $model->debe = CurrentAcount::find($model->debe_id);
            $model->haber = CurrentAcount::find($model->haber_id);
        }
        return $models;
    }
}
