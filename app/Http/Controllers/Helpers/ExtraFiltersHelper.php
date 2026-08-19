<?php

namespace App\Http\Controllers\Helpers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Helper genérico de "filtros extra" (extra_filters) para los buscadores del sistema.
 *
 * Centraliza la lógica de operadores que antes vivía inline dentro de
 * `SearchController::globalSearch` (whitelist corta, hecha a medida del Listado de artículos:
 * `=`, `like`, `category`, `stock_option`). Ahora cualquier buscador (buscador general, modales
 * de búsqueda de Vender/compras, etc.) puede reusar los mismos operadores genéricos, incluyendo
 * comparaciones numéricas (`>`, `<`, `>=`, `<=`) y presencia de valor (`numeric_presence`), sin
 * que el operador tenga el nombre del caso de uso adentro (ej: `stock_option` era en realidad un
 * concepto general sobre cualquier columna numérica).
 */
class ExtraFiltersHelper
{
    /**
     * Aplica los filtros extra (AND) sobre una query ya armada. Lo usan el buscador general y los
     * buscadores de modal, para que los filtros fijos que configura el usuario se comporten igual
     * en todos lados.
     *
     * Reglas:
     * - Si $extra_filters no es un array, se devuelve $models sin tocar.
     * - Cada filtro sin 'key' u 'operator' se ignora.
     * - La 'key' se valida SIEMPRE contra el schema real de la tabla antes de usarla en un where
     *   (excepto el operador legacy 'category', que no usa la key recibida sino que fuerza
     *   'category_id'). Esto evita interpolar en SQL una key arbitraria que venga del request.
     * - Valores que no filtran nada (null, '', 'todos', 'con_o_sin_stock') se ignoran, EXCEPTO
     *   el 0 con operador '=' sobre una columna numérica, que es un filtro legítimo (ej: stock
     *   exactamente 0). El 0 solo se descarta para el operador legacy 'category' (donde significa
     *   "todas").
     * - Los operadores fuera de la whitelist se ignoran en silencio (nunca ejecutan SQL arbitrario).
     * - 'address_stock_seteado' no filtra por columna sino por relación (whereHas sobre addresses,
     *   más las de las variantes si el modelo las tiene): deja pasar los modelos que tienen seteada
     *   la relación con esa sucursal, cualquiera sea el valor del pivote. Valor 0 = "todas".
     *
     * @param \Illuminate\Database\Eloquent\Builder $models Query base (ya armada, típicamente después
     *        del where(function...) del grupo OR de texto).
     * @param string $table Nombre de la tabla del modelo (para validar columnas contra el schema).
     * @param array $extra_filters Objetos { key, operator, value }.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function apply($models, $table, $extra_filters)
    {
        // Si no es un array, no hay filtros que aplicar: devolvemos la query intacta.
        if (!is_array($extra_filters)) {
            return $models;
        }

        foreach ($extra_filters as $extra_filter) {

            // Filtro incompleto (sin key u operator): se ignora.
            if (!isset($extra_filter['key']) || !isset($extra_filter['operator'])) {
                continue;
            }

            // Columna/propiedad sobre la que se filtra (viene del request, se valida abajo).
            $key = $extra_filter['key'];
            // Operador pedido (se compara contra la whitelist fija de este método).
            $operator = $extra_filter['operator'];
            // Valor del filtro (puede no venir, en cuyo caso es null).
            $value = isset($extra_filter['value']) ? $extra_filter['value'] : null;

            // Validación de la key contra el schema real de la tabla, SIEMPRE, antes de usarla en
            // cualquier where. Excepciones: los operadores que NO filtran por una columna de la
            // tabla del modelo y por lo tanto no tienen ninguna key que validar —'category' fuerza
            // 'category_id', y 'address_stock_seteado' filtra por una RELACIÓN, no por una columna
            // (articles no tiene address_id: el vínculo vive en el pivote address_article).
            //
            // Es una lista y no un `!=` encadenado a propósito: el `!= 'category'` que había acá
            // ya era el segundo operador que necesitaba la excepción y el tercero volvería a
            // alargar la condición en vez de agregar un elemento.
            $operadores_sin_key = ['category', 'address_stock_seteado'];
            if (!in_array($operator, $operadores_sin_key) && !Schema::hasColumn($table, $key)) {
                continue;
            }

            // Valores que no filtran nada: null, cadena vacía, 'todos' (numeric_presence) o
            // 'con_o_sin_stock' (legacy stock_option). OJO: el 0 NO se descarta acá de forma
            // genérica, porque con el operador '=' sobre una columna numérica "igual a 0" es un
            // filtro legítimo (ej: artículos con stock exactamente 0). El 0 se descarta más abajo
            // solo para el operador legacy 'category'.
            $sin_filtro = ($value === null || $value === '' || $value === 'todos' || $value === 'con_o_sin_stock');
            if ($sin_filtro) {
                continue;
            }

            if ($operator == '=') {
                // Igualdad directa. El 0 es un valor legítimo acá (ver comentario arriba).
                $models = $models->where($key, $value);
            } else if ($operator == 'like') {
                // Coincidencia parcial (contains).
                $models = $models->where($key, 'like', '%'.$value.'%');
            } else if ($operator == '>' || $operator == '<' || $operator == '>=' || $operator == '<=') {
                // Comparación numérica: solo si la columna es numérica y el valor recibido también
                // lo es. Si no cumple, se ignora el filtro (no comparar texto con '>' contra
                // cualquier columna).
                if (self::is_numeric_column($table, $key) && is_numeric($value)) {
                    $models = $models->where($key, $operator, $value);
                }
            } else if ($operator == 'numeric_presence') {
                // Presencia de valor sobre columna numérica. Solo aplica si la columna es numérica.
                if (self::is_numeric_column($table, $key)) {
                    if ($value == 'con_valor') {
                        // "que hayan tenido valor alguna vez" = la columna no es null.
                        $models = $models->whereNotNull($key);
                    } else if ($value == 'positivo') {
                        // "con valor positivo" = la columna es mayor a cero.
                        $models = $models->where($key, '>', 0);
                    }
                    // 'todos' ya se descartó arriba (no aplica ningún filtro).
                }
            } else if ($operator == 'category') {
                // Operador legacy: fuerza la columna 'category_id' (no la key recibida). Se
                // mantiene tal cual porque el Listado de artículos lo manda hoy en producción.
                // El 0 (o vacío) significa "todas las categorías": no se filtra.
                if ($value != 0 && $value !== '' && $value !== null) {
                    $models = $models->where('category_id', $value);
                }
            } else if ($operator == 'address_stock_seteado') {
                // Filtro por SUCURSAL del listado de artículos: deja pasar solo los artículos a los
                // que en algún momento se les seteó el stock de esa sucursal, sin mirar el valor.
                // Una fila en el pivote con amount 0, negativo o null cuenta igual que una con
                // stock positivo: lo que se pregunta es si la relación existe, no cuánto hay.
                //
                // Por eso es whereHas y NO un where sobre pivot.amount: cualquier comparación
                // numérica dejaría afuera justo los tres casos que Lucas pidió incluir (19/8/2026).
                //
                // El 0 significa "todas las sucursales" y no filtra nada. Se descarta acá adentro y
                // no en el $sin_filtro de arriba porque aquel deja pasar el 0 a propósito (para '='
                // sobre una columna numérica, "igual a 0" es un filtro legítimo). Mismo patrón que
                // el operador 'category'.
                $model_instance = $models->getModel();

                if ($value != 0 && method_exists($model_instance, 'addresses')) {

                    // ¿El modelo tiene variantes con stock propio por sucursal? (articles sí,
                    // cualquier otro modelo con addresses() probablemente no).
                    $tiene_variantes = method_exists($model_instance, 'article_variants');

                    // 🔴 El where(function(){}) que envuelve al OR no es decorativo. Sin él, el
                    // orWhereHas se suelta y se OR-ea contra TODA la query de arriba —el
                    // where('user_id'), el grupo de coincidencia de texto y los filtros de
                    // columna—, así que el filtro de sucursal traería artículos de otros usuarios
                    // y de otras búsquedas. Agrupado, el OR queda contenido y el conjunto entero
                    // se AND-ea con lo anterior, que es lo que un filtro tiene que hacer.
                    $models = $models->where(function ($query) use ($value, $tiene_variantes) {

                        $query->whereHas('addresses', function ($sub_query) use ($value) {
                            $sub_query->where('addresses.id', $value);
                        });

                        // Un artículo con variantes no guarda su stock por sucursal en
                        // address_article sino en address_article_variant, una fila por variante.
                        // La columna "Sucursal X" de la tabla YA los muestra: get_address_stock()
                        // del mixin payment_method_discounts_addresses_columns.js cae a sumar el
                        // stock de las variantes cuando el artículo no tiene fila propia.
                        //
                        // Si este filtro mirara solo address_article, un artículo con un número
                        // visible en la columna de Sucursal X desaparecería al filtrar por
                        // Sucursal X. Desde la pantalla eso se lee como un bug, no como una regla.
                        if ($tiene_variantes) {
                            $query->orWhereHas('article_variants.addresses', function ($sub_query) use ($value) {
                                $sub_query->where('addresses.id', $value);
                            });
                        }
                    });
                }
            } else if ($operator == 'stock_option') {
                // Operador legacy: mismos valores que el select de stock del frontend.
                // 'con_o_sin_stock' ya se descartó arriba (no aplica ningún where).
                if ($value == 'con_stock') {
                    $models = $models->where('stock', '>', 0);
                } else if ($value == 'hayan_tenido_stock' || $value == 'sin_stock') {
                    $models = $models->whereNotNull('stock');
                }
            }
            // Cualquier otro operador fuera de la whitelist se ignora en silencio.
        }

        return $models;
    }

    /**
     * Indica si una columna es numérica, consultando information_schema (NO Doctrine DBAL: revienta
     * con "Unknown database type enum" apenas la tabla tiene una columna enum, ej. articles.status).
     *
     * Movido tal cual desde `SearchController::is_numeric_column` (misma consulta a
     * information_schema, misma lista de tipos), para que sea reusable por cualquier buscador.
     *
     * @param string $table Nombre de la tabla (sin prefijo de base de datos).
     * @param string $column Nombre de la columna a chequear.
     * @return bool
     */
    public static function is_numeric_column($table, $column)
    {
        // Tipo de dato real de la columna, resuelto vía information_schema.
        $row = DB::selectOne(
            'SELECT DATA_TYPE as data_type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );

        if (!$row) {
            return false;
        }

        return in_array($row->data_type, ['int', 'bigint', 'smallint', 'tinyint', 'mediumint', 'decimal', 'float', 'double']);
    }
}
