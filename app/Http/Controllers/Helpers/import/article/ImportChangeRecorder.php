<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Models\ImportHistory;
use Illuminate\Support\Facades\Log;

class ImportChangeRecorder
{
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
}
