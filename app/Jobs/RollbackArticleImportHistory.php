<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\ArticleVariant;
use App\Models\ImportHistory;
use App\Models\User;
use App\Notifications\GlobalNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class RollbackArticleImportHistory implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Id del historial de importación a revertir.
     *
     * @var int
     */
    protected $import_history_id;

    /**
     * Usuario dueño del historial para validación de seguridad.
     *
     * @var int
     */
    protected $owner_user_id;

    /**
     * Máximo tiempo permitido para ejecutar el rollback.
     *
     * @var int
     */
    public $timeout = 1800;

    /**
     * Cantidad de reintentos del job.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Crea una nueva instancia del job.
     *
     * @param int $import_history_id
     * @param int $owner_user_id
     */
    public function __construct(int $import_history_id, int $owner_user_id)
    {
        $this->import_history_id = $import_history_id;
        $this->owner_user_id = $owner_user_id;
    }

    /**
     * Ejecuta el rollback completo de la importación.
     *
     * @return void
     */
    public function handle()
    {
        /**
         * Cargamos todas las relaciones necesarias en una sola consulta para
         * evitar lecturas parciales durante el procesamiento del rollback.
         */
        $import_history = ImportHistory::with([
            'chunks.articulos_actualizados',
            'articulos_creados',
        ])->find($this->import_history_id);

        if (is_null($import_history)) {
            Log::warning('RollbackArticleImportHistory: no existe import_history', [
                'import_history_id' => $this->import_history_id,
            ]);
            return;
        }

        /**
         * Revalidamos ownership dentro del Job para cubrir ejecuciones tardías
         * o llamadas forzadas fuera del flujo del controlador.
         */
        if ((int) $import_history->user_id !== (int) $this->owner_user_id) {
            Log::warning('RollbackArticleImportHistory: owner invalido', [
                'import_history_id' => $this->import_history_id,
                'expected_user_id' => $this->owner_user_id,
                'current_user_id' => $import_history->user_id,
            ]);
            return;
        }

        /**
         * No permitimos rollback mientras la importación esté activa para
         * evitar conflictos con chunks que aún puedan modificar artículos.
         */
        if (in_array($import_history->status, ['en_preparacion', 'en_proceso'])) {
            Log::warning('RollbackArticleImportHistory: importacion en curso', [
                'import_history_id' => $this->import_history_id,
                'status' => $import_history->status,
            ]);
            return;
        }

        /**
         * Obtenemos las columnas reales de la tabla articles para restaurar
         * únicamente campos directos y descartar estructuras complejas.
         */
        $article_columns = array_flip(Schema::getColumnListing('articles'));

        /**
         * Acá acumulamos por artículo y por campo el primer valor old detectado
         * en orden cronológico de chunks (estado previo global a la importación).
         */
        $restore_map = [];

        /**
         * Mapa de relaciones a restaurar por artículo actualizado.
         * Estructura: [ article_id => [ clave_relacion => { old: ..., new: ... } ] ]
         *
         * Claves posibles:
         *  - discounts_percent    → array de porcentajes a restaurar en article_discounts
         *  - discounts_amount     → array de montos a restaurar en article_discounts
         *  - surchages_percent    → array de porcentajes a restaurar en article_surchages
         *  - surchages_amount     → array de montos a restaurar en article_surchages
         *  - price_type_{id}      → { percentage, final_price } a restaurar en article_price_type
         *  - provider_pivot       → { provider_code, cost } a restaurar en article_provider
         */
        $relations_restore_map = [];

        /**
         * Procesamos chunks de forma ascendente para que, si un mismo campo se
         * modificó múltiples veces, prevalezca el old de la primera mutación.
         */
        $ordered_chunks = $import_history->chunks->sortBy('id');

        foreach ($ordered_chunks as $chunk) {
            foreach ($chunk->articulos_actualizados as $article) {
                /**
                 * Cada artículo actualizado trae en pivot el JSON updated_props
                 * con diffs tipo __diff__campo => { old, new }.
                 */
                $pivot_updated_props = $article->pivot->updated_props ?? null;

                if (is_null($pivot_updated_props)) {
                    continue;
                }

                $updated_props = is_array($pivot_updated_props)
                    ? $pivot_updated_props
                    : json_decode($pivot_updated_props, true);

                if (!is_array($updated_props)) {
                    continue;
                }

                foreach ($updated_props as $key => $value) {
                    /**
                     * Solo usamos entradas __diff__* porque contienen old/new
                     * y permiten reconstruir el estado anterior real.
                     */
                    if (!is_string($key) || strpos($key, '__diff__') !== 0) {
                        continue;
                    }

                    /**
                     * Extraemos el nombre de columna quitando el prefijo __diff__.
                     */
                    $field_name = substr($key, 8);

                    if (!is_array($value) || !array_key_exists('old', $value)) {
                        continue;
                    }

                    /*
                     * Separamos diffs de columnas directas de la tabla articles
                     * de diffs de relaciones (que tienen nombres reservados).
                     */
                    $is_relation_diff = $this->is_relation_diff_key($field_name);

                    if ($is_relation_diff) {
                        /*
                         * Guardamos el primer old de cada relación para restaurar
                         * el estado previo completo de la importación.
                         */
                        if (!isset($relations_restore_map[$article->id])) {
                            $relations_restore_map[$article->id] = [];
                        }

                        if (!array_key_exists($field_name, $relations_restore_map[$article->id])) {
                            $relations_restore_map[$article->id][$field_name] = $value;
                        }

                        continue;
                    }

                    /**
                     * Para columnas directas: ignoramos campos no existentes en articles
                     * y diffs que no sean escalares.
                     */
                    if (!isset($article_columns[$field_name])) {
                        continue;
                    }

                    $old_value = $value['old'];

                    if (is_array($old_value) || is_object($old_value)) {
                        continue;
                    }

                    if (!isset($restore_map[$article->id])) {
                        $restore_map[$article->id] = [];
                    }

                    /**
                     * Guardamos el primer old del campo para restaurar el estado
                     * previo completo de la importación.
                     */
                    if (!array_key_exists($field_name, $restore_map[$article->id])) {
                        $restore_map[$article->id][$field_name] = $old_value;
                    }
                }
            }
        }

        /**
         * IDs de artículos creados por importación para eliminarlos luego de
         * restaurar actualizados, todo dentro de una transacción atómica.
         */
        $created_article_ids = $import_history->articulos_creados
                                            ->pluck('id')
                                            ->unique()
                                            ->values()
                                            ->all();

        DB::transaction(function () use ($restore_map, $relations_restore_map, $created_article_ids, $import_history) {
            /**
             * Revertimos columnas directas en artículos actualizados.
             */
            foreach ($restore_map as $article_id => $fields_to_restore) {
                if (empty($fields_to_restore)) {
                    continue;
                }

                Article::where('id', $article_id)->update($fields_to_restore);
            }

            /**
             * Revertimos relaciones de artículos actualizados usando los diffs guardados.
             * Cada clave de relación determina qué tabla y qué operación ejecutar.
             */
            foreach ($relations_restore_map as $article_id => $relation_diffs) {
                $this->revert_relations($article_id, $relation_diffs);
            }

            /**
             * Para artículos CREADOS: eliminamos todas sus relaciones antes de
             * eliminar el artículo, para evitar huérfanos o errores de FK.
             */
            if (!empty($created_article_ids)) {
                $this->delete_created_article_relations($created_article_ids);

                /*
                 * Eliminamos artículos creados por la importación.
                 * Se usa delete() para respetar SoftDeletes del modelo Article.
                 */
                Article::whereIn('id', $created_article_ids)->delete();
            }

            /**
             * Dejamos trazabilidad en observations del historial con contadores
             * de relaciones revertidas además de los artículos directos.
             */
            $rollback_observation = 'Rollback ejecutado.'
                . ' Articulos restaurados: ' . count($restore_map) . '.'
                . ' Relaciones revertidas en: ' . count($relations_restore_map) . ' articulos.'
                . ' Articulos creados eliminados: ' . count($created_article_ids) . '.';

            $import_history->observations = trim(($import_history->observations ?? '') . ' | ' . $rollback_observation);
            $import_history->save();
        });

        /**
         * Enviamos una notificación global al finalizar para mantener el mismo
         * comportamiento UX que el cierre de importación de artículos.
         */
        $user = User::find($this->owner_user_id);
        if (!is_null($user)) {
            /**
             * Definimos botón de acción para refrescar la lista de artículos
             * en el frontend, igual que en fin de importación.
             */
            $functions_to_execute = [
                [
                    'btn_text'      => 'Aceptar',
                    'function_name' => 'update_articles_after_import',
                    'btn_variant'   => 'primary',
                ],
            ];

            /**
             * Mostramos un breve resumen del resultado del rollback.
             */
            $info_to_show = [
                [
                    'title'     => 'Resultado del rollback',
                    'parrafos'  => [
                        count($restore_map) . ' articulos restaurados',
                        count($created_article_ids) . ' articulos creados eliminados',
                    ],
                ],
            ];

            $user->notify(new GlobalNotification([
                'message_text'              => 'Rollback de importacion finalizado correctamente',
                'color_variant'             => 'success',
                'functions_to_execute'      => $functions_to_execute,
                'info_to_show'              => $info_to_show,
                'owner_id'                  => $user->id,
                'is_only_for_auth_user'     => false,
            ]));
        }

        Log::info('RollbackArticleImportHistory: rollback finalizado', [
            'import_history_id'            => $this->import_history_id,
            'restored_articles'            => count($restore_map),
            'relations_reverted_articles'  => count($relations_restore_map),
            'deleted_created_articles'     => count($created_article_ids),
        ]);
    }

    /**
     * Determina si una clave de diff corresponde a una relación y no a una columna directa.
     *
     * Las claves de relación tienen nombres reservados que no existen como columnas
     * en la tabla articles. Usamos prefijos conocidos para identificarlas.
     *
     * @param  string $field_name  Nombre del campo sin el prefijo "__diff__"
     * @return bool
     */
    protected function is_relation_diff_key(string $field_name): bool
    {
        /*
         * Claves de relación conocidas y sus prefijos:
         *   discounts_percent, discounts_amount
         *   surchages_percent, surchages_amount
         *   price_type_{id}
         *   provider_pivot
         */
        $relation_prefixes = [
            'discounts_percent',
            'discounts_amount',
            'surchages_percent',
            'surchages_amount',
            'price_type_',
            'provider_pivot',
        ];

        foreach ($relation_prefixes as $prefix) {
            if (strpos($field_name, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Revierte las relaciones de un artículo actualizado usando el mapa de diffs guardado.
     *
     * Procesa cada clave de relación y restaura los valores "old" en la tabla correspondiente.
     *
     * @param  int   $article_id       ID del artículo a revertir
     * @param  array $relation_diffs   Mapa de diffs de relaciones [ clave => { old: ..., new: ... } ]
     * @return void
     */
    protected function revert_relations(int $article_id, array $relation_diffs): void
    {
        foreach ($relation_diffs as $key => $diff) {
            $old_value = $diff['old'] ?? null;

            if (is_null($old_value)) {
                continue;
            }

            if (strpos($key, 'discounts_percent') === 0) {
                /*
                 * Restauramos los porcentajes de descuento eliminando los actuales
                 * e insertando los valores previos guardados en old.
                 */
                $this->restore_discounts_or_surchages(
                    $article_id,
                    'article_discounts',
                    'percentage',
                    is_array($old_value) ? $old_value : []
                );

            } elseif (strpos($key, 'discounts_amount') === 0) {
                /*
                 * Restauramos los montos de descuento.
                 */
                $this->restore_discounts_or_surchages(
                    $article_id,
                    'article_discounts',
                    'amount',
                    is_array($old_value) ? $old_value : []
                );

            } elseif (strpos($key, 'surchages_percent') === 0) {
                /*
                 * Restauramos los porcentajes de recargo.
                 */
                $this->restore_discounts_or_surchages(
                    $article_id,
                    'article_surchages',
                    'percentage',
                    is_array($old_value) ? $old_value : []
                );

            } elseif (strpos($key, 'surchages_amount') === 0) {
                /*
                 * Restauramos los montos de recargo.
                 */
                $this->restore_discounts_or_surchages(
                    $article_id,
                    'article_surchages',
                    'amount',
                    is_array($old_value) ? $old_value : []
                );

            } elseif (strpos($key, 'price_type_') === 0) {
                /*
                 * El id del price_type viene después del prefijo "price_type_".
                 * Restauramos percentage y final_price en la pivot article_price_type.
                 */
                $price_type_id = (int) substr($key, strlen('price_type_'));

                if ($price_type_id > 0 && is_array($old_value)) {
                    $this->restore_price_type_pivot($article_id, $price_type_id, $old_value);
                }

            } elseif ($key === 'provider_pivot') {
                /*
                 * Restauramos provider_code y cost en la pivot article_provider.
                 * El old contiene { provider_id, provider_code, cost }.
                 */
                if (is_array($old_value) && isset($old_value['provider_id'])) {
                    $this->restore_provider_pivot($article_id, $old_value);
                }
            }
        }
    }

    /**
     * Restaura los registros de descuento o recargo de un artículo para una columna dada.
     *
     * Elimina los registros actuales del tipo indicado (percentage o amount) e inserta
     * los valores del array old.
     *
     * @param  int    $article_id  ID del artículo
     * @param  string $table       Nombre de la tabla ('article_discounts' o 'article_surchages')
     * @param  string $column      Columna a restaurar ('percentage' o 'amount')
     * @param  array  $old_values  Array de valores previos (escalares)
     * @return void
     */
    protected function restore_discounts_or_surchages(
        int $article_id,
        string $table,
        string $column,
        array $old_values
    ): void {
        /*
         * Eliminamos los registros actuales del tipo indicado para este artículo.
         * La condición whereNotNull($column) evita tocar registros del otro tipo.
         */
        DB::table($table)
            ->where('article_id', $article_id)
            ->whereNotNull($column)
            ->delete();

        if (empty($old_values)) {
            /* Si no había valores previos, solo limpiamos y listo. */
            return;
        }

        /* Insertamos los valores previos como nuevos registros. */
        $now = now();
        $insert_rows = [];

        foreach ($old_values as $old_val) {
            if ($old_val === null || $old_val === '') {
                continue;
            }

            $insert_rows[] = [
                'article_id'  => $article_id,
                $column       => (float) $old_val,
                'created_at'  => $now,
                'updated_at'  => $now,
            ];
        }

        if (!empty($insert_rows)) {
            DB::table($table)->insert($insert_rows);
        }
    }

    /**
     * Restaura los valores de percentage y final_price en la pivot article_price_type.
     *
     * @param  int   $article_id     ID del artículo
     * @param  int   $price_type_id  ID del tipo de precio
     * @param  array $old_values     Array con claves 'percentage' y/o 'final_price'
     * @return void
     */
    protected function restore_price_type_pivot(int $article_id, int $price_type_id, array $old_values): void
    {
        /*
         * Actualizamos solo si la fila ya existe en la pivot.
         * Si no existe, no hay estado previo que restaurar.
         */
        $exists = DB::table('article_price_type')
            ->where('article_id', $article_id)
            ->where('price_type_id', $price_type_id)
            ->exists();

        if (!$exists) {
            return;
        }

        /* Construimos el array de campos a actualizar con los valores old. */
        $update_data = [];

        if (array_key_exists('percentage', $old_values)) {
            $update_data['percentage'] = $old_values['percentage'];
        }

        if (array_key_exists('final_price', $old_values)) {
            $update_data['final_price'] = $old_values['final_price'];
        }

        if (!empty($update_data)) {
            $update_data['updated_at'] = now();

            DB::table('article_price_type')
                ->where('article_id', $article_id)
                ->where('price_type_id', $price_type_id)
                ->update($update_data);
        }
    }

    /**
     * Restaura los valores de provider_code y cost en la pivot article_provider.
     *
     * @param  int   $article_id  ID del artículo
     * @param  array $old_values  Array con claves 'provider_id', 'provider_code', 'cost'
     * @return void
     */
    protected function restore_provider_pivot(int $article_id, array $old_values): void
    {
        $provider_id = (int)($old_values['provider_id'] ?? 0);

        if ($provider_id === 0) {
            return;
        }

        /* Construimos el array de campos a restaurar. */
        $update_data = [];

        if (array_key_exists('provider_code', $old_values)) {
            $update_data['provider_code'] = $old_values['provider_code'];
        }

        if (array_key_exists('cost', $old_values)) {
            $update_data['cost'] = $old_values['cost'];
        }

        if (!empty($update_data)) {
            $update_data['updated_at'] = now();

            DB::table('article_provider')
                ->where('article_id', $article_id)
                ->where('provider_id', $provider_id)
                ->update($update_data);
        }
    }

    /**
     * Elimina todas las relaciones de los artículos creados por la importación
     * antes de eliminar los artículos mismos.
     *
     * Esto evita huérfanos en tablas relacionadas y garantiza que el rollback
     * deje la base de datos en estado limpio.
     *
     * @param  array $created_article_ids  IDs de artículos creados por la importación
     * @return void
     */
    protected function delete_created_article_relations(array $created_article_ids): void
    {
        if (empty($created_article_ids)) {
            return;
        }

        /* Descuentos de artículos creados. */
        DB::table('article_discounts')
            ->whereIn('article_id', $created_article_ids)
            ->delete();

        /* Recargos de artículos creados. */
        DB::table('article_surchages')
            ->whereIn('article_id', $created_article_ids)
            ->delete();

        /* Listas de precios (pivot) de artículos creados. */
        DB::table('article_price_type')
            ->whereIn('article_id', $created_article_ids)
            ->delete();

        /* Proveedores (pivot) de artículos creados. */
        DB::table('article_provider')
            ->whereIn('article_id', $created_article_ids)
            ->delete();

        /*
         * Variantes de artículos creados.
         * Usamos each() con delete() para que Eloquent dispare los eventos del modelo
         * y se eliminen en cascada los pivots de article_property_values y addresses
         * si el modelo ArticleVariant tiene observers o deletes encadenados.
         */
        ArticleVariant::whereIn('article_id', $created_article_ids)
            ->each(function (ArticleVariant $variant) {
                /*
                 * Desvinculamos manualmente los pivots de valores de propiedad y
                 * direcciones antes de eliminar la variante para garantizar limpieza
                 * independientemente de que el modelo tenga cascades configuradas.
                 */
                if (method_exists($variant, 'article_property_values')) {
                    $variant->article_property_values()->detach();
                }

                if (method_exists($variant, 'addresses')) {
                    $variant->addresses()->detach();
                }

                $variant->delete();
            });

        Log::info('RollbackArticleImportHistory: relaciones de artículos creados eliminadas', [
            'created_article_ids_count' => count($created_article_ids),
        ]);
    }
}
