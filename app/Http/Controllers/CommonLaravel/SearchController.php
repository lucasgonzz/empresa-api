<?php

namespace App\Http\Controllers\CommonLaravel;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\ArticleHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SearchController extends Controller
{
    function search(Request $request, $model_name_param, $_filters = null, $paginate = 0) {
        $model_name = GeneralHelper::getModelName($model_name_param);
        $models = $model_name::where('user_id', $this->userId());
        if (is_null($_filters) || $_filters == 'null') {
            $filters = $request->filters;
        } else {
            $filters = $_filters;
        }
        foreach ($filters as $filter) {
            if (isset($filter['type'])) {
                if (isset($filter['en_blanco']) && (boolean)$filter['en_blanco']) {

                    $models = $models->whereNull($filter['key']);

                } else {

                    if ($filter['type'] == 'number') {
                        if ($filter['number_type'] == 'min' && $filter['value'] != '') {
                            $models = $models->where($filter['key'], '<', $filter['value']);
                            Log::info('Filtrando por number '.$filter['text'].' min');
                        }
                        if ($filter['number_type'] == 'equal' && $filter['value'] != '') {
                            $models = $models->where($filter['key'], '=', $filter['value']);
                            Log::info('Filtrando por number '.$filter['text'].' igual');
                        }
                        if ($filter['number_type'] == 'max' && $filter['value'] != '') {
                            $models = $models->where($filter['key'], '>', $filter['value']);
                            Log::info('Filtrando por number '.$filter['text'].' max');
                        }
                    } else if (($filter['type'] == 'text' || $filter['type'] == 'textarea') && $filter['value'] != '') {
                        if ($filter['key'] == 'bar_code' || $filter['key'] == 'provider_code') {
                            $models = $models->where($filter['key'], $filter['value']);
                        } else {

                            $keywords = explode(' ', $filter['value']);

                            foreach ($keywords as $keyword) {
                                $query = $filter['key'].' LIKE ?';
                                $models->whereRaw($query, ["%$keyword%"]);
                            }

                            // $models = $models->where($filter['key'], 'like', '%'.$filter['value'].'%');
                        }
                        Log::info('Filtrando por text '.$filter['text']);
                    } else if ($filter['type'] == 'search' && $filter['value'] != 0) {
                        $models = $models->where($filter['key'], $filter['value']);
                        Log::info('Filtrando por text '.$filter['text'].' value = '.$filter['value']);
                    } else if ($filter['type'] == 'boolean' && $filter['value'] != -1) {
                        $models = $models->where($filter['key'], $filter['value']);
                        Log::info('Filtrando por boolean '.$filter['text']);
                    } else if ($filter['type'] != 'boolean' && $filter['value'] != 0) {
                        $models = $models->where($filter['key'], $filter['value']);
                        Log::info('Filtrando por value '.$filter['text']);
                    }
                }
            }
        }
        if ($model_name_param == 'article' || $model_name_param == 'client' || $model_name_param == 'provider') {
            $models = $models->where('status', 'active');
        }
        $models = $models->withAll()
                        ->orderBy('created_at', 'DESC');
        if ($paginate) {
            $models = $models->paginate(100);
        } else {
            $models = $models->get();
        }
        // if ($model_name_param == 'article') {
        //     $models = ArticleHelper::setPrices($models);
        // }
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
        $data['num'] = $this->num($model_name_plural);
        $data['user_id'] = $this->userId();
        $data[$property] = $query;
        foreach ($request->properties_to_set as $property_to_set) {
            $data[$property_to_set['key']] = $property_to_set['value'];     
        }
        // $data[$property] = $query;
        $model = $model_name::create($data);
        return response()->json(['model' => $this->fullModel($_model_name, $model->id)], 201);
    }


    function searchFromModal(Request $request, $model_name_param) {
        $model_name = GeneralHelper::getModelName($model_name_param);
        $models = $model_name::where('user_id', $this->userId())
                                ->withAll();

        $models = $models->where(function ($query) use ($request, $model_name_param) {
            foreach ($request->props_to_filter as $prop_to_filter) {

                $query->orWhere(function ($subQuery) use ($prop_to_filter, $request) {
                    if ($prop_to_filter == 'bar_code' || $prop_to_filter == 'provider_code') {
                        $subQuery->where($prop_to_filter, $request->query_value);
                    } else {
                        $keywords = explode(' ', $request->query_value);
                        foreach ($keywords as $keyword) {
                            $subQuery->orWhereRaw($prop_to_filter . ' LIKE ?', ["%$keyword%"]);
                        }
                    }
                });

            }

            if (isset($request->depends_on_key)) {
                $query->where($request->depends_on_key, $request->depends_on_value);
            }

            if ($model_name_param == 'article' || $model_name_param == 'client' || $model_name_param == 'provider') {
                $query->where('status', 'active');
            }
        });

        $models = $models->get();


        // foreach ($request->props_to_filter as $prop_to_filter) {

        //     if ($prop_to_filter['key'] == 'bar_code' || $prop_to_filter['key'] == 'provider_code') {

        //         $models = $models->where($prop_to_filter['key'], $request->query_value);

        //     } else {

        //         $keywords = explode(' ', $request->query_value);

        //         foreach ($keywords as $keyword) {
        //             $query = $prop_to_filter['key'].' LIKE ?';
        //             $models->whereRaw($query, ["%$keyword%"]);
        //         }

        //         // $models = $models->where($prop_to_filter['key'], 'like', '%'.$request->query_value.'%');
        //     }
        //     if (isset($request->depends_on_key)) {
        //         $models = $models->where($request->depends_on_key, $request->depends_on_value);
        //     }
        //     if ($model_name_param == 'article' || $model_name_param == 'client' || $model_name_param == 'provider') {
        //         $models = $models->where('status', 'active');
        //     }

        // }

        // $models = $models->get();
        
        return response()->json(['models' => $models], 200);
    } 
}
