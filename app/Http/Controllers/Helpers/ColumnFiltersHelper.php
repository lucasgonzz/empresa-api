<?php

namespace App\Http\Controllers\Helpers;

use Illuminate\Support\Facades\Log;

/**
 * Unica traduccion de un filtro de columna del listado (el que se carga desde la lupa de cada
 * header de tabla) a SQL: en blanco / no en blanco, number con menor-igual-mayor, text con
 * que_contenga e igual_que, search y select por FK, date con los tres operadores, checkbox
 * tratando NULL como desactivado, y el ordenamiento por ordenar_de (que sabe ordenar por la
 * columna visible de una relacion, no por el id del FK). La comparten `SearchController::search()`
 * y `SearchController::globalSearch()`: cualquiera que este tentado de reimplementar un pedacito
 * de esto en otro controller tiene que leer aca que ya existe y por que no se duplica.
 */
class ColumnFiltersHelper
{
    /**
     * Aplica los filtros de columna del listado sobre el query builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $models          Query en construccion.
     * @param  array|null                            $filters         Filtros tal cual los manda el SPA.
     * @param  string                                $model_name_param Nombre snake_case del modelo (ruta).
     * @param  string                                $model_name       Clase Eloquent del modelo.
     * @return array  ['models' => Builder, 'used_filters' => array]
     */
    public static function apply($models, $filters, $model_name_param, $model_name)
    {
        if (!is_array($filters)) {
            return ['models' => $models, 'used_filters' => []];
        }

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
                    $models = self::apply_order_filter($models, $model_name, $filter);

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

                        // "En blanco" tiene que coincidir con lo que el listado MUESTRA en blanco:
                        // ademas de FK nulo o 0, un FK que apunta a un registro borrado (soft
                        // delete) o inexistente. Ver relation_for_blank_check().
                        $relation_info = self::relation_for_blank_check($model_name, $filter['key']);

                        $models = $models->where(function ($subquery) use ($filter, $relation_info) {
                            $subquery->whereNull($filter['key'])
                                        ->orWhere($filter['key'], 0);

                            if ($relation_info) {
                                $subquery->orWhereNotExists(function ($related_query) use ($filter, $relation_info) {
                                    self::where_related_is_alive($related_query, $filter['key'], $relation_info);
                                });
                            }
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

                        // Inverso exacto de en_blanco: FK cargado Y el registro relacionado existe y
                        // no esta borrado (ver relation_for_blank_check()).
                        $relation_info = self::relation_for_blank_check($model_name, $filter['key']);

                        $models = $models->where(function ($subquery) use ($filter, $relation_info) {
                            $subquery->whereNotNull($filter['key'])
                                        ->where($filter['key'], '!=', 0);

                            if ($relation_info) {
                                $subquery->whereExists(function ($related_query) use ($filter, $relation_info) {
                                    self::where_related_is_alive($related_query, $filter['key'], $relation_info);
                                });
                            }
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

                            $models = self::apply_date_filter_operator(
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

                            $models = self::apply_date_filter_operator(
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

                            $models = self::apply_date_filter_operator(
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

        return ['models' => $models, 'used_filters' => $used_filters];
    }

    /**
     * Resuelve la relacion belongsTo detras de un filtro select/search por FK ("<relacion>_id"),
     * para que "en blanco" / "no en blanco" coincidan con lo que el listado muestra.
     *
     * Causa del bug (servian, 23/9/2026): el listado embebe la relacion (ej: `provider`) y un
     * registro borrado con soft delete no se carga, asi que la celda se ve vacia — pero el FK sigue
     * apuntando al registro borrado (`ProviderController::destroy` no toca los articulos). El filtro
     * miraba solo `FK IS NULL OR FK = 0` y esos articulos no aparecian nunca. En servian2 eran
     * 29.682 articulos con proveedor borrado.
     *
     * Devuelve null cuando la key no es un belongsTo resoluble cuyo FK sea justamente la key: en
     * ese caso el filtro se comporta exactamente como antes.
     *
     * @param string $model_name Clase Eloquent del modelo filtrado.
     * @param string $key        Key del filtro (ej: provider_id).
     * @return array|null ['table' => tabla relacionada, 'owner_key' => pk referenciada,
     *                     'own_table' => tabla filtrada, 'deleted_at' => columna soft delete o null]
     */
    protected static function relation_for_blank_check($model_name, $key)
    {
        if (!is_string($key) || strlen($key) <= 3 || substr($key, -3) !== '_id') {
            return null;
        }

        $relation_method = substr($key, 0, -3);
        $instance = new $model_name();

        if (!method_exists($instance, $relation_method)) {
            return null;
        }

        // La key sale del request: no se invoca cualquier metodo publico del modelo. Un `save_id`
        // o `touch_id` llamaria a save()/touch() heredados de Eloquent y escribiria en la base.
        // Solo se acepta un metodo publico declarado en el propio modelo, sin parametros obligatorios.
        $reflection = new \ReflectionMethod($instance, $relation_method);

        if (!$reflection->isPublic()
            || $reflection->isStatic()
            || $reflection->getNumberOfRequiredParameters() > 0
            || $reflection->getDeclaringClass()->getName() === \Illuminate\Database\Eloquent\Model::class) {
            return null;
        }

        $relation = $instance->$relation_method();

        // MorphTo extiende BelongsTo pero no tiene una tabla ni un owner key fijos.
        if (!($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo)
            || $relation instanceof \Illuminate\Database\Eloquent\Relations\MorphTo
            || $relation->getForeignKeyName() !== $key) {
            return null;
        }

        $related = $relation->getRelated();

        // Auto-referencia (ej: users.owner_id -> users): la subconsulta pisaria el nombre de la
        // tabla externa y quedaria sin correlacionar. No se aplica el chequeo extra.
        if ($related->getTable() === $instance->getTable()) {
            return null;
        }

        // Si la relacion se declara con withTrashed() el listado SI muestra el registro borrado:
        // ahi un borrado no cuenta como "en blanco", solo el inexistente.
        $muestra_borrados = in_array(
            \Illuminate\Database\Eloquent\SoftDeletingScope::class,
            $relation->getQuery()->removedScopes(),
            true
        );

        return [
            'table'      => $related->getTable(),
            'owner_key'  => $relation->getOwnerKeyName(),
            'own_table'  => $instance->getTable(),
            'deleted_at' => (!$muestra_borrados && method_exists($related, 'getDeletedAtColumn'))
                ? $related->getDeletedAtColumn()
                : null,
        ];
    }

    /**
     * Condiciones de la subconsulta EXISTS: el registro relacionado existe y no esta borrado.
     *
     * @param \Illuminate\Database\Query\Builder $related_query Subconsulta del EXISTS.
     * @param string                             $key           FK en la tabla filtrada.
     * @param array                              $relation_info Salida de relation_for_blank_check().
     * @return void
     */
    protected static function where_related_is_alive($related_query, $key, array $relation_info)
    {
        $related_query->select(\Illuminate\Support\Facades\DB::raw(1))
            ->from($relation_info['table'])
            ->whereColumn(
                $relation_info['table'].'.'.$relation_info['owner_key'],
                $relation_info['own_table'].'.'.$key
            );

        if ($relation_info['deleted_at']) {
            $related_query->whereNull($relation_info['table'].'.'.$relation_info['deleted_at']);
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
    protected static function apply_order_filter($models, $model_name, $filter)
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

    /**
     * Indica si el valor del filtro date debe compararse con hora (no solo día calendario).
     * Valores solo fecha (YYYY-MM-DD) o datetime-local con 00:00 se tratan como día completo.
     *
     * @param string $value Valor enviado desde el SPA (date o datetime-local).
     * @return bool
     */
    protected static function date_filter_uses_datetime_comparison($value)
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
    protected static function normalize_date_filter_value_for_query($value)
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
    protected static function date_filter_date_only_part($value)
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
    protected static function apply_date_filter_operator($query, $column, $operator, $value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return $query;
        }

        if (self::date_filter_uses_datetime_comparison($value)) {
            $sql_value = self::normalize_date_filter_value_for_query($value);
            return $query->where($column, $operator, $sql_value);
        }

        $date_only = self::date_filter_date_only_part($value);

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
