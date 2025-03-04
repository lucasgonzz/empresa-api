<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\GeneralHelper;
use Illuminate\Http\Request;

class PapeleraController extends Controller
{
    
    function index($_model_name) {

        $model_name = GeneralHelper::getModelName($_model_name);

        $models = $model_name::where('user_id', $this->userId())
                                ->whereNotNull('deleted_at')
                                ->withAll()
                                ->withTrashed()
                                ->orderBy('deleted_at', 'DESC')
                                ->get();

        return response()->json(['models' => $models], 200);
    }

    function restaurar($_model_name, $model_id) {
        $model_name = GeneralHelper::getModelName($_model_name);

        $model = $model_name::where('id', $model_id)
                            ->withTrashed()
                            ->first();

        if ($model) {

            $model->deleted_at = null;
            $model->save();
        }
        return response(null, 200);
    }
}
