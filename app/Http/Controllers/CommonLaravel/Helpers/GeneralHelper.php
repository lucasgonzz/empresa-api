<?php

namespace App\Http\Controllers\CommonLaravel\Helpers;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Jobs\ProcessSetFinalPrices;
use App\Models\Article;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class GeneralHelper {

    /*
    |--------------------------------------------------------------------------
    | PreviusNext
    |--------------------------------------------------------------------------
    |
    |   * El parametro index indica el numero de dias a retroceder
    |   * Direction indica si se esta subiendo o bajando, se usa en el caso
    |   de que no haya ventas en tal fecha, si se esta bajando continua bajando
    |   y viceversa
    |   * only_one_date indica si se esta retrocediendo desde una fecha en especifico
    |   Si es nulo es porque se esta retrocediendo desde el principio
    |   Si no es nulo se empieza a retroceder desde la fecha que llega en esa variable
    |   
    */

    static function previusDays($model_name, $index) {
        if ($index == 0) {
            $start = Carbon::now()->startOfWeek();
            $end = Carbon::now()->endOfWeek();
        } else {
            $start = Carbon::now()->subWeeks($index)->startOfWeek();
            $end = Carbon::now()->subWeeks($index)->endOfWeek();
        }
        $result = [];
        $index = 0;
        while ($start < $end) {
            $start_date = $start->format('Y-m-d H:i:s');
            $end_date = $start->addDay()->format('Y-m-d H:i:s');
            $models = $model_name::where('user_id', UserHelper::userId())
                            ->whereBetween('created_at', [$start_date, $end_date])
                            ->get();
            $result[$index]['date'] = $start_date;
            $result[$index]['models'] = $models;
            $index++;
        }
        return $result;
    }

    static function get_decimal_value($number) {

        $number = str_replace(',', '.', $number);
        
        $number = (float)$number;

        return $number;
    }
    
    function file_exists_2($filePath) {
        $file_headers = @get_headers($filePath);
        if(str_contains($file_headers[0], '404 Not Found')) {
            $exists = false;
        } else {
            $exists = true;
        }
        return $exists;
    }
    
    static function attachModels($model, $relation_name, $relation_models, $pivot_values = null, $from_pivot_prop = true) {
        $model->{$relation_name}()->detach();
        foreach ($relation_models as $relation_model) {
            $_pivot_values = [];
            if (!is_null($pivot_values)) {
                foreach ($pivot_values as $pivot_value) {
                    if ($from_pivot_prop) {
                        $_pivot_values[$pivot_value] = Self::getPivotValue($relation_model, $pivot_value);
                    } else {
                        $_pivot_values[$pivot_value] = $relation_model[$pivot_value];
                    }
                }
            }
            $model->{$relation_name}()->attach($relation_model['id'], $_pivot_values);
        }
    }

    static function checkNewValuesForArticlesPrices($instance, $current_value, $new_value, $from_model_id = null, $model_id = null) {
        Log::info('current_value: '.$current_value);
        Log::info('new_value: '.$new_value);
        if ($current_value != $new_value) {

            if (!is_null($from_model_id)) {
                
                ProcessSetFinalPrices::dispatch(UserHelper::userId(), $from_model_id, $model_id);

            } else {
                
                ProcessSetFinalPrices::dispatch(UserHelper::userId());
            }
            
            Log::info('entro a checkNewValuesForArticlesPrices');
            // $instance->sendUpdateModelsNotification('article', false);
        } else {
            Log::info('No hubo cambios en el proveedor');
        }
    }

    static function getPivotValue($model, $prop, $ignore_0 = false) {
        if ($ignore_0) {
            if (isset($model['pivot'][$prop]) && $model['pivot'][$prop] != 0) {
                // Log::info('retornando '.$model['pivot'][$prop].' para '.$prop);
                return $model['pivot'][$prop];
            }
            // Log::info('no se retorno valor para '.$prop);
        } else if (isset($model['pivot'][$prop])) {
            return $model['pivot'][$prop];
        }
        return null;
    }

    static function getModelName($model_name) {
        $model_name = 'App\Models\!'.ucfirst($model_name);
        $model_name = str_replace('!', '', $model_name);
        while (str_contains($model_name, '_')) {
            $pos = strpos($model_name, '_');
            $sub_str = substr($model_name, $pos+1);
            $model_name = substr($model_name, 0, $pos).ucfirst($sub_str);
        }
        while (str_contains($model_name, '-')) {
            $pos = strpos($model_name, '-');
            $sub_str = substr($model_name, $pos+1);
            $model_name = substr($model_name, 0, $pos).ucfirst($sub_str);
        }
        return $model_name;
    }

    static function getImportColumns($request) {
        $props = [];
        foreach ($request->all() as $key => $value) {
            if (str_contains($key, 'prop_')) {
                Log::info($key.': '.$value);
                if ($value != '' && $value != -1) {
                    $props[strtolower(substr($key, strpos($key, '_')+1))] = (int)$value-1;
                } 
            }
        }
        return $props;
    }

    static function getModelsFromId($model_name, $ids) {
        $models = [];
        foreach ($ids as $id) {
            $models[] = Self::getModelName($model_name)::where('id', $id)
                                                        ->withTrashed()
                                                        ->first();
        }
        return $models;
    }

    static function getRelation($model, $relation_name, $prop_name = 'name') {
        if (!is_null($model->{$relation_name})) {
            return $model->{$relation_name}->{$prop_name};
        }
        return null;
    }

}