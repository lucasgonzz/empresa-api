<?php

namespace App\Http\Controllers\Helpers;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;

/**
 * Helper del "desglose de coincidencias" del buscador general (grupo 274).
 *
 * `GlobalSearchQueryHelper::apply` arma el WHERE que decide QUE filas devuelve el buscador, pero
 * el usuario no tiene forma de saber POR QUE aparecio cada una: con varias propiedades tildadas y
 * modo distribuido, una palabra puede haberla aportado el nombre y otra el codigo de proveedor.
 * Este helper es un observador puro sobre las filas YA traidas de la pagina actual (nunca participa
 * del armado de la query ni agrega consultas nuevas, salvo el loadMissing de relaciones que
 * withAll() no hubiera traido): recorre cada fila y cada propiedad tildada en PHP y cuenta cuantas
 * filas aporto cada una.
 *
 * La metrica NO es exclusiva: un mismo registro puede contar para dos o mas propiedades a la vez
 * (por eso `con_multiples`), y puede no coincidir por ninguna tildada aunque el SQL lo haya traido
 * -- por ejemplo por una divergencia de collation entre MySQL (`_ci`/`_general`, que matchea `a`
 * con `á`) y PHP (`mb_stripos`, que no) (por eso `sin_atribuir`). Repartir cada registro a una sola
 * propiedad por prioridad para que la suma cierre con el total ocultaria justamente el dato mas
 * util: que propiedad esta trayendo resultados.
 */
class GlobalSearchMatchesHelper
{
    /**
     * Calcula el desglose de coincidencias de una pagina de resultados contra las propiedades
     * (propias y de relaciones) que el usuario tildo en el buscador general.
     *
     * @param \Illuminate\Support\Collection|\Illuminate\Database\Eloquent\Collection|array $rows
     *        Modelos ya traidos (la pagina actual, no el total).
     * @param string $query_value Criterio de texto tal como lo escribio el usuario.
     * @param array $props Props propias: [{ key, keyword_mode }] o strings sueltos. keyword_mode se
     *        ignora a proposito (ver metodo, mas abajo).
     * @param array $relation_props Relaciones: [{ relation, props, keyword_mode }].
     * @param string $table Tabla del modelo, para validar columnas y tipos contra el schema real.
     * @param \Illuminate\Database\Eloquent\Model $model_instance Instancia para chequear relaciones.
     * @return array|null Forma: ['total' => int, 'propiedades' => [{kind,key|relation,cantidad}],
     *         'sin_atribuir' => int, 'con_multiples' => int], o null si no hay nada que desglosar
     *         (sin criterio de texto, sin filas, o sin ninguna propiedad valida).
     */
    public static function build($rows, $query_value, $props, $relation_props, $table, $model_instance)
    {
        // Sin criterio de texto no hay coincidencias que explicar. Es el caso del listado por
        // defecto (query_value vacio en cada entrada a un modulo): no puede pagar ni un ciclo de
        // esto ni disparar la notificacion del SPA.
        $query_value = trim((string) $query_value);
        if ($query_value === '') {
            return null;
        }

        // Mismo partido de palabras que GlobalSearchQueryHelper: explode por espacio, descartando
        // los vacios (dos espacios seguidos no cuentan como palabra).
        $keywords = array_values(array_filter(explode(' ', $query_value), function ($keyword) {
            return trim($keyword) !== '';
        }));

        if (empty($keywords)) {
            return null;
        }

        // Normalizado a Eloquent\Collection (no Support\Collection ni array plano) porque el paso
        // 7 necesita loadMissing(), que solo existe en la coleccion de Eloquent. Si ya viene una
        // Eloquent\Collection se usa tal cual (conserva relaciones ya cargadas por withAll());
        // si viene un array o una Support\Collection, se envuelve sin tocar los objetos modelo
        // (is_array() en el constructor de la coleccion los deja intactos, nunca los serializa).
        if ($rows instanceof EloquentCollection) {
            $rows_collection = $rows;
        } elseif ($rows instanceof \Illuminate\Support\Collection) {
            $rows_collection = new EloquentCollection($rows->all());
        } elseif (is_array($rows)) {
            $rows_collection = new EloquentCollection($rows);
        } else {
            $rows_collection = new EloquentCollection([]);
        }

        $row_list = $rows_collection->values()->all();

        if (empty($row_list)) {
            return null;
        }

        // Propiedades propias validas, en el orden en que llegaron en el payload. keyword_mode se
        // ignora a proposito: el desglose responde "que propiedad aporto", no "que propiedad
        // cumplio sola todo el criterio" -- un registro traido por el grupo estricto tambien aporto
        // sus palabras desde esa propiedad, asi que la cuenta es correcta en los dos modos.
        $own_entries = [];
        foreach ((array) $props as $prop) {
            $key = is_array($prop) ? (isset($prop['key']) ? $prop['key'] : null) : $prop;

            if (empty($key) || !is_string($key) || !Schema::hasColumn($table, $key)) {
                // Prop inexistente en la tabla, o payload malformado: se ignora (no rompe nada).
                continue;
            }

            $own_entries[] = [
                'kind'     => 'own',
                'key'      => $key,
                'cantidad' => 0,
            ];
        }

        // Relaciones validas, en el orden en que llegaron.
        $relation_entries = [];
        foreach ((array) $relation_props as $relation_prop) {
            if (!is_array($relation_prop) || !isset($relation_prop['relation']) || !isset($relation_prop['props'])) {
                continue;
            }

            $relation = $relation_prop['relation'];

            $relation_field_props = self::valid_relation_props($model_instance, $relation, (array) $relation_prop['props']);

            if (empty($relation_field_props)) {
                // Relacion inexistente, no-relacion, o sin ninguna columna real por la que buscar:
                // no participa del desglose.
                continue;
            }

            // Las relaciones que withAll() ya trajo no se vuelven a pedir. Las que falten se cargan
            // de una sola vez sobre la coleccion completa (una consulta por relacion, nunca una por
            // registro).
            try {
                $rows_collection->loadMissing($relation);
            } catch (\Throwable $e) {
                // Una relacion que no se puede cargar simplemente no participa del desglose.
                continue;
            }

            $relation_entries[] = [
                'kind'     => 'relation',
                'relation' => $relation,
                'props'    => $relation_field_props,
                'cantidad' => 0,
            ];
        }

        if (empty($own_entries) && empty($relation_entries)) {
            return null;
        }

        $sin_atribuir = 0;
        $con_multiples = 0;

        foreach ($row_list as $row) {
            $matched_count = 0;

            foreach ($own_entries as &$entry) {
                if (self::own_prop_matches($row, $entry['key'], $keywords, $table)) {
                    $entry['cantidad']++;
                    $matched_count++;
                }
            }
            unset($entry);

            foreach ($relation_entries as &$entry) {
                if (self::relation_matches($row, $entry['relation'], $entry['props'], $keywords)) {
                    $entry['cantidad']++;
                    $matched_count++;
                }
            }
            unset($entry);

            if ($matched_count === 0) {
                $sin_atribuir++;
            } elseif ($matched_count >= 2) {
                $con_multiples++;
            }
        }

        // Forma final de cada entrada (sin el detalle interno 'props', que solo servia para evaluar
        // la relacion).
        $propiedades = [];
        foreach ($own_entries as $entry) {
            $propiedades[] = ['kind' => 'own', 'key' => $entry['key'], 'cantidad' => $entry['cantidad']];
        }
        foreach ($relation_entries as $entry) {
            $propiedades[] = ['kind' => 'relation', 'relation' => $entry['relation'], 'cantidad' => $entry['cantidad']];
        }

        // Orden por cantidad descendente, y a igual cantidad en el orden de llegada. usort() no
        // garantiza estabilidad antes de PHP 8 (esto corre en 7.4), asi que se decora con el indice
        // original en vez de confiar en el orden que deje el algoritmo de sort.
        foreach ($propiedades as $i => &$entry) {
            $entry['_idx'] = $i;
        }
        unset($entry);

        usort($propiedades, function ($a, $b) {
            if ($a['cantidad'] === $b['cantidad']) {
                return $a['_idx'] <=> $b['_idx'];
            }

            return $b['cantidad'] <=> $a['cantidad'];
        });

        foreach ($propiedades as &$entry) {
            unset($entry['_idx']);
        }
        unset($entry);

        return [
            'total'         => count($row_list),
            'propiedades'   => $propiedades,
            'sin_atribuir'  => $sin_atribuir,
            'con_multiples' => $con_multiples,
        ];
    }

    /**
     * Replica la validacion de `GlobalSearchQueryHelper::valid_relation_props` (protected en esa
     * clase, no accesible desde aca; a proposito no se hace publica ni se mueve, es un camino
     * caliente en uso). Valida que la relacion exista como relacion de Eloquent real y filtra las
     * props declaradas contra el schema real de la tabla relacionada.
     *
     * @param \Illuminate\Database\Eloquent\Model $model_instance
     * @param mixed $relation Nombre del metodo de relacion tal como llega del frontend.
     * @param array $props Props de la relacion tal como llegan del frontend (sin validar).
     * @return array Subconjunto de $props que existen como columna en la tabla relacionada, o array
     *         vacio si la relacion no resuelve a una relacion de Eloquent.
     */
    protected static function valid_relation_props($model_instance, $relation, $props)
    {
        if (empty($relation) || !is_string($relation) || !method_exists($model_instance, $relation)) {
            return [];
        }

        try {
            $relation_instance = $model_instance->{$relation}();
        } catch (\Throwable $e) {
            return [];
        }

        if (!($relation_instance instanceof Relation)) {
            return [];
        }

        $related_table = $relation_instance->getRelated()->getTable();

        $valid_props = [];
        foreach ($props as $prop) {
            if (!is_string($prop) || $prop === '') {
                continue;
            }

            if (!preg_match('/^[a-zA-Z0-9_]+$/', $prop)) {
                continue;
            }

            if (!Schema::hasColumn($related_table, $prop)) {
                continue;
            }

            $valid_props[] = $prop;
        }

        return array_values($valid_props);
    }

    /**
     * Resuelve si una propiedad propia del modelo aporto a una fila: alguna de las palabras del
     * criterio coincide con el valor de esa columna en esa fila, con la misma regla de comparacion
     * que usa el SQL (texto: `mb_stripos` parcial; numerica: igualdad exacta, solo si la palabra
     * tambien es numerica).
     *
     * @param \Illuminate\Database\Eloquent\Model $row
     * @param string $key
     * @param array $keywords
     * @param string $table
     * @return bool
     */
    protected static function own_prop_matches($row, $key, $keywords, $table)
    {
        $value = $row->getAttribute($key);

        // Un valor null o vacio no coincide con nada.
        if ($value === null || $value === '') {
            return false;
        }

        // Guard numerico identico al de GlobalSearchQueryHelper: id, num, o cualquier columna
        // numerica segun information_schema.
        $is_numeric_column = ($key === 'id' || $key === 'num' || ExtraFiltersHelper::is_numeric_column($table, $key));

        foreach ($keywords as $keyword) {
            if ($is_numeric_column) {
                if (self::numeric_matches($value, $keyword)) {
                    return true;
                }
            } elseif (self::text_matches($value, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resuelve si una relacion aporto a una fila: alguna de sus props declaradas, en algun modelo
     * relacionado ya cargado, contiene alguna palabra del criterio (siempre con la regla de texto,
     * nunca la numerica -- misma decision que `GlobalSearchQueryHelper`, que arma sus condiciones
     * de relacion siempre con LIKE).
     *
     * Nunca accede a la relacion sin `relationLoaded()`: dispararia un N+1 de una consulta por
     * propiedad y por fila.
     *
     * @param \Illuminate\Database\Eloquent\Model $row
     * @param string $relation
     * @param array $relation_field_props
     * @param array $keywords
     * @return bool
     */
    protected static function relation_matches($row, $relation, $relation_field_props, $keywords)
    {
        if (!$row->relationLoaded($relation)) {
            return false;
        }

        $related = $row->getRelation($relation);

        // Una relacion cargada puede devolver un modelo (belongsTo/hasOne) o una coleccion
        // (hasMany/belongsToMany/etc). Se normalizan los dos casos a un array de modelos.
        if ($related instanceof \Illuminate\Support\Collection) {
            $related_models = $related->all();
        } elseif ($related === null) {
            $related_models = [];
        } else {
            $related_models = [$related];
        }

        foreach ($related_models as $related_model) {
            foreach ($relation_field_props as $prop) {
                $value = $related_model->getAttribute($prop);

                if ($value === null || $value === '') {
                    continue;
                }

                foreach ($keywords as $keyword) {
                    if (self::text_matches($value, $keyword)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Coincidencia de texto: parcial, sin distinguir mayusculas.
     *
     * @param mixed $value
     * @param string $keyword
     * @return bool
     */
    protected static function text_matches($value, $keyword)
    {
        if ($keyword === '') {
            return false;
        }

        return mb_stripos((string) $value, (string) $keyword) !== false;
    }

    /**
     * Coincidencia numerica: exacta, y solo si la palabra tambien es numerica. Nunca LIKE, nunca
     * comparacion de strings (un '0100' de la base tiene que dar igual que un 100 escrito, igual
     * que en SQL).
     *
     * @param mixed $value
     * @param string $keyword
     * @return bool
     */
    protected static function numeric_matches($value, $keyword)
    {
        if (!is_numeric($keyword)) {
            return false;
        }

        return (float) $value == (float) $keyword;
    }
}
