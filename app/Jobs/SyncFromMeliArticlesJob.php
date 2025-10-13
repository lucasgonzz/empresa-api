<?php

namespace App\Jobs;

use App\Services\MercadoLibre\ProductoDownloaderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncFromMeliArticlesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 5600;

    public $sync_from_meli_article_id;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($sync_from_meli_article_id)
    {
        $this->sync_from_meli_article_id = $sync_from_meli_article_id;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $service = new ProductoDownloaderService();

        $service->importar_productos('create_only', $this->sync_from_meli_article_id);
    }
}
