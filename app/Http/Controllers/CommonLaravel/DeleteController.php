<?php

namespace App\Http\Controllers\CommonLaravel;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\CommonLaravel\SearchController;
use App\Http\Controllers\Controller;
use App\Services\Filter\FilterHistoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DeleteController extends Controller
{
    function delete(Request $request, $model_name) {
        $models = [];
        $formated_model_name = GeneralHelper::getModelName($model_name);

        $used_filters = [];
        
        if ($request->from_filter) {
            $search_ct = new SearchController();
            $res = $search_ct->search($request, $model_name, $request->filter_form, 0, true);
            $models = $res['models'];
            $used_filters = $res['used_filters'];
            // foreach($models as $model) {
            //     Log::info($model->name);
            // }
        } else {
            foreach ($request->models_id as $id) {
                $models[] = $formated_model_name::find($id);
            }
            // foreach($models as $model) {
            //     Log::info($model->name);
            // }
        }
        $models_response = [];
        
        $send_notification = true;
        if (count($models) > 300) {
            $send_notification = false;
        }

        $eliminados = 0;
        foreach ($models as $model) {
            $controller_name = 'App\\Http\\Controllers\\'.explode('\\', $formated_model_name)[2].'Controller';
            $controller = new $controller_name();

            Log::info(Auth()->user()->name.' va a eliminar '.$model_name.' id: '.$model->id);

            if ($model->name) {
                Log::info('Nombre: '.$model->name);
            }
            
            if ($model_name == 'article') {
                $controller->destroy($model->id, $send_notification);
            } else {
                $controller->destroy($model->id);
            }

            $eliminados++;
            
        }


        if ($model_name == 'article') {
            FilterHistoryService::log_action([
                'user_id'             => $this->userId(true),
                'auth_user_id'        => $this->userId(false),
                'action'              => 'eliminacion',
                'model_name'          => 'article',
                'filtrados_count'     => count($models),
                'afectados_count'     => $eliminados,
                'used_filters'        => $used_filters,
            ]);
        }
        return response()->json(['models' => $models], 200);
    }
}
