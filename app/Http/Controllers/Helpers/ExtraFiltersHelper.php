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
            // cualquier where. Excepción única: el operador legacy 'category', que no usa la key
            // recibida sino que fuerza 'category_id' (ver más abajo).
            if ($operator != 'category' && !Schema::hasColumn($table, $key)) {
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
