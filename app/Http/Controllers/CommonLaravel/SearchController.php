<?php

namespace App\Http\Controllers\CommonLaravel;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\sale\SaleArticlesEagerLoadHelper;
use App\Services\Filter\FilterHistoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SearchController extends Controller
{
    /**
     * @param bool $return_raw_models Si true (solo uso interno), devuelve la colección/paginador sin armar JSON ni log de historial.
     */
    function search(Request $request, $model_name_param, $_filters = null, $paginate = 0, $return_used_filters = false, $return_raw_models = false) {
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
        
        // Log::info('filters:');
        // Log::info($filters);

        $used_filters = [];

        foreach ($filters as $filter) {
            
            // Log::info('Va con ');
            // Log::info($filter);

            if (isset($filter['type'])) {

                if (isset($filter['ordenar_de'])
                && $filter['ordenar_de'] != '') {
                    $models = $models->orderBy($filter['key'], $filter['ordenar_de']);

                    // Log::info('ordenando por '.$filter['key']. ' de '.$filter['ordenar_de']);

                    $used_filters[] = [
                        'key'       => $filter['key'],
                        'operator'  => 'order_by',
                        'value'     => $filter['ordenar_de'],
                        'type'      => $filter['type'],
                    ];
                }

                if (isset($filter['en_blanco']) && (boolean)$filter['en_blanco']) {

                    // Log::info($filter['key'].' en_blanco');
                    
                    if ($filter['type'] == 'select'
                        || $filter['type'] == 'search') {

                        // $models = $models->where($filter['key'], 0);
                        // $models = $models->whereNull($filter['key'])
                        //                 ->orWhere($filter['key'], 0);

                        $models = $models->where(function ($subquery) use ($filter) {
                            $subquery->whereNull($filter['key'])
                                        ->orWhere($filter['key'], 0);
                        });


                        $used_filters[] = [
                            'key'       => $filter['key'],
                            'operator'  => 'en_blanco',
                            'value'     => true,
                            'type'      => $filter['type'],
                        ];


                    } else if ($filter['type'] == 'date') {

                        // Fechas vacías en BD: normalmente NULL (no cadena vacía).
                        $models = $models->whereNull($filter['key']);

                        $used_filters[] = [
                            'key'       => $filter['key'],
                            'operator'  => 'en_blanco',
                            'value'     => true,
                            'type'      => $filter['type'],
                        ];

                    } else {

                        $models = $models->where(function ($subquery) use ($filter) {
                            $subquery->whereNull($filter['key'])
                                        ->orWhere($filter['key'], '');
                        });

                        $used_filters[] = [
                            'key'       => $filter['key'],
                            'operator'  => 'en_blanco',
                            'value'     => true,
                            'type'      => $filter['type'],
                        ];
                    }

                } else if (isset($filter['no_en_blanco']) && (boolean)$filter['no_en_blanco']) {

                    if ($filter['type'] == 'select'
                        || $filter['type'] == 'search') {

                        $models = $models->where(function ($subquery) use ($filter) {
                            $subquery->whereNotNull($filter['key'])
                                        ->where($filter['key'], '!=', 0);
                        });

                        $used_filters[] = [
                            'key'       => $filter['key'],
                            'operator'  => 'no_en_blanco',
                            'value'     => true,
                            'type'      => $filter['type'],
                        ];

                    } else if ($filter['type'] == 'date') {

                        // Inverso de en_blanco en date: columna con fecha cargada (NOT NULL).
                        $models = $models->whereNotNull($filter['key']);

                        $used_filters[] = [
                            'key'       => $filter['key'],
                            'operator'  => 'no_en_blanco',
                            'value'     => true,
                            'type'      => $filter['type'],
                        ];

                    } else {

                        $models = $models->where(function ($subquery) use ($filter) {
                            $subquery->whereNotNull($filter['key'])
                                        ->where($filter['key'], '!=', '');
                            if ($filter['type'] == 'number') {
                                $subquery->where($filter['key'], '!=', 0);
                            }
                        });

                        $used_filters[] = [
                            'key'       => $filter['key'],
                            'operator'  => 'no_en_blanco',
                            'value'     => true,
                            'type'      => $filter['type'],
                        ];
                    }

                } else if (isset($filter['key'])) {

                    // Log::info('Entro');
                    // Log::info($filter['type'] == 'select');
                    // Log::info(isset($filter['igual_que']));
                    // Log::info($filter['igual_que'] !== 0); 

                    $key = $filter['key'];

                    if ($key == 'num' && $model_name_param == 'article') {
                        $key = 'id';
                    }

                    /**
                     * Ventas: filtro por N° de comprobante en afip_tickets (relación hasMany).
                     * No aplica sobre columna de sales; usa whereHas en afip_tickets.cbte_numero.
                     */
                    if ($filter['type'] == 'afip_ticket_cbte_numero'
                        && $model_name_param == 'sale'
                        && isset($filter['que_contenga'])
                        && trim($filter['que_contenga']) != '') {

                        $cbte_numero_search = trim($filter['que_contenga']);
                        $models = $models->whereHas('afip_tickets', function ($q) use ($cbte_numero_search) {
                            $q->where('cbte_numero', 'like', '%' . $cbte_numero_search . '%');
                        });

                        $used_filters[] = [
                            'key'       => $filter['key'],
                            'operator'  => 'que_contenga',
                            'value'     => $filter['que_contenga'],
                            'type'      => $filter['type'],
                        ];
                    } else if ($filter['type'] == 'number') {
                        if (isset($filter['menor_que'])
                            && $filter['menor_que'] != '') {
                            
                            $models = $models->where($key, '<', trim($filter['menor_que']));
                            Log::info('Filtrando por number '.$key.' menor_que');

                            $used_filters[] = [
                                'key'       => $filter['key'],
                                'operator'  => 'menor_que',
                                'value'     => $filter['menor_que'],
                                'type'      => $filter['type'],
                            ];
                        }
                        if (isset($filter['igual_que'])
                            && $filter['igual_que'] != '') {
                            
                            $models = $models->where($key, '=', trim($filter['igual_que']));
                            // Log::info('Filtrando por number '.$key.' igual');


                            $used_filters[] = [
                                'key'       => $filter['key'],
                                'operator'  => 'igual_que',
                                'value'     => $filter['igual_que'],
                                'type'      => $filter['type'],
                            ];
                        }
                        if (isset($filter['mayor_que'])
                            && $filter['mayor_que'] != '') {
                            
                            $models = $models->where($key, '>', trim($filter['mayor_que']));
                            // Log::info('Filtrando por number '.$key.' mayor_que');


                            $used_filters[] = [
                                'key'       => $filter['key'],
                                'operator'  => 'mayor_que',
                                'value'     => $filter['mayor_que'],
                                'type'      => $filter['type'],
                            ];
                        }
                    } else if (($filter['type'] == 'text' || $filter['type'] == 'textarea')) {

                        if (isset($filter['igual_que'])
                            && $filter['igual_que'] != '') {

                            $models = $models->where($filter['key'], trim($filter['igual_que']));
                            // Log::info('Que '.$filter['key'].' sea igual que: '.$filter['igual_que']);


                            $used_filters[] = [
                                'key'       => $filter['key'],
                                'operator'  => 'igual_que',
                                'value'     => $filter['igual_que'],
                                'type'      => $filter['type'],
                            ];

                        } else if (isset($filter['que_contenga'])
                            && $filter['que_contenga'] != '') {

                            $keywords = explode(' ', $filter['que_contenga']);

                            // Log::info('Que '.$filter['key'].' contenga '.$filter['que_contenga'].':');
                            foreach ($keywords as $keyword) {
                                $query = $filter['key'].' LIKE ?';
                                $models->whereRaw($query, ["%$keyword%"]);
                                // Log::info('keyword: '.$keyword);
                            }


                            $used_filters[] = [
                                'key'       => $filter['key'],
                                'operator'  => 'que_contenga',
                                'value'     => $filter['que_contenga'],
                                'type'      => $filter['type'],
                            ];


                            // $models = $models->where($filter['key'], 'like', '%'.$filter['value'].'%');
                        }
                        // Log::info('Filtrando por text '.$filter['text']);
                    } else if ($filter['type'] == 'search' 
                        && isset($filter['igual_que'])
                        && $filter['igual_que'] != 0
                        && $filter['igual_que'] != '') {
                        
                        // Log::info('Filtrando por search '.$filter['key'].' igual_que '.$filter['igual_que']);

                        $models = $models->where($filter['key'], $filter['igual_que']);

                        $used_filters[] = [
                            'key'       => $filter['key'],
                            'operator'  => 'igual_que',
                            'value'     => $filter['igual_que'],
                            'type'      => $filter['type'],
                        ];
                    
                    } else if ($filter['type'] == 'date' 
                        && ( 
                            (isset($filter['menor_que']) && $filter['menor_que'] != '')
                            || (isset($filter['igual_que']) && $filter['igual_que'] != '')
                            || (isset($filter['mayor_que']) && $filter['mayor_que'] != '')
                        )
                    ) {

                        if (isset($filter['menor_que']) && trim($filter['menor_que']) != '') {

                            $models = $this->apply_date_filter_operator(
                                $models,
                                $filter['key'],
                                '<',
                                $filter['menor_que']
                            );

                            $used_filters[] = [
                                'key'       => $filter['key'],
                                'operator'  => 'menor_que',
                                'value'     => $filter['menor_que'],
                                'type'      => $filter['type'],
                            ];
                        }

                        if (isset($filter['igual_que']) && trim($filter['igual_que']) != '') {

                            $models = $this->apply_date_filter_operator(
                                $models,
                                $filter['key'],
                                '=',
                                $filter['igual_que']
                            );
                            
                            $used_filters[] = [
                                'key'       => $filter['key'],
                                'operator'  => 'igual_que',
                                'value'     => $filter['igual_que'],
                                'type'      => $filter['type'],
                            ];
                        }

                        if (isset($filter['mayor_que']) && trim($filter['mayor_que']) != '') {

                            $models = $this->apply_date_filter_operator(
                                $models,
                                $filter['key'],
                                '>',
                                $filter['mayor_que']
                            );
                            
                            $used_filters[] = [
                                'key'       => $filter['key'],
                                'operator'  => 'mayor_que',
                                'value'     => $filter['mayor_que'],
                                'type'      => $filter['type'],
                            ];
                        }

                    } else if ($filter['type'] == 'select' 
                        && isset($filter['igual_que'])
                        && $filter['igual_que'] !== 0
                    ) {
                        
                        $models = $models->where($filter['key'], $filter['igual_que']);
                        // Log::info('Filtrando por select '.$filter['key'].' igual_que '.$filter['igual_que']);
                        
                        $used_filters[] = [
                            'key'       => $filter['key'],
                            'operator'  => 'igual_que',
                            'value'     => $filter['igual_que'],
                            'type'      => $filter['type'],
                        ];

                    } else if ($filter['type'] == 'checkbox' 
                        && isset($filter['checkbox'])
                        && $filter['checkbox'] != -1
                    ) {
                        // Clave del filtro (columna booleana/tinyint). Valor pedido por el cliente (1/0, true/false, '0', etc.).
                        $checkboxKey = $filter['key'];
                        $checkboxVal = $filter['checkbox'];

                        // Desactivado: en SQL `col = 0` no coincide con NULL; tratamos NULL como desactivado igual que 0/false.
                        if (in_array($checkboxVal, [0, false, '0'], true)) {
                            $models = $models->where(function ($subquery) use ($checkboxKey) {
                                $subquery->whereNull($checkboxKey)
                                    ->orWhere($checkboxKey, 0);
                            });
                        } else {
                            $models = $models->where($checkboxKey, $checkboxVal);
                        }

                        $used_filters[] = [
                            'key'       => $filter['key'],
                            'operator'  => 'checkbox',
                            'value'     => $filter['checkbox'],
                            'type'      => $filter['type'],
                        ];
                        // Log::info('Filtrando por checkbox '.$filter['key'].' igual_que '.$filter['checkbox']);
                    }

                }
            }
        }

        if ($model_name_param == 'article') {
            $models = $models->where('status', 'active');
        }
        $models = $models->withAll()
                        ->orderBy('created_at', 'DESC');

        if ($model_name_param === 'sale') {
            SaleArticlesEagerLoadHelper::apply_images_if_preferred($models, $this->userId());
        }

        if ($paginate) {
            $per_page = (int) $request->input('per_page', 50);
            if ($per_page < 1) {
                $per_page = 5;
            }
            if ($per_page > 200) {
                $per_page = 200;
            }
            $models = $models->paginate($per_page);
        } else {
            $models = $models->get();
        }

        if ($return_raw_models) {
            return $models;
        }

        // Log::info('Resultado de la busqueda: '.count($models).' modelos');
        // Log::info('-------------------');

        if ($model_name_param == 'article') {

            Log::info('used_filters:');
            Log::info($used_filters);

            FilterHistoryService::log_action([
                'user_id'             => $this->userId(true),
                'auth_user_id'        => $this->userId(false),
                'action'              => 'busqueda',
                'model_name'          => 'article',
                'filtrados_count'     => count($models),
                'afectados_count'     => 0,
                'used_filters'        => $used_filters,
            ]);
        }

        if (is_null($_filters)) {
            return response()->json(['models' => $models], 200);
        } else {

            if ($return_used_filters) {
                return [
                    'models'        => $models,
                    'used_filters'  => $used_filters, 
                ];
            }
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

        if ($model_name_param == 'article') {
            $models->where('status', 'active');
        }

        $models = $models->paginate(25);

        return response()->json(['models' => $models], 200);
    }

    /**
     * Indica si el valor del filtro date debe compararse con hora (no solo día calendario).
     * Valores solo fecha (YYYY-MM-DD) o datetime-local con 00:00 se tratan como día completo.
     *
     * @param string $value Valor enviado desde el SPA (date o datetime-local).
     * @return bool
     */
    protected function date_filter_uses_datetime_comparison($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return false;
        }
        if (strpos($value, 'T') === false && strpos($value, ' ') === false) {
            return false;
        }
        $normalized = str_replace('T', ' ', $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}\s+(\d{2}):(\d{2})/', $normalized, $matches)) {
            return $matches[1] !== '00' || $matches[2] !== '00';
        }
        return false;
    }

    /**
     * Normaliza datetime-local del frontend (2026-06-01T14:30) a formato SQL.
     *
     * @param string $value
     * @return string
     */
    protected function normalize_date_filter_value_for_query($value)
    {
        $value = trim((string) $value);
        if (strpos($value, 'T') !== false) {
            $value = str_replace('T', ' ', $value);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}$/', $value)) {
            $value .= ':00';
        }
        return $value;
    }

    /**
     * Parte fecha (YYYY-MM-DD) para whereDate cuando no hay hora efectiva.
     *
     * @param string $value
     * @return string
     */
    protected function date_filter_date_only_part($value)
    {
        $value = trim((string) $value);
        if (strpos($value, 'T') !== false) {
            return substr($value, 0, 10);
        }
        if (strpos($value, ' ') !== false) {
            return substr($value, 0, 10);
        }
        return $value;
    }

    /**
     * Aplica operador de filtro date: whereDate si es solo día; where con timestamp si hay hora.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $column
     * @param string $operator '<', '=', '>'
     * @param string $value
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function apply_date_filter_operator($query, $column, $operator, $value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return $query;
        }

        if ($this->date_filter_uses_datetime_comparison($value)) {
            $sql_value = $this->normalize_date_filter_value_for_query($value);
            return $query->where($column, $operator, $sql_value);
        }

        $date_only = $this->date_filter_date_only_part($value);

        if ($operator === '<') {
            return $query->whereDate($column, '<', $date_only);
        }
        if ($operator === '=') {
            return $query->whereDate($column, $date_only);
        }
        if ($operator === '>') {
            return $query->whereDate($column, '>', $date_only);
        }

        return $query;
    }
}
