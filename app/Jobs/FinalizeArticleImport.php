<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\ArticleImportHelper;
use App\Models\ArticleImportResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;

class FinalizeArticleImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $import_uuid, $model_name, $columns, $user, $auth_user_id, $provider_id, $archivo_excel_path;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($import_uuid, $model_name, $columns, $user, $auth_user_id, $provider_id, $archivo_excel_path)
    {
        $this->import_uuid = $import_uuid;
        $this->model_name = $model_name;
        $this->columns = $columns;
        $this->user = $user;
        $this->auth_user_id = $auth_user_id;
        $this->provider_id = $provider_id;
        $this->archivo_excel_path = $archivo_excel_path;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $results = ArticleImportResult::where('import_uuid', $this->import_uuid)->get();

        $articulos_creados = 0;
        $articulos_actualizados = 0;

        Log::info('');
        Log::info('FinalizeArticleImport');
        Log::info('Creando import_history con '.count($results).' results y import_uuid: '.$this->import_uuid);

        foreach ($results as $result) {
            Log::Info('Sumando '.$result->created_count.' creados');
            Log::Info('Sumando '.$result->updated_count.' actualizados');
            $articulos_creados += $result->created_count;
            $articulos_actualizados += $result->updated_count;
        }

        // Limpiar resultados intermedios
        ArticleImportResult::where('import_uuid', $this->import_uuid)->delete();
            
        ArticleImportHelper::create_import_history($this->user, $this->auth_user_id, $this->provider_id, $this->columns, $this->archivo_excel_path, null, $articulos_creados, $articulos_actualizados);

        ArticleImportHelper::enviar_notificacion($this->user, $articulos_creados, $articulos_actualizados);

        Log::info('Se envio notificacion');

        Artisan::call('set_article_address_stock_from_variants', [
            'user_id' => $this->user->id
        ]);
    }
}
