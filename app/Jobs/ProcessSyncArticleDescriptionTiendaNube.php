<?php

namespace App\Jobs;

use App\Services\TiendaNube\TiendaNubeProductDescriptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessSyncArticleDescriptionTiendaNube implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $article;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($article)
    {
        $this->article = $article;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {

        if (is_null($this->article->tiendanube_product_id)) return;
        
        $service = new TiendaNubeProductDescriptionService();
        $service->update_descriptions($this->article);
    }
}
