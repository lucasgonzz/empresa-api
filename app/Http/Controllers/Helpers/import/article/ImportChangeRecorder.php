<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Models\ImportHistory;
use Illuminate\Support\Facades\Log;

/**
 * Registra qué artículos se crearon o actualizaron durante una importación,
 * junto con los diffs de columnas directas y de relaciones para permitir rollback.
 */
class ImportChangeRecorder
{
    /**
     * Registra un artículo creado en el historial de importación.
     *
     * @param int $import_history_id
     * @param int $article_id
     * @return void
     */
    public static function logCreated(int $import_history_id, int $article_id): void
    {
        try {
            $import = ImportHistory::find($import_history_id);
            if ($import) {
                $import->articulos_creados()->syncWithoutDetaching([$article_id]);
            }
        } catch (\Throwable $th) {
            Log::error("ImportChangeRecorder::logCreated - {$th->getMessage()}");
        }
    }

    /**
     * Registra un artículo actualizado con el JSON completo de cambios (columnas directas).
     *
     * @param int   $import_history_id
     * @param int   $article_id
     * @param array $changes
     * @return void
     */
    public static function logUpdated(int $import_history_id, int $article_id, array $changes): void
    {
        try {
            $import = ImportHistory::find($import_history_id);
            if ($import) {
                $import->articulos_actualizados()->syncWithoutDetaching([
                    $article_id => [
                        'updated_props' => json_encode(
                            $changes,
                            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
                        ),
                    ],
                ]);
            }
        } catch (\Throwable $th) {
            Log::error("ImportChangeRecorder::logUpdated - {$th->getMessage()}");
        }
    }

    /**
     * Registra el diff de una relación modificada durante la importación.
     *
     * Solo actualiza el pivot si el artículo ya está registrado en articulos_actualizados
     * del ImportHistory. Es best-effort: si falla, loguea y continúa.
     *
     * @param int    $import_history_id  ID del historial de importación
     * @param int    $article_id         ID del artículo actualizado
     * @param string $diff_key           Clave sin prefijo (ej: "discounts_percent", "price_type_3")
     * @param mixed  $old_value          Estado previo de la relación
     * @param mixed  $new_value          Estado nuevo aplicado por la importación
     * @return void
     */
    public static function logRelationUpdated(
        int $import_history_id,
        int $article_id,
        string $diff_key,
        $old_value,
        $new_value
    ): void {
        try {
            $import = ImportHistory::find($import_history_id);

            if (!$import) {
                return;
            }

            /*
             * Buscamos si ya existe un registro para este artículo en esta importación.
             * Solo trackeamos artículos ya registrados como actualizados.
             */
            $existing = $import->articulos_actualizados()
                ->where('article_id', $article_id)
                ->first();

            if (!$existing) {
                return;
            }

            $pivot_props = $existing->pivot->updated_props ?? null;

            $current_props = is_array($pivot_props)
                ? $pivot_props
                : json_decode($pivot_props ?? '{}', true);

            if (!is_array($current_props)) {
                $current_props = [];
            }

            $diff_full_key = '__diff__' . $diff_key;

            /*
             * Solo guardamos el primer old detectado para restaurar el estado
             * previo real a toda la importación.
             */
            if (!array_key_exists($diff_full_key, $current_props)) {
                $current_props[$diff_full_key] = [
                    'old' => $old_value,
                    'new' => $new_value,
                ];

                $import->articulos_actualizados()->updateExistingPivot($article_id, [
                    'updated_props' => json_encode($current_props, JSON_UNESCAPED_UNICODE),
                ]);
            }
        } catch (\Throwable $th) {
            Log::error("ImportChangeRecorder::logRelationUpdated - {$th->getMessage()}");
        }
    }

    /**
     * Fusiona un diff de relación en el array de props de un artículo del cache.
     *
     * El cache se persiste luego en el pivot del chunk (ArticleImportResult) y es
     * lo que lee RollbackArticleImportHistory al revertir una importación.
     *
     * @param array  $article_props  Referencia al array del artículo en articulos_para_actualizar_CACHE
     * @param string $diff_key       Clave sin prefijo __diff__
     * @param mixed  $old_value
     * @param mixed  $new_value
     * @return void
     */
    public static function mergeRelationDiffIntoArticleProps(
        array &$article_props,
        string $diff_key,
        $old_value,
        $new_value
    ): void {
        $diff_full_key = '__diff__' . $diff_key;

        if (!array_key_exists($diff_full_key, $article_props)) {
            $article_props[$diff_full_key] = [
                'old' => $old_value,
                'new' => $new_value,
            ];
        }
    }
}
