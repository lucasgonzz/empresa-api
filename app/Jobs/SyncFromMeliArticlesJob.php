<?php

namespace App\Jobs;

use App\Models\SyncFromMeliArticle;
use App\Services\MercadoLibre\ProductoDownloaderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Job en cola: importa publicaciones ML hacia artículos locales para un registro de sync.
 */
class SyncFromMeliArticlesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var int Tiempo máximo de ejecución (catálogos grandes). */
    public $timeout = 5600;

    /** @var int Id de sync_from_meli_articles */
    public $sync_from_meli_article_id;

    /**
     * @param int $sync_from_meli_article_id Registro de sincronización a procesar.
     */
    public function __construct($sync_from_meli_article_id)
    {
        $this->sync_from_meli_article_id = $sync_from_meli_article_id;
    }

    /**
     * Ejecuta importación en modo create_only (no modifica artículos ya vinculados).
     *
     * @return void
     */
    public function handle()
    {
        $sync_record = SyncFromMeliArticle::find($this->sync_from_meli_article_id);
        if (!$sync_record) {
            return;
        }

        $service = new ProductoDownloaderService($sync_record->user_id);
        $service->importar_productos('create_only', $this->sync_from_meli_article_id);
    }
}
