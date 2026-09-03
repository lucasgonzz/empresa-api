<?php

namespace App\Http\Controllers\CommonLaravel;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\ColumnFiltersHelper;
use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\ExtraFiltersHelper;
use App\Http\Controllers\Helpers\GlobalSearchMatchesHelper;
use App\Http\Controllers\Helpers\GlobalSearchQueryHelper;
use App\Http\Controllers\Helpers\sale\SaleArticlesEagerLoadHelper;
use App\Http\Controllers\Helpers\VenderSearchHelper;
use App\Services\Filter\FilterHistoryService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SearchController extends Controller
{
    /**
     * @param bool $return_raw_models Si true (solo uso interno), devuelve la colección/paginador sin armar JSON ni log de historial.
     */
    /**
     * Query base del modelo, con el filtro por usuario SOLO si la tabla lo admite.
     *
     * 🔴 NO TODAS LAS TABLAS SON POR USUARIO, Y APLICARLES EL FILTRO ES UN 500.
     *
     * `current_acount_payment_methods`, por ejemplo, es un catalogo GLOBAL de la base: no tiene
     * columna `user_id` y su propio index() no filtra. Cuando el buscador le aplicaba el filtro
     * igual, el `paginate()` de mas abajo reventaba con
     *
     *     SQLSTATE[42S22]: Unknown column 'user_id' in 'WHERE'
     *     (select count(*) as aggregate from `current_acount_payment_methods` where `user_id` = 2700)
     *
     * Medido en la produccion de masquito el 3/9/2026: diez veces en un solo dia, y le pasa a
     * cualquier cliente en 4.x que abra ese buscador.
     *
     * Es la misma clase de problema que ya estaba resuelta para `withAll()` unas lineas mas abajo,
     * y por el mismo motivo: este endpoint dejo de usarse a mano y ahora lo dispara el listado por
     * defecto de cada modulo con solo entrar, asi que llega a modelos a los que antes nadie lo
     * llevaba. Aquello se blindo con method_exists; esto faltaba.
     *
     * ⚠️ Que una tabla global no se filtre NO afloja ningun aislamiento entre clientes: cada
     * cliente tiene su propia base de datos. El `user_id` de estas tablas separa a los usuarios de
     * UN mismo comercio, y las que no lo tienen es porque su contenido es del comercio entero.
     *
     * @param  string  $model_name  Clase del modelo, con namespace.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function query_base_del_modelo($model_name)
    {
        $instancia = new $model_name();

        if (! Schema::hasColumn($instancia->getTable(), 'user_id')) {
            return $model_name::query();
        }

        return $model_name::where('user_id', $this->userId());
    }

    function search(Request $request, $model_name_param, $_filters = null, $paginate = 0, $return_used_filters = false, $return_raw_models = false) {
        $model_name = GeneralHelper::getModelName($model_name_param);
        $models = $this->query_base_del_modelo($model_name);

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

        $column_filters_result = ColumnFiltersHelper::apply($models, $filters, $model_name_param, $model_name);
        $models = $column_filters_result['models'];
        $used_filters = $column_filters_result['used_filters'];

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
     * Crea un modelo "al vuelo" desde el buscador, cuando el usuario escribe un valor
     * que todavia no existe (ej: escribe una localidad nueva en el buscador de localidades).
     *
     * @param Request $request Trae user_id implicito, depends_on_key/depends_on_value opcional
     *                         (para completar la dependencia, ej: category_id de una sub categoria)
     *                         y properties_to_set (pares key/value adicionales que arma el front).
     * @param string $_model_name Nombre del modelo en singular (ej: 'combo', 'location').
     * @param string $property Propiedad que se esta buscando/creando (ej: 'name').
     * @param string $query Valor tipeado por el usuario que no matcheo ningun resultado existente.
     * @return \Illuminate\Http\JsonResponse 201 con el modelo creado, o 422 si faltan datos obligatorios.
     */
    function saveIfNotExist(Request $request, $_model_name, $property, $query) {
        $model_name = GeneralHelper::getModelName($_model_name);
        $data = [];
        // Calculo de nombre de tabla en plural (regla existente: termina en 'y' -> 'ies', si no + 's').
        if (substr($_model_name, strlen($_model_name)-1) == 'y') {
            $model_name_plural = substr($_model_name, 0, strlen($_model_name)-1).'ies';
        } else {
            $model_name_plural = $_model_name.'s';
        }

        $data['user_id'] = $this->userId();
        $data[$property] = $query;

        /** num solo si la tabla realmente tiene la columna: saveIfNotExist es generico y hay modelos sin num. */
        if (Schema::hasColumn($model_name_plural, 'num')) {
            $data['num'] = $this->num($model_name_plural);
        }

        /** La dependencia del buscador (ej: category_id al crear una sub categoria) llega en el mismo par que ya usa searchFromModal. */
        if ($request->filled('depends_on_key') && $request->filled('depends_on_value')) {
            $data[$request->depends_on_key] = $request->depends_on_value;
        }

        // Propiedades adicionales que arma el front (por modelo). Se saltean las que no traen
        // 'key' o cuyo 'value' viene vacio, para no pisar defaults de columnas numericas con ''.
        $properties_to_set = $request->input('properties_to_set', []);
        if (is_array($properties_to_set)) {
            foreach ($properties_to_set as $property_to_set) {
                if (!is_array($property_to_set) || !isset($property_to_set['key'])) {
                    continue;
                }
                if (!isset($property_to_set['value']) || $property_to_set['value'] === '') {
                    continue;
                }
                $data[$property_to_set['key']] = $property_to_set['value'];
            }
        }

        try {

            // Intento de creacion real. Si la base rechaza el insert (ej: columna NOT NULL sin valor),
            // se captura abajo en vez de dejar que explote como 500 mudo.
            $model = $model_name::create($data);

        } catch (QueryException $e) {

            // Log con el payload completo para poder diagnosticar el proximo caso sin adivinar.
            Log::info('saveIfNotExist fallo para '.$_model_name.'. Data: '.json_encode($data).'. Error: '.$e->getMessage());

            return response()->json([
                'message' => 'No se pudo crear '.$_model_name.' porque faltan datos obligatorios. Cargalo desde su formulario completo.',
            ], 422);
        }

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
        $models = $this->query_base_del_modelo($model_name)
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

        /**
         * Filtro opcional por sucursal que NO deja afuera a los que no tienen sucursal asignada.
         *
         * Regla unica del sistema (decision de Lucas, 11/8/2026): un cliente sin sucursal
         * asignada aparece en TODAS las sucursales.
         *
         * Por que no se reusa depends_on_key/depends_on_value: ese mecanismo hace un where
         * estricto sobre la columna, o sea exactamente lo contrario de lo decidido.
         *
         * Es opcional a proposito: sin el parametro (o con 0/vacio) la query queda igual que
         * antes de este cambio, asi que ningun otro modelo que use search-from-modal cambia
         * de comportamiento.
         */
        $address_id_con_nulos = (int) $request->input('address_id_con_nulos', 0);
        if ($address_id_con_nulos > 0) {
            $models->where(function ($subquery) use ($address_id_con_nulos) {
                $subquery->where('address_id', $address_id_con_nulos)
                        ->orWhereNull('address_id')
                        // El orWhere de 0 NO es redundante: el select "Sucursal" del formulario
                        // del cliente tiene value: 0 como valor inicial, asi que en la base
                        // conviven null y 0 para el mismo significado ("sin sucursal"). Sacarlo
                        // deja afuera a todos los clientes guardados desde ese formulario.
                        ->orWhere('address_id', 0);
            });
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
     *                   cero, 'todos' = sin filtro), 'address_stock_seteado' (filtra por RELACIÓN y
     *                   no por columna: deja pasar los modelos a los que se les cargó la sucursal
     *                   indicada, cualquiera sea el valor del pivote; valor 0 = todas), y los legacy
     *                   'category' (fuerza category_id) y 'stock_option' (con_stock /
     *                   hayan_tenido_stock / sin_stock / con_o_sin_stock).
     *                   Cualquier otro operador se ignora en silencio. La key se valida contra el
     *                   schema de la tabla salvo para 'category' y 'address_stock_seteado', que no
     *                   filtran por una columna del modelo y por lo tanto no tienen key que validar.
     * - filters         (array)  Filtros de columna del listado (los de la lupa de cada header),
     *                   misma forma exacta que recibe `search`. Delegado en
     *                   `ColumnFiltersHelper::apply`, el mismo helper que usa `search` — nunca
     *                   reimplementar un pedazo de esa traducción acá. Se AND'ean con el grupo de
     *                   coincidencia de texto y con `extra_filters`. Si alguno de estos filtros trae
     *                   `ordenar_de`, ese orden tiene PRIORIDAD sobre `order_by`/`order_direction`
     *                   (ver más abajo).
     * - per_page        (int)    Tamaño de página (clamp 1..200, default 50). Página vía ?page=.
     * - contexto        (string|null) (Prompt 04, grupo 179) Flag opcional que declara que la
     *                   llamada viene de un módulo con lógica propia de búsqueda. Únicamente
     *                   soportado hoy para el modelo `article`, whitelist estricta en
     *                   `VenderSearchHelper::is_valid_contexto`:
     *                     - 'vender': excluye insumos, agrega coincidencia EXACTA de código de
     *                       barras (artículo y variantes) cuando el criterio es una sola palabra, y
     *                       expande las variantes de cada artículo en filas propias con su propio
     *                       precio/imágenes/depósitos (mismo comportamiento que
     *                       `VenderController::search_nombre`).
     *                     - 'provider_order' / 'recipe': igual que 'vender' pero SIN excluir
     *                       insumos (pedido a proveedor y receta los necesitan).
     *                   Sin `contexto` válido (o con un modelo distinto de `article`), `globalSearch`
     *                   sigue su camino normal: sin variantes expandidas, sin excluir insumos,
     *                   paginado con `paginate()` de Eloquent. La forma de la respuesta es SIEMPRE
     *                   la misma (`{ models: { data, last_page, total, ... } }`), tenga o no
     *                   `contexto` — nunca la forma plana de `search_nombre`.
     *
     * @param Request $request
     * @param string $model_name_param Nombre del modelo en snake_case (ej: 'article').
     * @return \Illuminate\Http\JsonResponse `{ models: <paginador>, matches: <desglose>|null }`.
     *         `models` tiene la misma forma que `search` (paginador con data/last_page/total).
     *         `matches` (grupo 274, ver `GlobalSearchMatchesHelper`) es el desglose de que
     *         propiedad aporto a cada resultado de esta página: `null` sin criterio de texto, con
     *         contexto Vender, o si no hay ninguna propiedad tildada por la que desglosar.
     *         O un JSON de error controlado (400) si el modelo/prop/relación pedida no existe.
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

        // Filtros de columna del listado (los de la lupa de cada header). Misma forma exacta que los
        // que recibe `search`: los traduce a SQL ColumnFiltersHelper, que es el mismo helper que usa
        // aquel endpoint — nunca reimplementar un pedazo de esa traduccion aca.
        $column_filters = $request->input('filters', []);

        // Conector entre el grupo estricto ('todas') y el grupo distribuido ('alguna') de props y
        // relaciones. Default 'or': alcanza con que se cumpla uno de los dos grupos.
        $conector = $request->input('conector', 'or');

        // Contexto opcional (Prompt 04, grupo 179): declara que la llamada viene de un módulo con
        // lógica propia de búsqueda (hoy, Vender / pedido a proveedor / receta). Ver PHPDoc arriba.
        $contexto = $request->input('contexto', null);

        // Solo el modelo article tiene lógica de contexto (Vender busca artículos). Cualquier otro
        // modelo, o un contexto fuera de la whitelist, sigue el camino normal sin tocar nada de esto.
        $usar_contexto_vender = $model_name_param == 'article' && VenderSearchHelper::is_valid_contexto($contexto);

        // Instancia temporal del modelo, usada solo para chequear existencia de columnas/relaciones
        // antes de armar la query (evita 500 por prop o relación inexistente).
        $model_instance = new $model_name();

        // Nombre de la tabla del modelo, para resolver tipo de columna vía el schema builder.
        $table = $model_instance->getTable();

        // Query base: solo el filtro por usuario. El withAll() se aplica aparte (ver abajo), porque
        // no todos los modelos lo definen.
        $models = $this->query_base_del_modelo($model_name);

        // withAll() solo si el modelo lo define. De los ~273 modelos en app/Models, solo ~197
        // definen scopeWithAll; los ~76 restantes tiraban BadMethodCallException (500) apenas se
        // llamaba a ->withAll() sobre ellos. Hasta ahora este endpoint se usaba a mano y nadie
        // buscaba en esos modelos, pero el listado por defecto (prompt 03 de este grupo) dispara
        // esta misma llamada solo con entrar a cualquier módulo, así que hay que blindarlo acá.
        if (method_exists($model_instance, 'scopeWithAll')) {
            $models = $models->withAll();
        }

        // Exclusión de insumos (contexto Vender), aplicada ANTES del grupo de coincidencia de texto
        // para que quede AND'eada con el resto de la query, igual que search_nombre.
        if ($usar_contexto_vender) {
            $models = VenderSearchHelper::apply_conditions($models, $query_value, $contexto);
        }

        // Callback de condiciones extra de texto (coincidencia exacta de código de barras, artículo
        // y variantes), solo con contexto Vender válido. Se le pasa a GlobalSearchQueryHelper::apply
        // para que quede DENTRO del mismo grupo de coincidencia de texto (no como un AND aparte).
        $extra_text_conditions = $usar_contexto_vender ? VenderSearchHelper::bar_code_condition_callback() : null;

        // Grupo de coincidencia de texto (props + relaciones, con su keyword_mode y el conector),
        // delegado en GlobalSearchQueryHelper. Reemplaza el armado inline que antes mezclaba OR de
        // props con AND de palabras adentro de cada una (ver PHPDoc del helper para la lógica de
        // los dos modos y su combinación).
        $models = GlobalSearchQueryHelper::apply($models, $query_value, $props, $relation_props, $conector, $table, $model_instance, $extra_text_conditions);

        // AND de filtros extra, fuera del closure del grupo OR para que sean condiciones AND reales.
        // Delegado en ExtraFiltersHelper::apply (whitelist de operadores genéricos: '=', 'like',
        // comparación numérica '>','<','>=','<=', 'numeric_presence', 'address_stock_seteado', y los
        // legacy 'category' y 'stock_option'). Cualquier operador fuera de la whitelist se ignora en silencio (no se
        // ejecuta SQL arbitrario con la key/valor que venga del request).
        $models = ExtraFiltersHelper::apply($models, $table, $extra_filters);

        // Filtros de columna del listado, delegados en ColumnFiltersHelper (mismo helper que usa
        // `search`). Se aplican DESPUES de ExtraFiltersHelper::apply (AND real, no dentro del
        // closure del grupo OR de texto) y ANTES del orderBy de mas abajo: si alguno de estos
        // filtros trae `ordenar_de`, el helper ya le agrega su propio orderBy acá, y el orderBy de
        // `order_by`/`order_direction` queda como criterio secundario. Es lo que corresponde: si el
        // usuario toco explicitamente la flecha de una columna, ese orden manda sobre el default
        // (created_at DESC). Aplicarlo despues invertiria la prioridad, y el sintoma seria "toco la
        // flecha y no pasa nada" — dificil de rastrear justamente porque no falla, solo ordena por
        // otra cosa.
        $column_filters_result = ColumnFiltersHelper::apply($models, $column_filters, $model_name_param, $model_name);
        $models = $column_filters_result['models'];
        $used_column_filters = $column_filters_result['used_filters'];

        // Modelos que solo se buscan entre los ACTIVOS, porque asi los devuelve el index que llena
        // su store y ese es el universo que el usuario espera ver en un buscador.
        //
        // `provider` se agrego el 10/8/2026 (hallazgo 20260805-global-search-provider-sin-filtro-status).
        // El grupo 352 paso la busqueda de proveedores del store a la API, y las dos fuentes no
        // coincidian: ProviderController::index() filtra where('status', 'active') (linea 25) y este
        // endpoint no filtraba nada. Desde ese cambio el buscador ofrecia proveedores dados de baja
        // en el alta de compra, en el campo proveedor del articulo y en el cheque endosado. No fue
        // una regresion de codigo —el codigo nuevo hacia lo que decia—: cambio la fuente de datos y
        // con ella el resultado, sin que nada lo avisara.
        //
        // La lista es explicita a proposito. Este endpoint es generico para ~150 modelos y muchas
        // tablas tienen una columna `status` que NO significa alta/baja (estados de venta, de
        // importacion, etc.): filtrar solo por tener la columna haria desaparecer registros validos
        // en silencio, que es exactamente el modo de falla que este arreglo viene a cerrar.
        $model_names_solo_activos = ['article', 'provider'];

        if (in_array($model_name_param, $model_names_solo_activos, true)) {
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

        // Orden configurable por request, validado contra el schema real de la tabla (nunca contra
        // una lista fija): este endpoint es genérico para ~150 modelos del sistema y mantener a
        // mano una whitelist de columnas por modelo sería inviable. El valor de `order_by` SIEMPRE
        // se valida con Schema::hasColumn ANTES de llegar a orderBy(); nunca se concatena en un
        // string de SQL ni se pasa por orderByRaw, así se evite cualquier chance de inyección.
        $order_direction = strtoupper(trim((string) $request->input('order_direction', 'DESC')));
        if ($order_direction !== 'ASC' && $order_direction !== 'DESC') {
            $order_direction = 'DESC';
        }

        $order_by = trim((string) $request->input('order_by', ''));
        if ($order_by === '' || !Schema::hasColumn($table, $order_by)) {
            // Sin valor, o columna que no existe en la tabla real: se cae al comportamiento de
            // siempre (created_at) sin lanzar error, para que el buscador nunca rompa por una key
            // rara mandada desde el front.
            $order_by = 'created_at';
        }

        $models = $models->orderBy($order_by, $order_direction);

        if ($usar_contexto_vender) {

            // La expansión de variantes cambia la cantidad de filas: no se puede paginar con
            // paginate() de Eloquent (paginaría ANTES de expandir). Se trae todo con get() y se
            // pagina a mano, igual que hace hoy search_nombre.
            $articles = $models->get();

            $results = VenderSearchHelper::expand_variants($articles, $query_value);

            $current_page = LengthAwarePaginator::resolveCurrentPage();

            $models = new LengthAwarePaginator(
                $results->forPage($current_page, $per_page)->values(),
                $results->count(),
                $per_page,
                $current_page
            );
        } else {

            $models = $models->paginate($per_page);
        }

        // Historial de filtrados (Grupo 273, Prompt 02): `search()` ya lo registraba para article,
        // pero si el listado pasa a filtrar por `globalSearch`, ese historial dejaba de registrarse
        // sin que nadie lo note. Solo si HUBO filtros de columna usados: `globalSearch` tambien lo
        // dispara el listado por defecto de cada modulo, que corre solo con entrar — registrar eso
        // incondicionalmente (como hace `search()`) llenaria el historial de entradas vacias, una
        // por cada vez que alguien abre el listado.
        if ($model_name_param == 'article' && count($used_column_filters)) {

            FilterHistoryService::log_action([
                'user_id'             => $this->userId(true),
                'auth_user_id'        => $this->userId(false),
                'action'              => 'busqueda',
                'model_name'          => 'article',
                'filtrados_count'     => $models->total(),
                'afectados_count'     => 0,
                'used_filters'        => $used_column_filters,
            ]);
        }

        // Desglose de coincidencias (grupo 274): por que propiedad aparecio cada resultado de esta
        // pagina. Se calcula en PHP sobre las filas ya traidas -- ninguna consulta nueva salvo el
        // loadMissing de relaciones que no hubiera traido withAll(). Devuelve null cuando no hay
        // criterio de texto (listado por defecto) o cuando no hay nada que desglosar.
        $matches = null;

        if (!$usar_contexto_vender) {
            $matches = GlobalSearchMatchesHelper::build(
                $models->getCollection(),
                $query_value,
                $props,
                $relation_props,
                $table,
                $model_instance
            );
        }

        return response()->json(['models' => $models, 'matches' => $matches], 200);
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
}
