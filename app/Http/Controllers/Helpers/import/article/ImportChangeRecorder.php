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

    /*
     * Acá vivía logRelationUpdated(): buscaba el artículo en el pivot
     * `article_actualizados_import_history` (nivel ImportHistory) para sumarle el diff, pero ese
     * pivot no lo escribe nadie (logCreated/logUpdated no tienen callers), así que eran dos
     * consultas por diff, por artículo, para no escribir nunca nada. Se eliminó en la misión
     * importacion-excel-motor-rapido (24/9/2026); el diff se registra únicamente con
     * mergeRelationDiffIntoArticleProps(), sobre el cache del chunk.
     */

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
