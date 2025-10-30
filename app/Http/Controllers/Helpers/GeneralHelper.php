<?php

namespace App\Http\Controllers\Helpers;

use App\Article;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\UserHelper;
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

    static function checkNewValuesForArticlesPrices($current_value, $new_value, $from_model_id = null, $model_id = null) {
        if ($current_value != $new_value) {
            if (!is_null($from_model_id)) {
                $articles = Article::where($from_model_id, $model_id)
                                    ->get();
                foreach ($articles as $article) {
                    ArticleHelper::setFinalPrice($article);
                }
            } else {
                ArticleHelper::setArticlesFinalPrice();
            }
        }
    }

    static function getPivotValue($model, $prop, $ignore_0 = false) {
        if ($ignore_0) {
            if (isset($model['pivot'][$prop]) && $model['pivot'][$prop] != 0) {
                Log::info('retornando '.$model['pivot'][$prop].' para '.$prop);
                return $model['pivot'][$prop];
            }
            Log::info('no se retorno valor para '.$prop);
        } else if (isset($model['pivot'][$prop])) {
            return $model['pivot'][$prop];
        }
        return null;
    }

    static function getModelName($model_name) {
        $model_name = 'App\Models\/'.ucfirst($model_name);
        $model_name = str_replace('/', '', $model_name);
        if (str_contains($model_name, '_')) {
            $pos = strpos($model_name, '_');
            $sub_str = substr($model_name, $pos+1);
            $model_name = substr($model_name, 0, $pos).ucfirst($sub_str);
        }
        if (str_contains($model_name, '-')) {
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


    static function article_name($article) {
        $name = $article->name;
        if (!is_null($article->pivot->variant_description)) {
            $name .= ' '.$article->pivot->variant_description;
        }
        return $name;
    }

    static function getJpgImage($image_url) {


        if (!is_null($image_url)) {
            $array = explode('/', $image_url); 
            $array_name = end($array);
            $name = explode('.', $array_name)[0];
            $extension = strtolower(pathinfo($array_name, PATHINFO_EXTENSION));

            if (env('APP_ENV') == 'local') {
                // $jpg_file_url = storage_path('app/' . $name . '.jpg');
                $jpg_file_url = storage_path('app/public/' . $name . '.jpg');
            } else {
                $jpg_file_url = storage_path('app/public/' . $name . '.jpg');
            }


            // Convertir a JPG si la imagen es WEBP
            if ($extension === 'webp') {
                if (!file_exists($jpg_file_url)) {
                    try {
                        $image = imagecreatefromwebp($image_url);
                        if ($image !== false) {
                            imagejpeg($image, $jpg_file_url, 100);
                            imagedestroy($image);
                        } else {
                            throw new \Exception("No se pudo crear la imagen desde WEBP.");
                        }
                    } catch (\Exception $e) {
                        return $image_url; // Devuelve la URL original si falla la conversión
                    }
                }
                return $jpg_file_url;
            }

            // Si la imagen ya es JPG, simplemente devuelve la URL
            if (Self::isJpg($image_url)) {
                return $image_url;
            }
        }
        return $image_url;
    }

    function isJpg($file) {
        // Verifica que el archivo exista
        if (!file_exists($file)) {
            return false;
        }
        
        // Obtiene información sobre la imagen
        $imageInfo = getimagesize($file);
        
        // Verifica si el tipo MIME es 'image/jpeg'
        if ($imageInfo && $imageInfo['mime'] == 'image/jpeg') {
            return true;
        }
        
        return false;
    }

}