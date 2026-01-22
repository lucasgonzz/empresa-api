<?php

namespace App\Http\Controllers\CommonLaravel;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CreditAccountHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SearchController extends Controller
{
    function search(Request $request, $model_name_param, $_filters = null, $paginate = 0) {
        $model_name = GeneralHelper::getModelName($model_name_param);
        $models = $model_name::where('user_id', $this->userId());

        $auth_user = $this->user(false);
        if (!is_null($auth_user)) {
            Log::info('------------------------');
            Log::info($this->user(false)->name.' esta haciendo un filtrado de '.$model_name_param);
        }

        if (is_null($_filters) || $_filters == 'null') {
            $filters = $request->filters;
        } else {
            $filters = $_filters;
        }

        if ($request->papelera) {
            $models = $models->whereNotNull('deleted_at')
                            ->withTrashed();
        }
        
        Log::info('filters:');
        Log::info($filters);

        foreach ($filters as $filter) {
            
            Log::info('Va con ');
            Log::info($filter);

            if (isset($filter['type'])) {

                if (isset($filter['ordenar_de'])
                && $filter['ordenar_de'] != '') {
                    $models = $models->orderBy($filter['key'], $filter['ordenar_de']);

                    Log::info('ordenando por '.$filter['key']. ' de '.$filter['ordenar_de']);
                }

                if (isset($filter['en_blanco']) && (boolean)$filter['en_blanco']) {

                    Log::info($filter['key'].' en_blanco');
                    
                    if ($filter['type'] == 'select'
                        || $filter['type'] == 'search') {

                        // $models = $models->where($filter['key'], 0);
                        // $models = $models->whereNull($filter['key'])
                        //                 ->orWhere($filter['key'], 0);

                        $models = $models->where(function ($subquery) use ($filter) {
                            $subquery->whereNull($filter['key'])
                                        ->orWhere($filter['key'], 0);
                        });


                    } else {

                        $models = $models->where(function ($subquery) use ($filter) {
                            $subquery->whereNull($filter['key'])
                                        ->orWhere($filter['key'], '');
                        });
                        // $models = $models->whereNull($filter['key']);
                    }

                    // Log::info('en_blanco para '.$filter['key']);

                } else if (isset($filter['key'])) {

                    // Log::info('Entro');
                    // Log::info($filter['type'] == 'select');
                    // Log::info(isset($filter['igual_que']));
                    // Log::info($filter['igual_que'] !== 0); 

                    $key = $filter['key'];

                    if ($key == 'num' && $model_name_param == 'article') {
                        $key = 'id';
                    }

                    if ($filter['type'] == 'number') {
                        if (isset($filter['menor_que'])
                            && $filter['menor_que'] != '') {
                            
                            $models = $models->where($key, '<', trim($filter['menor_que']));
                            Log::info('Filtrando por number '.$key.' menor_que');
                        }
                        if (isset($filter['igual_que'])
                            && $filter['igual_que'] != '') {
                            
                            $models = $models->where($key, '=', trim($filter['igual_que']));
                            Log::info('Filtrando por number '.$key.' igual');
                        }
                        if (isset($filter['mayor_que'])
                            && $filter['mayor_que'] != '') {
                            
                            $models = $models->where($key, '>', trim($filter['mayor_que']));
                            Log::info('Filtrando por number '.$key.' mayor_que');
                        }
                    } else if (($filter['type'] == 'text' || $filter['type'] == 'textarea')) {

                        if (isset($filter['igual_que'])
                            && $filter['igual_que'] != '') {

                            $models = $models->where($filter['key'], trim($filter['igual_que']));
                            Log::info('Que '.$filter['key'].' sea igual que: '.$filter['igual_que']);

                        } else if (isset($filter['que_contenga'])
                            && $filter['que_contenga'] != '') {

                            $keywords = explode(' ', $filter['que_contenga']);

                            Log::info('Que '.$filter['key'].' contenga '.$filter['que_contenga'].':');
                            foreach ($keywords as $keyword) {
                                $query = $filter['key'].' LIKE ?';
                                $models->whereRaw($query, ["%$keyword%"]);
                                Log::info('keyword: '.$keyword);
                            }


                            // $models = $models->where($filter['key'], 'like', '%'.$filter['value'].'%');
                        }
                        // Log::info('Filtrando por text '.$filter['text']);
                    } else if ($filter['type'] == 'search' 
                        && isset($filter['igual_que'])
                        && $filter['igual_que'] != 0
                        && $filter['igual_que'] != '') {
                        
                        Log::info('Filtrando por search '.$filter['key'].' igual_que '.$filter['igual_que']);

                        $models = $models->where($filter['key'], $filter['igual_que']);
                    
                    } else if ($filter['type'] == 'date' 
                        && ( 
                            (isset($filter['menor_que']) && $filter['menor_que'] != '')
                            || (isset($filter['igual_que']) && $filter['igual_que'] != '')
                            || (isset($filter['mayor_que']) && $filter['mayor_que'] != '')
                        )
                    ) {

                        if (isset($filter['menor_que'])) {

                            $models = $models->whereDate($filter['key'], '<', $filter['menor_que']);
                            Log::info('Filtrando por date '.$filter['key'].' menor_que '.$filter['menor_que']);
                        }

                        if (isset($filter['igual_que'])) {

                            $models = $models->whereDate($filter['key'], $filter['igual_que']);
                            Log::info('Filtrando por date '.$filter['key'].' igual_que '.$filter['igual_que']);
                        }

                        if (isset($filter['mayor_que'])) {

                            $models = $models->whereDate($filter['key'], '>', $filter['mayor_que']);
                            
                            Log::info('Filtrando por date '.$filter['key'].' mayor_que '.$filter['mayor_que']);
                        }

                    } else if ($filter['type'] == 'select' 
                        && isset($filter['igual_que'])
                        && $filter['igual_que'] !== 0
                    ) {
                        
                        $models = $models->where($filter['key'], $filter['igual_que']);
                        Log::info('Filtrando por select '.$filter['key'].' igual_que '.$filter['igual_que']);

                    } else if ($filter['type'] == 'checkbox' 
                        && isset($filter['checkbox'])
                        && $filter['checkbox'] != -1
                    ) {
                        
                        $models = $models->where($filter['key'], $filter['checkbox']);
                        Log::info('Filtrando por checkbox '.$filter['key'].' igual_que '.$filter['checkbox']);
                    }

                }
            }
        }

        if ($model_name_param == 'article') {
            $models = $models->where('status', 'active');
        }
        $models = $models->withAll()
                        ->orderBy('created_at', 'DESC');
        if ($paginate) {
            // $models = $models->paginate(5);
            $models = $models->paginate(50);
        } else {
            $models = $models->get();
        }

        Log::info('Resultado de la busqueda: '.count($models).' modelos');
        Log::info('-------------------');

        if (is_null($_filters)) {
            return response()->json(['models' => $models], 200);
        } else {
            return $models;
        }
    }

    function saveIfNotExist(Request $request, $_model_name, $property, $query) {
        $model_name = GeneralHelper::getModelName($_model_name);
        $data = [];
        if (substr($_model_name, strlen($_model_name)-1) == 'y') {
            $model_name_plural = substr($_model_name, 0, strlen($_model_name)-1).'ies';
        } else {
            $model_name_plural = $_model_name.'s';
        }
        // $data['num'] = $this->num($model_name_plural);
        $data['user_id'] = $this->userId();
        $data[$property] = $query;
        foreach ($request->properties_to_set as $property_to_set) {
            $data[$property_to_set['key']] = $property_to_set['value'];     
        }

        // $data[$property] = $query;
        $model = $model_name::create($data);

        if (
            $_model_name == 'client'
            || $_model_name == 'provider'
        ) {
            
            CreditAccountHelper::crear_credit_accounts($_model_name, $model->id);

        }
        
        return response()->json(['model' => $this->fullModel($_model_name, $model->id)], 201);
    }


    function searchFromModal(Request $request, $model_name_param) {
        $model_name = GeneralHelper::getModelName($model_name_param);
        $models = $model_name::where('user_id', $this->userId())
                                ->withAll();

        $models = $models->where(function ($query) use ($request, $model_name_param) {

            foreach ($request->props_to_filter as $prop_to_filter) {

                $query->orWhere(function ($subQuery) use ($prop_to_filter, $request) {
                    if ($prop_to_filter == 'num' || $prop_to_filter == 'bar_code') {
                        $subQuery->where($prop_to_filter, $request->query_value);
                        // Log::info($prop_to_filter.' igual a'.$request->query_value);
                    } else {
                        $keywords = explode(' ', $request->query_value);
                        foreach ($keywords as $keyword) {
                            $subQuery->whereRaw($prop_to_filter . ' LIKE ?', ["%$keyword%"]);
                            // Log::info($prop_to_filter.' contenga '.$keyword);
                        }
                    }
                });

            }

        });

        if (isset($request->depends_on_key)) {
            $models->where($request->depends_on_key, $request->depends_on_value);
        }

        if ($model_name_param == 'article' || $model_name_param == 'client' || $model_name_param == 'provider') {
            $models->where('status', 'active');
        }

        $models = $models->paginate(25);

        return response()->json(['models' => $models], 200);
    } 
}
