<?php

namespace App\Http\Controllers\CommonLaravel;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\ExtraFiltersHelper;
use App\Http\Controllers\Helpers\GlobalSearchQueryHelper;
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
                    // Delegamos el ordenamiento en un helper que sabe ordenar tanto por columnas
                    // propias del modelo como por la columna visible de una relacion belongsTo
                    // (categoria, proveedor, marca, etc). Ver apply_order_filter mas abajo.
                    $models = $this->apply_order_filter($models, $model_name, $filter);

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

    /**
     * Aplica el ordenamiento pedido por un filtro.
     *
     * Para filtros de relacion (select/search cuya key es un FK con forma "<relacion>_id"),
     * ordena por la columna VISIBLE de la relacion (por ejemplo el nombre de la categoria),
     * no por el id del FK. Lo hace con una subconsulta correlacionada (sin JOIN) para no
     * colisionar con withAll() ni multiplicar filas en la paginacion. Si no es una relacion
     * belongsTo resoluble, cae al orderBy directo sobre la columna (comportamiento previo,
     * seguro para columnas propias y enums).
     *
     * @param \Illuminate\Database\Eloquent\Builder $models     Query en construccion.
     * @param string                                $model_name Clase Eloquent del modelo filtrado.
     * @param array                                 $filter     Filtro con key, type y ordenar_de.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function apply_order_filter($models, $model_name, $filter)
    {
        // Direccion de orden pedida (ASC o DESC).
        $direction = $filter['ordenar_de'];
        // Columna o FK sobre la que se pidio ordenar.
        $key = $filter['key'];
        // Tipo del filtro (number, text, date, select, search, ...).
        $type = isset($filter['type']) ? $filter['type'] : null;

        // Solo intentamos orden por relacion cuando el filtro es de relacion (select/search) y su
        // key tiene forma de FK "<algo>_id". El resto ordena directo por la columna.
        $is_relation_filter = ($type === 'select' || $type === 'search')
            && strlen($key) > 3
            && substr($key, -3) === '_id';

        if ($is_relation_filter) {
            // Nombre del metodo de relacion por convencion: category_id -> category.
            $relation_method = substr($key, 0, -3);

            // Instancia del modelo para resolver la relacion real via Eloquent (sin adivinar plurales).
            $instance = new $model_name();

            if (method_exists($instance, $relation_method)) {
                // Resolvemos la relacion declarada en el modelo.
                $relation = $instance->$relation_method();

                // Solo belongsTo tiene un unico FK ordenable de forma correlacionada.
                if ($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo) {
                    // Tabla del modelo relacionado (ej: categories) tomada del propio Eloquent.
                    $related_table = $relation->getRelated()->getTable();
                    // Clave primaria referenciada en la tabla relacionada (normalmente id).
                    $related_key = $relation->getOwnerKeyName();
                    // Tabla del modelo que se esta filtrando (ej: articles).
                    $own_table = $instance->getTable();

                    // Columna visible de la relacion: la que manda el front (order_relation_prop)
                    // o "name" por defecto. Para el IVA, por ejemplo, el front manda "percentage".
                    $order_column = (isset($filter['order_relation_prop']) && $filter['order_relation_prop'] != '')
                        ? $filter['order_relation_prop']
                        : 'name';

                    // Subconsulta correlacionada: por cada fila del modelo trae el valor visible de
                    // su relacion (ej: el name de su categoria) para usarlo como criterio de orden.
                    $order_subquery = \Illuminate\Support\Facades\DB::table($related_table)
                        ->select($related_table.'.'.$order_column)
                        ->whereColumn($related_table.'.'.$related_key, $own_table.'.'.$key)
                        ->limit(1);

                    // Ordenamos por el resultado de la subconsulta (por el nombre de la relacion).
                    return $models->orderBy($order_subquery, $direction);
                }
            }
        }

        // Comportamiento previo: orden directo por la columna propia del modelo.
        return $models->orderBy($key, $direction);
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
     * Buscador general unificado (view-header de todos los módulos).
     *
     * Evolución de `searchFromModal`: además de props propias del modelo (OR con AND de
     * keywords vía LIKE, igual criterio que `searchFromModal`), soporta OR contra props de
     * relaciones (`relation_props`, vía `orWhereHas`) y un AND de filtros extra por módulo
     * (`extra_filters`, ej. categoría / stock en el listado de artículos).
     *
     * Payload esperado (JSON body, POST):
     * - query_value    (string) Criterio de texto. OJO: se llama `query_value`, nunca `query`
     *                   (`$request->query` es una propiedad real de Symfony\Request, no un input).
     * - props           (array)  Columnas propias del modelo a considerar en el grupo de coincidencia
     *                   (ej: ["name","bar_code"] o [{key:"name",keyword_mode:"todas"}]). Cada item
     *                   puede venir como string suelto (payload viejo del frontend, se trata como
     *                   keyword_mode 'alguna') o como objeto { key, keyword_mode }, con keyword_mode
     *                   'todas' (la prop debe contener TODAS las palabras del criterio ella sola) o
     *                   'alguna' (la prop aporta al "pozo común": alcanza con que aporte alguna
     *                   palabra, mientras el resto aparezca en otra prop del pozo). Default 'alguna'.
     *                   IMPORTANTE: el payload viejo (array de strings) sigue funcionando, pero
     *                   CAMBIA de comportamiento respecto de antes de este prompt: pasa del modo
     *                   estricto ('todas', el de siempre) al distribuido ('alguna', el que usa
     *                   Vender), que es el nuevo default pedido. Es intencional.
     * - relation_props  (array)  Objetos { relation, props, keyword_mode } para el grupo de
     *                   coincidencia contra relaciones vía whereHas. Mismo significado de
     *                   keyword_mode que en props (default 'alguna' si no viene).
     * - conector        (string) 'or' (default) o 'and'. Conecta el grupo de props/relaciones en
     *                   modo 'todas' (estricto) con el grupo en modo 'alguna' (distribuido). Con
     *                   'and', ambos grupos tienen que cumplirse; con 'or' (o cualquier otro valor),
     *                   alcanza con que se cumpla uno de los dos. Ver GlobalSearchQueryHelper::apply.
     * - extra_filters   (array)  Objetos { key, operator, value } que ANDean sobre el resultado del
     *                   grupo de coincidencia de texto. Delegado en ExtraFiltersHelper::apply.
     *                   Whitelist de operadores: '=' (igualdad, admite 0 como valor legítimo),
     *                   'like' (contains), '>' / '<' / '>=' / '<=' (comparación numérica, solo si la
     *                   columna es numérica y el valor también lo es), 'numeric_presence' (solo
     *                   columnas numéricas; valores 'con_valor' = no nula, 'positivo' = mayor a
     *                   cero, 'todos' = sin filtro), y los legacy 'category' (fuerza category_id) y
     *                   'stock_option' (con_stock / hayan_tenido_stock / sin_stock / con_o_sin_stock).
     *                   Cualquier otro operador, o una key que no sea columna de la tabla, se ignora
     *                   en silencio.
     * - per_page        (int)    Tamaño de página (clamp 1..200, default 50). Página vía ?page=.
     *
     * @param Request $request
     * @param string $model_name_param Nombre del modelo en snake_case (ej: 'article').
     * @return \Illuminate\Http\JsonResponse Misma forma que `search` (paginador con data/last_page/total),
     *         o un JSON de error controlado (400) si el modelo/prop/relación pedida no existe.
     */
    function globalSearch(Request $request, $model_name_param) {

        // Resolución del modelo Eloquent a partir del nombre en snake_case del param de ruta.
        $model_name = GeneralHelper::getModelName($model_name_param);

        // Si el modelo no existe, devolvemos error controlado en vez de un 500 por clase inexistente.
        if (!class_exists($model_name)) {
            return response()->json(['error' => 'Modelo inexistente: '.$model_name_param], 400);
        }

        // Criterio de texto. SIEMPRE `query_value` (nunca `$request->query`, que es una propiedad
        // real de Symfony\Request y no el input del mismo nombre).
        $query_value = trim((string) $request->input('query_value'));

        // Listas recibidas del frontend. Con default a array vacío si no vienen.
        $props = $request->input('props', []);
        $relation_props = $request->input('relation_props', []);
        $extra_filters = $request->input('extra_filters', []);

        // Conector entre el grupo estricto ('todas') y el grupo distribuido ('alguna') de props y
        // relaciones. Default 'or': alcanza con que se cumpla uno de los dos grupos.
        $conector = $request->input('conector', 'or');

        // Instancia temporal del modelo, usada solo para chequear existencia de columnas/relaciones
        // antes de armar la query (evita 500 por prop o relación inexistente).
        $model_instance = new $model_name();

        // Nombre de la tabla del modelo, para resolver tipo de columna vía el schema builder.
        $table = $model_instance->getTable();

        $models = $model_name::where('user_id', $this->userId())->withAll();

        // Grupo de coincidencia de texto (props + relaciones, con su keyword_mode y el conector),
        // delegado en GlobalSearchQueryHelper. Reemplaza el armado inline que antes mezclaba OR de
        // props con AND de palabras adentro de cada una (ver PHPDoc del helper para la lógica de
        // los dos modos y su combinación).
        $models = GlobalSearchQueryHelper::apply($models, $query_value, $props, $relation_props, $conector, $table, $model_instance);

        // AND de filtros extra, fuera del closure del grupo OR para que sean condiciones AND reales.
        // Delegado en ExtraFiltersHelper::apply (whitelist de operadores genéricos: '=', 'like',
        // comparación numérica '>','<','>=','<=', 'numeric_presence', y los legacy 'category' y
        // 'stock_option'). Cualquier operador fuera de la whitelist se ignora en silencio (no se
        // ejecuta SQL arbitrario con la key/valor que venga del request).
        $models = ExtraFiltersHelper::apply($models, $table, $extra_filters);

        if ($model_name_param == 'article') {
            $models = $models->where('status', 'active');
        }

        // Paginado con clamp 1..200 (default 50), igual que `search`.
        $per_page = (int) $request->input('per_page', 50);
        if ($per_page < 1) {
            $per_page = 50;
        }
        if ($per_page > 200) {
            $per_page = 200;
        }

        $models = $models->orderBy('created_at', 'DESC')->paginate($per_page);

        return response()->json(['models' => $models], 200);
    }

    /**
     * Indica si una columna de la tabla es de tipo numérico (int/decimal/float), consultando
     * `information_schema` directamente (sin Doctrine DBAL, que rompe con "Unknown database type
     * enum" apenas la tabla tiene alguna columna enum, sin importar qué columna se pida).
     *
     * Se conserva este método (sus llamadores actuales dentro de `globalSearch` no cambian de
     * comportamiento), pero delega en `ExtraFiltersHelper::is_numeric_column`, que es ahora la
     * implementación real y reusable por cualquier buscador (ver ExtraFiltersHelper).
     *
     * @param string $table Nombre de la tabla (sin prefijo de base de datos).
     * @param string $column Nombre de la columna a chequear.
     * @return bool
     */
    protected function is_numeric_column($table, $column) {
        return ExtraFiltersHelper::is_numeric_column($table, $column);
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
