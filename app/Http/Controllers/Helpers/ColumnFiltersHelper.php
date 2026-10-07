<?php

namespace App\Http\Controllers\Helpers;

use App\Exceptions\FiltroDeColumnaInvalidoException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Log;

/**
 * Unica traduccion de un filtro de columna del listado (el que se carga desde la lupa de cada
 * header de tabla) a SQL: en blanco / no en blanco, number con menor-igual-mayor, text con
 * que_contenga e igual_que, search y select por FK, date con los tres operadores, checkbox
 * tratando NULL como desactivado, y el ordenamiento por ordenar_de (que sabe ordenar por la
 * columna visible de una relacion, no por el id del FK). La comparten `SearchController::search()`
 * y `SearchController::globalSearch()`: cualquiera que este tentado de reimplementar un pedacito
 * de esto en otro controller tiene que leer aca que ya existe y por que no se duplica.
 *
 * 🔴 EL `key` DE CADA FILTRO SALE DEL PEDIDO Y ES UN NOMBRE DE COLUMNA, NADA MAS (mision
 * filtros-key-sin-inyeccion, 5/10/2026). Hasta esa fecha el "que contenga" se armaba con
 * `whereRaw($filter['key'].' LIKE ?')`, sin parentesis: un key como "1=1 OR name" dejaba
 * `user_id = <dueño> AND 1=1 OR name LIKE ?` y traia las filas de TODOS los comercios de una base
 * compartida. Por aca pasan ocho caminos (search, global-search, la exportacion a Excel, el PDF del
 * catalogo, el PDF de clientes, la eliminacion y la actualizacion masivas por filtro, el asistente),
 * y por las dos masivas eso BORRABA o MODIFICABA filas ajenas. Por eso la guarda vive aca, una sola
 * vez, y no en cada controller: ver columna_del_filtro(), tiene_criterio() y relacion_real().
 */
class ColumnFiltersHelper
{
    /**
     * Forma de un identificador SQL simple: letras, numeros y guion bajo, sin empezar con numero.
     * Es el primer filtro (barato) de un key; el que decide es que el nombre este en la lista de
     * columnas reales de la tabla (ver columna_valida()).
     *
     * Termina en `\z` y no en `$`: en PCRE `$` también acepta un "\n" final, así que "name\n" pasaba
     * por identificador. Hoy lo frenaba la lista de columnas igual, pero el patrón tiene que decir
     * lo que promete.
     */
    const PATRON_IDENTIFICADOR = '/^[A-Za-z_][A-Za-z0-9_]*\z/';

    /**
     * Ruta real de app/ (cacheada por proceso), para relacion_real(). null = todavia no se calculo.
     *
     * @var string|false|null
     */
    protected static $ruta_de_app = null;

    /**
     * Aplica los filtros de columna del listado sobre el query builder.
     *
     * Orden de cada filtro (mision filtros-key-sin-inyeccion):
     *  1. Un filtro que no es array o no trae `type` se saltea (igual que siempre).
     *  2. `images`: rama propia (presencia de una relacion, no una columna).
     *  3. `afip_ticket_cbte_numero` de venta: rama propia (su key no es una columna de sales).
     *  4. El resto: el key tiene que ser una columna real de la tabla. Si no lo es y el filtro trae un
     *     criterio puesto → FiltroDeColumnaInvalidoException (422). Si no lo es y el filtro es inerte
     *     → se saltea, como hasta ahora.
     *  5. De ahi en adelante, a SQL entra SOLO la columna validada ($columna), nunca $filter['key'].
     *
     * @param  \Illuminate\Database\Eloquent\Builder $models          Query en construccion.
     * @param  array|null                            $filters         Filtros tal cual los manda el SPA.
     * @param  string                                $model_name_param Nombre snake_case del modelo (ruta).
     * @param  string                                $model_name       Clase Eloquent del modelo.
     * @return array  ['models' => Builder, 'used_filters' => array]
     *
     * @throws \App\Exceptions\FiltroDeColumnaInvalidoException  Key que no es columna con un criterio
     *         puesto, o una direccion de orden que no es ASC ni DESC.
     */
    public static function apply($models, $filters, $model_name_param, $model_name)
    {
        if (!is_array($filters)) {
            return ['models' => $models, 'used_filters' => []];
        }

        $used_filters = [];

        // Columnas reales de la tabla del modelo (minuscula => nombre real). Se piden UNA sola vez
        // por apply() y recien cuando el primer filtro las necesita: un pedido sin filtros, o solo
        // con el de imagenes, no paga la consulta a information_schema.
        $columnas = null;

        foreach ($filters as $filter) {

            // Log::info('Va con ');
            // Log::info($filter);

            // Un filtro que no es array o no trae type se saltea, igual que siempre.
            if (is_array($filter) && isset($filter['type'])) {

                /*
                 * "Sin imágenes" / "Con imágenes" en la columna de imágenes del listado (misión
                 * imagenes-catalogo-completo, 27/9/2026). Las imágenes no son una columna sino una
                 * relación (morphMany), así que "en blanco" es que no tenga ninguna y "no en blanco"
                 * que tenga al menos una. Va ANTES y con `continue` porque ninguna de las ramas de
                 * abajo sirve para este tipo: la genérica de en_blanco haría `images IS NULL` sobre
                 * una columna que no existe (error de SQL), y ordenar por `images` también. Su key se
                 * valida como relación real (is_own_relation()), no como columna.
                 */
                if ($filter['type'] == 'images') {
                    $presencia = self::apply_images_presence_filter($models, $filter, $model_name);
                    $models    = $presencia['models'];

                    if (!is_null($presencia['used_filter'])) {
                        $used_filters[] = $presencia['used_filter'];
                    }

                    continue;
                }

                /**
                 * Ventas: filtro por N° de comprobante en afip_tickets (relación hasMany).
                 * No aplica sobre columna de sales; usa whereHas en afip_tickets.cbte_numero.
                 *
                 * Va adelantado y con `continue` (mision filtros-key-sin-inyeccion): su key
                 * (`afip_ticket_cbte_numero`, lo arma store/sale/index.js de la SPA) NO es una columna
                 * de sales y no entra a ningun SQL, asi que no puede pasar por la guarda de columnas de
                 * abajo. Solo lee el que_contenga: ni en_blanco ni orden (los dos daban 500 sobre esa
                 * columna inexistente).
                 */
                if ($filter['type'] == 'afip_ticket_cbte_numero' && $model_name_param == 'sale') {

                    if (isset($filter['que_contenga'])
                        && is_scalar($filter['que_contenga'])
                        && trim($filter['que_contenga']) != '') {

                        $cbte_numero_search = trim($filter['que_contenga']);
                        $models = $models->whereHas('afip_tickets', function ($q) use ($cbte_numero_search) {
                            $q->where('cbte_numero', 'like', '%' . $cbte_numero_search . '%');
                        });

                        $used_filters[] = [
                            'key'       => isset($filter['key']) ? $filter['key'] : 'afip_ticket_cbte_numero',
                            'operator'  => 'que_contenga',
                            'value'     => $filter['que_contenga'],
                            'type'      => $filter['type'],
                        ];
                    }

                    continue;
                }

                if (is_null($columnas)) {
                    $columnas = self::columnas_de_la_tabla($model_name);
                }

                /*
                 * 🔴 LA GUARDA. $columna es el nombre REAL de una columna de la tabla, o null.
                 *
                 * No alcanza con un regex de "parece un identificador": la lista de columnas reales es
                 * lo que garantiza que lo que entra al SQL es una columna de ESTA tabla y no, por
                 * ejemplo, el nombre de otra columna de otra tabla o una funcion. El regex solo descarta
                 * rapido lo que ni siquiera puede ser un nombre.
                 */
                $columna = self::columna_del_filtro($filter, $columnas);

                if (is_null($columna)) {

                    /*
                     * 🔴 Inerte se saltea, con criterio da 422. No "simplificar" a rechazar todo key
                     * que no sea columna: la SPA manda TODOS los filtros de la tabla en cada busqueda y
                     * en el filter_form de las masivas, tambien los que el usuario no toco, y varios
                     * modelos declaran columnas que no son de la tabla (article_purchase.bar_code,
                     * employee.*, client.limites_credito...). Rechazarlos rompe el listado entero.
                     *
                     * Y tampoco "simplificar" a saltear siempre: un filtro CON criterio que no se puede
                     * aplicar no puede desaparecer en silencio, porque en una masiva eso es operar sobre
                     * mas filas de las que la persona pidio. Hoy ese caso daba 500 (SQL sobre una
                     * columna inexistente); ahora da 422 y la operacion no corre.
                     */
                    if (self::tiene_criterio($filter)) {
                        throw new FiltroDeColumnaInvalidoException(
                            isset($filter['key']) ? $filter['key'] : null,
                            $model_name,
                            'key_no_es_columna'
                        );
                    }

                    continue;
                }

                if (isset($filter['ordenar_de'])
                && $filter['ordenar_de'] != '') {

                    // La direccion tambien sale del pedido: solo asc o desc. Antes cualquier otra cosa
                    // era un InvalidArgumentException de Laravel (500).
                    $direccion = self::direccion_de_orden($filter['ordenar_de']);

                    if (is_null($direccion)) {
                        throw new FiltroDeColumnaInvalidoException(
                            $filter['key'],
                            $model_name,
                            'direccion_de_orden'
                        );
                    }

                    // Delegamos el ordenamiento en un helper que sabe ordenar tanto por columnas
                    // propias del modelo como por la columna visible de una relacion belongsTo
                    // (categoria, proveedor, marca, etc). Ver apply_order_filter mas abajo.
                    $models = self::apply_order_filter($models, $model_name, $filter, $columna, $direccion);

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
                        $relation_info = self::relation_for_blank_check($model_name, $columna);

                        $models = $models->where(function ($subquery) use ($columna, $relation_info) {
                            $subquery->whereNull($columna)
                                        ->orWhere($columna, 0);

                            if ($relation_info) {
                                $subquery->orWhereNotExists(function ($related_query) use ($columna, $relation_info) {
                                    self::where_related_is_alive($related_query, $columna, $relation_info);
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
                        $models = $models->whereNull($columna);

                        $used_filters[] = [
                            'key'       => $filter['key'],
                            'operator'  => 'en_blanco',
                            'value'     => true,
                            'type'      => $filter['type'],
                        ];

                    } else {

                        $models = $models->where(function ($subquery) use ($columna) {
                            $subquery->whereNull($columna)
                                        ->orWhere($columna, '');
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
                        $relation_info = self::relation_for_blank_check($model_name, $columna);

                        $models = $models->where(function ($subquery) use ($columna, $relation_info) {
                            $subquery->whereNotNull($columna)
                                        ->where($columna, '!=', 0);

                            if ($relation_info) {
                                $subquery->whereExists(function ($related_query) use ($columna, $relation_info) {
                                    self::where_related_is_alive($related_query, $columna, $relation_info);
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
                        $models = $models->whereNotNull($columna);

                        $used_filters[] = [
                            'key'       => $filter['key'],
                            'operator'  => 'no_en_blanco',
                            'value'     => true,
                            'type'      => $filter['type'],
                        ];

                    } else {

                        $models = $models->where(function ($subquery) use ($filter, $columna) {
                            $subquery->whereNotNull($columna)
                                        ->where($columna, '!=', '');
                            if ($filter['type'] == 'number') {
                                $subquery->where($columna, '!=', 0);
                            }
                        });

                        $used_filters[] = [
                            'key'       => $filter['key'],
                            'operator'  => 'no_en_blanco',
                            'value'     => true,
                            'type'      => $filter['type'],
                        ];
                    }

                } else {

                    // Log::info('Entro');
                    // Log::info($filter['type'] == 'select');
                    // Log::info(isset($filter['igual_que']));
                    // Log::info($filter['igual_que'] !== 0);

                    $key = $columna;

                    if ($key == 'num' && $model_name_param == 'article') {
                        $key = 'id';
                    }

                    if ($filter['type'] == 'number') {
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

                            $models = $models->where($columna, trim($filter['igual_que']));
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
                                /*
                                 * 🔴 Por la columna VALIDADA y con where(), que la envuelve en backticks.
                                 * Aca vivia `whereRaw($filter['key'].' LIKE ?')`: el key del pedido
                                 * concatenado en el SQL, sin parentesis, era la puerta de la inyeccion
                                 * (mision filtros-key-sin-inyeccion). No volver a concatenar nada que
                                 * venga del pedido en un whereRaw.
                                 */
                                $models->where($columna, 'LIKE', "%$keyword%");
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

                        $models = $models->where($columna, $filter['igual_que']);

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
                                $columna,
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
                                $columna,
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
                                $columna,
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

                        $models = $models->where($columna, $filter['igual_que']);
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
                        // Clave del filtro (columna booleana/tinyint, ya validada). Valor pedido por el cliente (1/0, true/false, '0', etc.).
                        $checkboxKey = $columna;
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
     * Columnas reales de la tabla de un modelo, en un mapa minuscula => nombre real. Una sola
     * consulta (information_schema), por la conexion del propio modelo.
     *
     * Es publica para que otros buscadores que reciben nombres de columna del pedido
     * (SearchController::searchFromModal) validen con EXACTAMENTE la misma regla, sin copiarla.
     *
     * @param  string|\Illuminate\Database\Eloquent\Model  $modelo  Clase Eloquent o una instancia.
     * @return array<string, string>
     */
    public static function columnas_de_la_tabla($modelo)
    {
        $instancia = $modelo instanceof Model ? $modelo : new $modelo();

        $listado = $instancia->getConnection()
                            ->getSchemaBuilder()
                            ->getColumnListing($instancia->getTable());

        $columnas = [];

        foreach ($listado as $nombre) {
            // MySQL compara nombres de columna sin distinguir mayusculas: se indexa en minuscula
            // y se devuelve el nombre tal como lo declara la tabla.
            $columnas[strtolower($nombre)] = $nombre;
        }

        return $columnas;
    }

    /**
     * El nombre REAL de la columna si $key es un identificador y esta en $columnas; si no, null.
     *
     * 🔴 Lo que decide es la LISTA de columnas reales, no el regex. Un regex solo no alcanza: un
     * identificador bien formado puede no ser una columna de esta tabla (y terminar en un 500) o ser
     * el nombre de una funcion de SQL. El regex queda delante porque descarta barato, antes de
     * buscar en la lista, todo lo que trae espacios, parentesis, comillas o puntos.
     *
     * @param  mixed                  $key       Lo que llego en el pedido.
     * @param  array<string, string>  $columnas  Salida de columnas_de_la_tabla().
     * @return string|null
     */
    public static function columna_valida($key, array $columnas)
    {
        if (!is_string($key) || !preg_match(self::PATRON_IDENTIFICADOR, $key)) {
            return null;
        }

        $minuscula = strtolower($key);

        return isset($columnas[$minuscula]) ? $columnas[$minuscula] : null;
    }

    /**
     * La columna validada del key de un filtro, o null (ver columna_valida()).
     *
     * @param  array                  $filter
     * @param  array<string, string>  $columnas
     * @return string|null
     */
    protected static function columna_del_filtro(array $filter, array $columnas)
    {
        return self::columna_valida(isset($filter['key']) ? $filter['key'] : null, $columnas);
    }

    /**
     * ¿El filtro trae algun criterio puesto? Solo se usa para decidir que hacer con un filtro cuyo
     * key NO es columna: con criterio → 422; sin criterio → se saltea.
     *
     * 🔴 Tiene que FALLAR CERRADO: todo valor que alguna rama de apply() aplicaria sobre una columna
     * cuenta como criterio. Los unicos inertes son los que la SPA usa para "vacio"
     * (build_table_filters_from_props() y limpiar_filtro() de common-vue), que la SPA manda tambien
     * en los filtros que nadie toco:
     *  - igual_que: null, '' o el ENTERO 0 (el vacio de select/search). El texto '0' es un criterio
     *    real en texto, numero y select (esas ramas arman `columna = '0'`); solo en search su rama
     *    lo ignora (`!= 0`). Antes se lo trataba como vacio en todos los tipos, y en una masiva un
     *    "igual a 0" sobre un key que no es columna desaparecia en silencio y la operacion corria
     *    con el resto de los filtros (verificador independiente, 5/10/2026).
     *  - checkbox: -1 (o '-1'). '' NO es vacio: la rama lo aplica (`'' != -1`).
     *  - en_blanco / no_en_blanco falsos; ordenar_de vacio; que_contenga / menor_que / mayor_que ''.
     * Los vacios que manda la SPA son 0 en el igual_que de select/search, '' en el de text / number /
     * date (y en search despues de BtnRestartFilter), -1 en checkbox: todos siguen inertes. La SPA
     * nunca manda '0' como vacio ni '' en checkbox. Unica diferencia que queda, a sabiendas: un
     * igual_que '' en select, que su rama aplicaria (`!== 0`) y aca es vacio; solo puede llegar por
     * los GET con los filtros en JSON (en POST/PUT ConvertEmptyStringsToNull lo vuelve null y la rama
     * tampoco lo aplica), la SPA no lo manda y en un key que no es columna solo significa "no se
     * filtra por eso". Las comparaciones son estrictas: con `==` de PHP 7.4, 'abc' == 0 es verdadero y
     * un criterio de texto pasaria por vacio.
     *
     * @param  array  $filter
     * @return bool
     */
    protected static function tiene_criterio(array $filter)
    {
        if (isset($filter['ordenar_de']) && $filter['ordenar_de'] != '') {
            return true;
        }

        if (isset($filter['en_blanco']) && (boolean) $filter['en_blanco']) {
            return true;
        }

        if (isset($filter['no_en_blanco']) && (boolean) $filter['no_en_blanco']) {
            return true;
        }

        foreach (['que_contenga', 'menor_que', 'mayor_que'] as $campo) {
            if (isset($filter[$campo]) && $filter[$campo] !== '') {
                return true;
            }
        }

        if (isset($filter['igual_que'])) {
            $tipo = isset($filter['type']) ? $filter['type'] : null;

            // El vacio de igual_que; '0' solo en search, la unica rama que lo ignora.
            $vacios = ($tipo === 'search') ? ['', 0, '0'] : ['', 0];

            if (!in_array($filter['igual_que'], $vacios, true)) {
                return true;
            }
        }

        if (isset($filter['checkbox']) && !in_array($filter['checkbox'], [-1, '-1'], true)) {
            return true;
        }

        return false;
    }

    /**
     * Direccion de orden normalizada ('asc' / 'desc'), o null si el pedido trae otra cosa.
     *
     * @param  mixed  $valor  ordenar_de tal como llego (la SPA manda 'ASC' / 'DESC').
     * @return string|null
     */
    protected static function direccion_de_orden($valor)
    {
        if (!is_string($valor)) {
            return null;
        }

        $direccion = strtolower(trim($valor));

        return ($direccion === 'asc' || $direccion === 'desc') ? $direccion : null;
    }

    /**
     * La UNICA guarda para invocar un metodo del modelo por un nombre que sale del pedido: devuelve
     * la relacion de Eloquent si $metodo es una relacion real del modelo, o null.
     *
     * Por que hace falta: `whereHas($key)`, el "en blanco" de un FK (`<relacion>_id`), el orden por
     * la columna visible de una relacion y las relation_props de global-search INVOCAN el metodo cuyo
     * nombre manda el pedido sobre una instancia nueva del modelo. Sin guarda, `save` / `touch` /
     * `push` (Model) o `restore` (SoftDeletes) escriben en la base.
     *
     * Las condiciones, en orden, y todas antes de invocar nada:
     *  - identificador valido;
     *  - no es un accessor/mutator (`get...Attribute` / `set...Attribute`) ni un scope;
     *  - existe, es publico, no estatico y sin parametros obligatorios;
     *  - 🔴 esta DECLARADO EN UN ARCHIVO DENTRO DE app/. Esta es la condicion que importa y la que
     *    alguien estaria tentado de "simplificar" a mirar la clase declarante: para un metodo de un
     *    trait (SoftDeletes::restore, HasFactory...) PHP reporta como clase declarante AL MODELO,
     *    pero como archivo el del trait, en vendor/. Mirar la clase dejaba pasar `restore`. Mirar el
     *    archivo excluye Model, todos los traits de Illuminate y cualquier cosa de vendor/;
     *  - si declara un tipo de retorno, que sea una Relation;
     *  - recien ahi se invoca (en try/catch: un metodo que tira se descarta) y lo que devuelve
     *    tiene que ser una Relation.
     *
     * Medido el 5/10/2026: en app/Models no hay metodos publicos sin parametros con efectos que
     * pasen todas las condiciones de antes de invocar salvo relaciones (y accessors, que se excluyen
     * por nombre). Si alguien agrega uno, la ultima condicion igual lo descarta, pero ya lo habra
     * ejecutado: no declarar en un modelo metodos publicos sin parametros que escriban.
     *
     * @param  string  $model_name  Clase Eloquent del modelo.
     * @param  mixed   $metodo      Nombre del metodo tal como llego del pedido.
     * @return \Illuminate\Database\Eloquent\Relations\Relation|null
     */
    public static function relacion_real($model_name, $metodo)
    {
        if (!is_string($metodo) || !preg_match(self::PATRON_IDENTIFICADOR, $metodo)) {
            return null;
        }

        // Sin distinguir mayusculas (`/i`): PHP resuelve los metodos sin distinguirlas, asi que
        // "getAmountsByStatusattribute" invoca el mismo accessor que "getAmountsByStatusAttribute"
        // (medido por el verificador: 3 SELECT). En app/Models no hay relaciones que empiecen con
        // "scope" ni terminen en "attribute", asi que el /i no deja afuera ninguna.
        if (preg_match('/^(get|set)[A-Za-z0-9_]*Attribute\z/i', $metodo) || preg_match('/^scope[A-Z_]/i', $metodo)) {
            return null;
        }

        if (!is_string($model_name) || !class_exists($model_name)) {
            return null;
        }

        $instancia = new $model_name();

        if (!method_exists($instancia, $metodo)) {
            return null;
        }

        try {
            $reflexion = new \ReflectionMethod($instancia, $metodo);
        } catch (\ReflectionException $e) {
            return null;
        }

        if (!$reflexion->isPublic()
            || $reflexion->isStatic()
            || $reflexion->getNumberOfRequiredParameters() > 0
            || !self::declarado_en_app($reflexion)) {
            return null;
        }

        // Un tipo de retorno declarado que no es una relacion alcanza para descartarlo sin invocar.
        if ($reflexion->hasReturnType()) {
            $tipo = $reflexion->getReturnType();

            if (!($tipo instanceof \ReflectionNamedType)
                || $tipo->isBuiltin()
                || !is_a($tipo->getName(), Relation::class, true)) {
                return null;
            }
        }

        try {
            $relacion = $instancia->{$metodo}();
        } catch (\Throwable $e) {
            return null;
        }

        return $relacion instanceof Relation ? $relacion : null;
    }

    /**
     * ¿El metodo esta escrito en un archivo de app/? (ver relacion_real()).
     *
     * @param  \ReflectionMethod  $reflexion
     * @return bool
     */
    protected static function declarado_en_app(\ReflectionMethod $reflexion)
    {
        $archivo = $reflexion->getFileName();

        // Metodos internos de PHP (sin archivo).
        if (!is_string($archivo) || $archivo === '') {
            return false;
        }

        $archivo = realpath($archivo);

        if (is_null(self::$ruta_de_app)) {
            self::$ruta_de_app = realpath(app_path());
        }

        if ($archivo === false || self::$ruta_de_app === false) {
            return false;
        }

        $archivo = str_replace('\\', '/', $archivo);
        $app     = rtrim(str_replace('\\', '/', self::$ruta_de_app), '/') . '/';

        // En Windows (las maquinas de desarrollo) el sistema de archivos no distingue mayusculas.
        if (DIRECTORY_SEPARATOR === '\\') {
            return strncasecmp($archivo, $app, strlen($app)) === 0;
        }

        return strncmp($archivo, $app, strlen($app)) === 0;
    }

    /**
     * Filtro de presencia sobre una relacion (tipo `images`): `en_blanco` = sin ningun registro de
     * la relacion (whereDoesntHave), `no_en_blanco` = con al menos uno (whereHas). Misión
     * imagenes-catalogo-completo (27/9/2026): "Sin imágenes" / "Con imágenes" del listado.
     *
     * Solo se aplica si la key es una relacion real del modelo, declarada en un archivo de app/ (el
     * modelo o un trait propio; ver is_own_relation() y relacion_real()); si no, el filtro se ignora
     * sin romper la busqueda y no se anota en used_filters. Sin en_blanco ni no_en_blanco tampoco
     * hace nada. Hasta el 5/10/2026 se exigia el archivo del modelo; desde la mision
     * filtros-key-sin-inyeccion la regla es la de relacion_real(), la misma para todas las entradas.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $models
     * @param  array                                 $filter
     * @param  string                                $model_name  Clase Eloquent del modelo.
     * @return array  ['models' => Builder, 'used_filter' => array|null]
     */
    protected static function apply_images_presence_filter($models, $filter, $model_name)
    {
        $key = isset($filter['key']) && is_string($filter['key']) ? $filter['key'] : '';

        $en_blanco    = isset($filter['en_blanco']) && (boolean) $filter['en_blanco'];
        $no_en_blanco = !$en_blanco && isset($filter['no_en_blanco']) && (boolean) $filter['no_en_blanco'];

        if ((!$en_blanco && !$no_en_blanco) || !self::is_own_relation($model_name, $key)) {
            return ['models' => $models, 'used_filter' => null];
        }

        $models = $en_blanco ? $models->whereDoesntHave($key) : $models->whereHas($key);

        return [
            'models'      => $models,
            'used_filter' => [
                'key'       => $key,
                'operator'  => $en_blanco ? 'en_blanco' : 'no_en_blanco',
                'value'     => true,
                'type'      => $filter['type'],
            ],
        ];
    }

    /**
     * ¿La key es una relacion real del modelo? (Conserva el nombre de antes, pero ya no exige que
     * este declarada en el archivo del propio modelo: vale cualquier archivo de app/, como en
     * relacion_real().)
     *
     * La key sale del request y whereHas() la invoca como metodo sobre una instancia nueva del
     * modelo: sin guarda, una key como `restore` (SoftDeletes) o `save` terminaria llamando a ese
     * metodo. La guarda es relacion_real(), la misma para todo lo que invoca un metodo por nombre.
     *
     * @param  string $model_name
     * @param  string $key
     * @return bool
     */
    protected static function is_own_relation($model_name, $key)
    {
        if (!is_string($key) || !preg_match(self::PATRON_IDENTIFICADOR, $key) || !class_exists($model_name)) {
            return false;
        }

        return !is_null(self::relacion_real($model_name, $key));
    }

    /**
     * Resuelve la relacion belongsTo detras de un filtro select/search por FK ("<relacion>_id"),
     * para que "en blanco" / "no en blanco" coincidan con lo que el listado muestra.
     *
     * Causa del bug (servian, 23/9/2026): el listado embebe la relacion (ej: `provider`) y un
     * registro borrado con soft delete no se carga, asi que la celda se ve vacia — pero el FK sigue
     * apuntando al registro borrado (`ProviderController::destroy` no toca los articulos). El filtro
     * miraba solo `FK IS NULL OR FK = 0` y esos articulos no aparecian nunca. En servian2 eran
     * 29.682 articulos.
     *
     * Devuelve null cuando la key no es un belongsTo resoluble cuyo FK sea justamente la key: en
     * ese caso el filtro se comporta exactamente como antes.
     *
     * @param string $model_name Clase Eloquent del modelo filtrado.
     * @param string $key        Key del filtro (ej: provider_id), ya validada como columna.
     * @return array|null ['table' => tabla relacionada, 'owner_key' => pk referenciada,
     *                     'own_table' => tabla filtrada, 'deleted_at' => columna soft delete o null]
     */
    protected static function relation_for_blank_check($model_name, $key)
    {
        if (!is_string($key) || strlen($key) <= 3 || substr($key, -3) !== '_id') {
            return null;
        }

        // La key sale del request: el metodo `<relacion>` se invoca SOLO si es una relacion real
        // (ver relacion_real()). Un `save_id` o `touch_id` llamaria a save()/touch() de Eloquent, y
        // un `restore_id` a SoftDeletes::restore(), que hace save().
        $relation = self::relacion_real($model_name, substr($key, 0, -3));

        // MorphTo extiende BelongsTo pero no tiene una tabla ni un owner key fijos.
        if (!($relation instanceof BelongsTo)
            || $relation instanceof MorphTo
            || $relation->getForeignKeyName() !== $key) {
            return null;
        }

        $instance = $relation->getParent();
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
     * @param string                             $key           FK en la tabla filtrada (validada).
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
     * @param array                                 $filter     Filtro (type y order_relation_prop).
     * @param string                                $columna    Key del filtro YA VALIDADA como columna.
     * @param string                                $direccion  'asc' o 'desc', ya normalizada.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected static function apply_order_filter($models, $model_name, $filter, $columna, $direccion)
    {
        // Tipo del filtro (number, text, date, select, search, ...).
        $type = isset($filter['type']) ? $filter['type'] : null;

        // Solo intentamos orden por relacion cuando el filtro es de relacion (select/search) y su
        // key tiene forma de FK "<algo>_id". El resto ordena directo por la columna.
        $is_relation_filter = ($type === 'select' || $type === 'search')
            && strlen($columna) > 3
            && substr($columna, -3) === '_id';

        if ($is_relation_filter) {
            // Nombre del metodo de relacion por convencion: category_id -> category. Se invoca SOLO
            // si es una relacion real (relacion_real()): un `save_id` con ordenar_de ejecutaba
            // (new Modelo)->save() antes de esta guarda.
            $relation = self::relacion_real($model_name, substr($columna, 0, -3));

            // Solo belongsTo tiene un unico FK ordenable de forma correlacionada. MorphTo extiende
            // BelongsTo pero no tiene una tabla relacionada fija: cae al orden directo.
            if ($relation instanceof BelongsTo && !($relation instanceof MorphTo)) {
                // Modelo y tabla relacionados (ej: categories), tomados del propio Eloquent.
                $related = $relation->getRelated();
                $related_table = $related->getTable();
                // Clave primaria referenciada en la tabla relacionada (normalmente id).
                $related_key = $relation->getOwnerKeyName();
                // Tabla del modelo que se esta filtrando (ej: articles).
                $own_table = $relation->getParent()->getTable();

                // Columna visible de la relacion: la que manda el front (order_relation_prop)
                // o "name" por defecto. Para el IVA, por ejemplo, el front manda "percentage".
                $pedida = (isset($filter['order_relation_prop']) && $filter['order_relation_prop'] != '')
                    ? $filter['order_relation_prop']
                    : 'name';

                // 🔴 order_relation_prop tambien sale del pedido y entra al SQL de la subconsulta:
                // tiene que ser una columna real de la tabla RELACIONADA. Si no lo es (o la tabla
                // no tiene `name`, como afip_informations o addresses), se ordena por el FK en vez
                // de dar 500 como hasta el 5/10/2026.
                $order_column = self::columna_valida($pedida, self::columnas_de_la_tabla($related));

                if (!is_null($order_column)) {
                    // Subconsulta correlacionada: por cada fila del modelo trae el valor visible de
                    // su relacion (ej: el name de su categoria) para usarlo como criterio de orden.
                    $order_subquery = \Illuminate\Support\Facades\DB::table($related_table)
                        ->select($related_table.'.'.$order_column)
                        ->whereColumn($related_table.'.'.$related_key, $own_table.'.'.$columna)
                        ->limit(1);

                    // Ordenamos por el resultado de la subconsulta (por el nombre de la relacion).
                    return $models->orderBy($order_subquery, $direccion);
                }
            }
        }

        // Comportamiento previo: orden directo por la columna propia del modelo.
        return $models->orderBy($columna, $direccion);
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
