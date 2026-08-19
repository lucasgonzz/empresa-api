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
     * - La 'key' se valida SIEMPRE contra el schema real de la tabla antes de usarla en un where,
     *   excepto para los operadores que no filtran por una columna del modelo y por lo tanto no
     *   tienen key que validar: el legacy 'category' (fuerza 'category_id') y
     *   'address_stock_seteado' (filtra por relación). Ver $operadores_sin_key más abajo. Esto
     *   evita interpolar en SQL una key arbitraria que venga del request.
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
                // stock positivo: lo que se pregunta es si la relación la cargó una persona, no
                // cuánto hay adentro.
                //
                // Por eso es whereHas y NO un where sobre pivot.amount: cualquier comparación
                // numérica dejaría afuera justo los tres casos que Lucas pidió incluir (19/8/2026).
                //
                // Lo que sí hay que elegir con cuidado es CUÁL pivote se mira: ver el bloque de
                // los artículos con variantes, abajo. No todas las filas de address_article las
                // escribió una persona.
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

                    if (!$tiene_variantes) {

                        // Modelo sin variantes: su pivote propio es el único dato que hay.
                        $models = $models->whereHas('addresses', function ($sub_query) use ($value) {
                            $sub_query->where('addresses.id', $value);
                        });

                    } else {

                        // 🔴 ACÁ NO SE PUEDE MIRAR `address_article` A SECAS, y este es el hallazgo
                        // que la Fase 5 encontró el 19/8/2026. Para un artículo cuyas variantes
                        // tienen sucursales, el pivote del ARTÍCULO es un valor DERIVADO que el
                        // backend regenera entero, con una fila para CADA sucursal de la cuenta y
                        // las que no tienen stock en 0:
                        //
                        //   ArticleHelper::get_addresses()            -> array con TODAS las
                        //                                                sucursales del usuario en 0
                        //   ArticleHelper::actualizar_article_addresses() -> sync([]) + attach de
                        //                                                cada entrada, ceros incluidos
                        //
                        // Se llega ahí desde cualquier movimiento de stock, desde SetArticleStock,
                        // desde producción y desde la importación de Excel. O sea que preguntar
                        // "¿existe la fila?" sobre esos artículos devuelve SIEMPRE que sí, para
                        // TODAS las sucursales, y el filtro no filtraría nada justo en las cuentas
                        // que usan variantes por sucursal — que son las que tienen sucursales en
                        // serio. Es exactamente lo contrario de lo que se pidió ("diferenciar a qué
                        // sucursal pertenece cada artículo").
                        //
                        // Entonces el dato que vale es el que cargó una persona, y ese vive en el
                        // pivote de la VARIANTE (address_article_variant), que nada regenera.
                        //
                        // El corte no es "tiene variantes" sino "alguna variante tiene sucursales":
                        // si ninguna las tiene, actualizar_article_addresses() no corre nunca y el
                        // pivote del artículo sigue siendo lo que cargó el usuario, así que ahí sí
                        // es la fuente correcta.
                        //
                        // 🔴 El where(function(){}) que envuelve al OR no es decorativo. Sin él, el
                        // orWhere se suelta y se OR-ea contra TODA la query de arriba —el
                        // where('user_id'), el grupo de coincidencia de texto y los filtros de
                        // columna—, así que el filtro traería artículos de otros usuarios y de
                        // otras búsquedas. El test de aislamiento por usuario lo cubre.
                        $models = $models->where(function ($query) use ($value) {

                            // Caso A — alguna variante tiene sucursales: manda el pivote de las
                            // variantes, y el del artículo se ignora por derivado.
                            $query->where(function ($con_variantes) use ($value) {
                                $con_variantes->whereHas('article_variants.addresses')
                                              ->whereHas('article_variants.addresses', function ($sub_query) use ($value) {
                                                    $sub_query->where('addresses.id', $value);
                                              });
                            });

                            // Caso B — ninguna variante tiene sucursales: el pivote del artículo es
                            // lo que cargó el usuario y es la fuente correcta. Cubre tanto al
                            // artículo sin variantes como al que tiene variantes sin sucursales.
                            $query->orWhere(function ($sin_variantes) use ($value) {
                                $sin_variantes->whereDoesntHave('article_variants.addresses')
                                              ->whereHas('addresses', function ($sub_query) use ($value) {
                                                    $sub_query->where('addresses.id', $value);
                                              });
                            });
                        });
                    }
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
