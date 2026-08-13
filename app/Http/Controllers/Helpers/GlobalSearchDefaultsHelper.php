<?php

namespace App\Http\Controllers\Helpers;

/**
 * Propiedades que arrancan tildadas en el buscador general, por modelo, del lado de la API.
 *
 * Es la tabla que siembra `GlobalSearchDefaultsSeeder` en `table_column_preferences`
 * (preference_type `global_search`).
 *
 * 🔴 ESTA TABLA ESTA DUPLICADA A PROPOSITO. Su espejo en el front es
 * `empresa-spa/src/common-vue/config/global_search_defaults.js`, que es el que aplica el default
 * mientras no hay fila guardada. Si tocas esta tabla, toca tambien ese archivo: las dos listas
 * tienen que coincidir key por key y en el mismo orden.
 *
 * No se unifican con un endpoint: el front necesita el mapa de forma sincronica para el instante
 * anterior a que responda el GET de la preferencia, y para las bases donde el seeder todavia no
 * corrio.
 */
class GlobalSearchDefaultsHelper
{
    /**
     * model_name => keys tildadas por defecto, en el orden en que se guardan.
     *
     * Las keys van planas, sin separar propias de relaciones: la clasificacion la hace el
     * componente del front contra own_props / relation_props. Las que terminan en `_id` son
     * relaciones y necesitan su metodo en el modelo Eloquent (ver `relation_methods_faltantes()`).
     */
    const DEFAULTS = [
        'article'         => ['bar_code', 'provider_code', 'name'],
        'sale'            => ['num', 'client_id', 'employee_id', 'observations'],
        'provider'        => ['name', 'razon_social'],
        'provider_order'  => ['num', 'provider_id'],
        'client'          => ['name', 'cuit', 'razon_social'],
        'seller'          => ['name'],
        'order'           => ['num', 'buyer_id'],
        'buyer'           => ['name', 'email'],
        'caja'            => ['name', 'address_id', 'employee_id'],
        'movimiento_caja' => ['concepto_movimiento_caja_id'],
    ];

    /**
     * Modelos que declara la tabla. Es el universo que toca el seeder: ningun model_name fuera
     * de esta lista se siembra ni se borra.
     *
     * @return array
     */
    public static function model_names()
    {
        return array_keys(self::DEFAULTS);
    }

    /**
     * Keys tildadas por defecto de un modelo. Los modelos que no estan en la tabla mantienen el
     * comportamiento de siempre ('name' y nada mas), igual que el fallback del front.
     *
     * @param string $model_name
     * @return array
     */
    public static function keys_for($model_name)
    {
        if (isset(self::DEFAULTS[$model_name])) {
            return self::DEFAULTS[$model_name];
        }

        return ['name'];
    }

    /**
     * Array `columns` listo para persistir en `table_column_preferences`, con la misma forma que
     * produce TableColumnPreferenceController::normalize_column_payload().
     *
     * Solo se enumeran las keys tildadas: applySelectionFromColumns() del front unicamente mira
     * las columnas `visible`, y la proxima busqueda del usuario reescribe la fila completa.
     *
     * @param string $model_name
     * @return array
     */
    public static function columns_for($model_name)
    {
        $columns = [];
        $order = 0;

        foreach (self::keys_for($model_name) as $key) {
            $columns[] = [
                'key'          => $key,
                'visible'      => true,
                'order'        => $order,
                'width'        => null,
                'wrap_content' => false,
                'keyword_mode' => 'alguna',
            ];
            $order++;
        }

        return $columns;
    }

    /**
     * Fila `settings` de la preferencia: el conector a nivel modelo. 'or' es el default que ya
     * valida el controlador y el menos restrictivo.
     *
     * @return array
     */
    public static function settings()
    {
        return ['conector' => 'or'];
    }

    /**
     * Keys de relacion de la tabla que NO tienen su metodo en el modelo Eloquent, con el formato
     * "<model_name>.<key> => <Clase>::<metodo>()".
     *
     * Existe porque el modo de falla es silencioso: GlobalSearchQueryHelper::valid_relation_props()
     * descarta con method_exists() y sin avisar, asi que una relacion sembrada sin su metodo se ve
     * tildada en el desplegable y no filtra nada. Pasaba con `caja.address_id`: la columna
     * `cajas.address_id` existe desde 2025 y el modelo no tenia `address()`.
     *
     * El nombre del metodo es la key sin el sufijo `_id`, en snake_case: la relacion que arma el
     * front es exactamente eso, asi que un `conceptoMovimientoCaja()` en camelCase no matchearia.
     *
     * @return array lista vacia si esta todo en orden
     */
    public static function relation_methods_faltantes()
    {
        $faltantes = [];

        foreach (self::DEFAULTS as $model_name => $keys) {
            $class = GeneralHelper::getModelName($model_name);

            if (!class_exists($class)) {
                $faltantes[] = $model_name.'.* => clase inexistente: '.$class;
                continue;
            }

            $instance = new $class();

            foreach ($keys as $key) {
                if (substr($key, -3) !== '_id') {
                    continue;
                }

                $relation = substr($key, 0, -3);

                if (!method_exists($instance, $relation)) {
                    $faltantes[] = $model_name.'.'.$key.' => '.$class.'::'.$relation.'()';
                }
            }
        }

        return $faltantes;
    }
}
