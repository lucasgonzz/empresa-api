<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\TiendaNubeStore;
use App\Services\TiendaNube\TiendaNubeImageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessSyncArticleImageToTiendaNube implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;
    public $backoff = 30;

    protected $article;
    protected $image;

    public function __construct(Article $article, $image)
    {
        $this->article = $article;
        $this->image = $image;
    }

    public function handle()
    {
        $service = new TiendaNubeImageService();
        $service->subirImagenDeArticulo($this->article, $this->image);
    }
}
