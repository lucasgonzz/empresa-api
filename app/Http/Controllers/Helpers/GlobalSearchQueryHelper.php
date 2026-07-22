<?php

namespace App\Http\Controllers\Helpers;

use Illuminate\Support\Facades\Schema;

/**
 * Helper del "grupo de coincidencia de texto" del buscador general.
 *
 * Antes de este helper, `SearchController::globalSearch` armaba SIEMPRE la misma estructura:
 * OR entre propiedades, con AND de palabras adentro de cada propiedad (modo "todas"). Esa
 * estructura es distinta de la que usa `VenderController::search_nombre` (AND de palabras, con
 * OR de propiedades adentro de cada palabra; modo "alguna"), y esa diferencia era la razón real
 * por la que el buscador de Vender "encuentra" cosas que el buscador general "no encuentra" con
 * los mismos datos (ej: criterio "8 pinza" contra un artículo "Pinza universal" con
 * provider_code "8532": en modo "todas" ninguna propiedad sola contiene las dos palabras, así
 * que no aparece; en modo "alguna" "pinza" la aporta el name y "8" el provider_code).
 *
 * Este helper baja esa decisión a configuración del usuario: cada propiedad (y cada relación)
 * tiene su propio `keyword_mode` ('todas' o 'alguna'), y el modelo tiene un `conector` ('or' u
 * 'and') que combina el grupo de props en modo 'todas' con el grupo de props en modo 'alguna'.
 * Todas las props en 'todas' reproduce exactamente el comportamiento de antes del buscador
 * general; todas en 'alguna' reproduce exactamente el de Vender.
 */
class GlobalSearchQueryHelper
{
    /**
     * Aplica el grupo de coincidencia de texto sobre una query ya armada, segun el modo de
     * coincidencia elegido por el usuario para cada propiedad.
     *
     * @param \Illuminate\Database\Eloquent\Builder $models Query base.
     * @param string $query_value Criterio de texto tal como lo escribio el usuario.
     * @param array $props Props propias: [{ key, keyword_mode }] o strings sueltos (default 'alguna').
     * @param array $relation_props Relaciones: [{ relation, props, keyword_mode }].
     * @param string $conector 'or' o 'and'. Une el grupo estricto con el distribuido.
     * @param string $table Tabla del modelo, para validar columnas y tipos contra el schema.
     * @param \Illuminate\Database\Eloquent\Model $model_instance Instancia para chequear relaciones.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function apply($models, $query_value, $props, $relation_props, $conector, $table, $model_instance)
    {
        // Criterio de texto normalizado. Si viene vacío, la búsqueda puede ser solo por filtros
        // fijos (extra_filters): no tocamos la query.
        $query_value = trim((string) $query_value);
        if ($query_value === '') {
            return $models;
        }

        // Palabras del criterio, descartando vacíos (dos espacios seguidos no generan un
        // LIKE '%%' que matchee todo).
        $keywords = array_values(array_filter(explode(' ', $query_value), function ($keyword) {
            return trim($keyword) !== '';
        }));

        if (empty($keywords)) {
            return $models;
        }

        // Props propias normalizadas y separadas por modo, ya validadas contra el schema real.
        $strict_props = [];
        $distributed_props = [];

        foreach ((array) $props as $prop) {
            // Normalizacion de entrada: puede venir como string suelto (payload viejo del
            // frontend) o como objeto { key, keyword_mode }. Un string, o un objeto sin
            // keyword_mode, se trata como 'alguna' (nuevo default).
            if (is_array($prop)) {
                $key = isset($prop['key']) ? $prop['key'] : null;
                $keyword_mode = isset($prop['keyword_mode']) ? $prop['keyword_mode'] : 'alguna';
            } else {
                $key = $prop;
                $keyword_mode = 'alguna';
            }

            if (empty($key) || !Schema::hasColumn($table, $key)) {
                // Prop inexistente en la tabla: se ignora (no rompe la busqueda).
                continue;
            }

            // Cualquier valor de keyword_mode que no sea 'todas' se trata como 'alguna'.
            if ($keyword_mode === 'todas') {
                $strict_props[] = $key;
            } else {
                $distributed_props[] = $key;
            }
        }

        // Relaciones normalizadas y separadas por modo, ya validadas contra el modelo real.
        $strict_relations = [];
        $distributed_relations = [];

        foreach ((array) $relation_props as $relation_prop) {
            if (!is_array($relation_prop) || !isset($relation_prop['relation']) || !isset($relation_prop['props'])) {
                continue;
            }

            $relation = $relation_prop['relation'];

            if (!method_exists($model_instance, $relation)) {
                // Relacion inexistente en el modelo: se ignora (no rompe la busqueda).
                continue;
            }

            // keyword_mode a nivel relacion, mismo default y misma normalizacion que las props.
            $keyword_mode = isset($relation_prop['keyword_mode']) ? $relation_prop['keyword_mode'] : 'alguna';

            $relation_entry = [
                'relation' => $relation,
                'props'    => (array) $relation_prop['props'],
            ];

            if ($keyword_mode === 'todas') {
                $strict_relations[] = $relation_entry;
            } else {
                $distributed_relations[] = $relation_entry;
            }
        }

        // Si tras normalizar no quedo ninguna prop ni relacion valida, no filtrar por nada es
        // preferible a devolver cero resultados sin explicacion.
        if (empty($strict_props) && empty($distributed_props) && empty($strict_relations) && empty($distributed_relations)) {
            return $models;
        }

        // Todo el armado va envuelto en UN solo where(function...) para no romper el AND con
        // user_id, con status ni con los extra_filters que se ANDean despues de este metodo.
        return $models->where(function ($query) use (
            $strict_props,
            $distributed_props,
            $strict_relations,
            $distributed_relations,
            $keywords,
            $conector,
            $table
        ) {
            // Hay grupo estricto si hay al menos una prop o relacion en modo 'todas'.
            $has_strict_group = !empty($strict_props) || !empty($strict_relations);
            // Hay grupo distribuido si hay al menos una prop o relacion en modo 'alguna'.
            $has_distributed_group = !empty($distributed_props) || !empty($distributed_relations);

            // Conector real a usar cuando existen los dos grupos: 'and' solo si se pidio
            // explicitamente y hay dos grupos para combinar; cualquier otro valor es 'or'.
            $use_and_conector = ($conector === 'and') && $has_strict_group && $has_distributed_group;

            if ($has_strict_group) {
                // Grupo estricto: estructura de hoy del buscador general. OR entre props/relaciones,
                // con AND de todas las palabras adentro de cada una.
                $add_strict_group = function ($grupo) use ($strict_props, $strict_relations, $keywords, $table) {
                    foreach ($strict_props as $prop) {
                        $grupo->orWhere(function ($sub) use ($prop, $keywords, $table) {
                            self::apply_all_keywords($sub, $prop, $keywords, $table);
                        });
                    }

                    foreach ($strict_relations as $relation_entry) {
                        $relation = $relation_entry['relation'];
                        $relation_field_props = $relation_entry['props'];

                        // Misma estructura que ya tenia el buscador general para relaciones (OR
                        // entre las props de la relacion, con AND de todas las palabras adentro
                        // de cada prop) - se preserva tal cual para que el modo 'todas' siga dando
                        // exactamente el SQL de hoy.
                        $grupo->orWhereHas($relation, function ($sub_relation) use ($relation_field_props, $keywords) {
                            $sub_relation->where(function ($sub) use ($relation_field_props, $keywords) {
                                foreach ($relation_field_props as $relation_field_prop) {
                                    $sub->orWhere(function ($sub_prop) use ($relation_field_prop, $keywords) {
                                        foreach ($keywords as $keyword) {
                                            $sub_prop->whereRaw($relation_field_prop.' LIKE ?', ['%'.$keyword.'%']);
                                        }
                                    });
                                }
                            });
                        });
                    }
                };

                if ($use_and_conector) {
                    $query->where(function ($grupo) use ($add_strict_group) {
                        $add_strict_group($grupo);
                    });
                } else {
                    $query->orWhere(function ($grupo) use ($add_strict_group) {
                        $add_strict_group($grupo);
                    });
                }
            }

            if ($has_distributed_group) {
                // Grupo distribuido: estructura de Vender. AND de palabras, con OR entre props y
                // relaciones del "pozo comun" adentro de cada palabra.
                $add_distributed_group = function ($grupo) use ($distributed_props, $distributed_relations, $keywords, $table) {
                    foreach ($keywords as $keyword) {
                        $grupo->where(function ($sub) use ($distributed_props, $distributed_relations, $keyword, $table) {
                            foreach ($distributed_props as $prop) {
                                self::apply_single_keyword($sub, $prop, $keyword, $table);
                            }

                            foreach ($distributed_relations as $relation_entry) {
                                $relation = $relation_entry['relation'];
                                $relation_field_props = $relation_entry['props'];

                                $sub->orWhereHas($relation, function ($sub_relation) use ($relation_field_props, $keyword) {
                                    $sub_relation->where(function ($sub_prop) use ($relation_field_props, $keyword) {
                                        foreach ($relation_field_props as $relation_field_prop) {
                                            $sub_prop->orWhereRaw($relation_field_prop.' LIKE ?', ['%'.$keyword.'%']);
                                        }
                                    });
                                });
                            }
                        });
                    }
                };

                if ($use_and_conector || !$has_strict_group) {
                    $query->where(function ($grupo) use ($add_distributed_group) {
                        $add_distributed_group($grupo);
                    });
                } else {
                    $query->orWhere(function ($grupo) use ($add_distributed_group) {
                        $add_distributed_group($grupo);
                    });
                }
            }
        });
    }

    /**
     * Agrega, dentro del closure de una prop, el AND de todas las palabras del criterio contra
     * esa prop (grupo estricto / modo 'todas'). Aplica el guard numerico: si la prop es numerica,
     * solo participa cuando TODAS las palabras del criterio son numericas (si alguna palabra no
     * lo es, la prop no puede cumplir "todas las palabras" y queda fuera del grupo, sin agregar
     * ninguna condicion; Eloquent omite un nested where sin condiciones adentro, asi que el
     * efecto es identico a no participar del OR, mismo resultado que hoy).
     *
     * @param \Illuminate\Database\Eloquent\Builder $sub Sub-query del grupo AND de esta prop.
     * @param string $prop Columna sobre la que se arma el AND.
     * @param array $keywords Palabras del criterio.
     * @param string $table Tabla, para resolver si la columna es numerica.
     * @return void
     */
    protected static function apply_all_keywords($sub, $prop, $keywords, $table)
    {
        // Guard numerico: id, num, o cualquier columna numerica segun el schema real.
        $is_numeric_column = ($prop === 'id' || $prop === 'num' || ExtraFiltersHelper::is_numeric_column($table, $prop));

        if ($is_numeric_column) {
            // Solo participa si TODAS las palabras del criterio son numericas (si el criterio
            // tiene una sola palabra numerica, esto reduce al match exacto de siempre).
            $all_keywords_numeric = true;
            foreach ($keywords as $keyword) {
                if (!is_numeric($keyword)) {
                    $all_keywords_numeric = false;
                    break;
                }
            }

            if ($all_keywords_numeric) {
                foreach ($keywords as $keyword) {
                    // Match exacto por numero, nunca LIKE sobre columna numerica.
                    $sub->where($prop, $keyword);
                }
            }
            // Si alguna palabra no es numerica, no se agrega ninguna condicion: la prop queda
            // fuera del grupo (ver PHPDoc de este metodo).
            return;
        }

        foreach ($keywords as $keyword) {
            $sub->where($prop, 'LIKE', '%'.$keyword.'%');
        }
    }

    /**
     * Agrega, dentro del closure de una palabra, el OR de esa prop contra esa palabra puntual
     * (grupo distribuido / modo 'alguna'). Aplica el guard numerico: la prop numerica solo aporta
     * a las palabras que son numericas (con match exacto), y no participa del OR para palabras de
     * texto.
     *
     * @param \Illuminate\Database\Eloquent\Builder $sub Sub-query del OR de esta palabra.
     * @param string $prop Columna a evaluar contra la palabra.
     * @param string $keyword Palabra puntual del criterio.
     * @param string $table Tabla, para resolver si la columna es numerica.
     * @return void
     */
    protected static function apply_single_keyword($sub, $prop, $keyword, $table)
    {
        $is_numeric_column = ($prop === 'id' || $prop === 'num' || ExtraFiltersHelper::is_numeric_column($table, $prop));

        if ($is_numeric_column) {
            if (is_numeric($keyword)) {
                // Match exacto por numero, nunca LIKE sobre columna numerica.
                $sub->orWhere($prop, $keyword);
            }
            // Palabra no numerica: esta prop numerica no aporta a esta palabra.
            return;
        }

        $sub->orWhere($prop, 'LIKE', '%'.$keyword.'%');
    }
}
