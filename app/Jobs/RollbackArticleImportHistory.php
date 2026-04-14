<?php

namespace App\Jobs;

use App\Models\Article;
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
                     * Extraemos el nombre de columna quitando el prefijo.
                     */
                    $field_name = substr($key, 8);

                    /**
                     * Ignoramos campos no existentes en articles y diffs que
                     * no sean escalares para cumplir el alcance acordado.
                     */
                    if (!isset($article_columns[$field_name])) {
                        continue;
                    }

                    if (!is_array($value) || !array_key_exists('old', $value)) {
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

        DB::transaction(function () use ($restore_map, $created_article_ids, $import_history) {
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
             * Eliminamos artículos creados por la importación.
             * Se usa delete() para respetar SoftDeletes del modelo Article.
             */
            if (!empty($created_article_ids)) {
                Article::whereIn('id', $created_article_ids)->delete();
            }

            /**
             * Dejamos trazabilidad básica en observations del historial.
             */
            $rollback_observation = 'Rollback ejecutado. Articulos restaurados: '
                . count($restore_map)
                . '. Articulos creados eliminados: '
                . count($created_article_ids)
                . '.';

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
            'import_history_id' => $this->import_history_id,
            'restored_articles' => count($restore_map),
            'deleted_created_articles' => count($created_article_ids),
        ]);
    }
}
