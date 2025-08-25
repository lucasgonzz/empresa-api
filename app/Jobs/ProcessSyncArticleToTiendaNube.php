<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\TiendaNubeStore;
use App\Services\TiendaNube\TiendaNubeProductService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessSyncArticleToTiendaNube implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;
    public $backoff = 30;

    protected $article_id;

    public function __construct(Article $article)
    {
        $this->article_id = $article->id;
    }

    public function handle()
    {
        $article = Article::find($this->article_id);
        if (!$article) return;

        Log::info('Handle de ProcessSyncArticleToTiendaNube');
        
        $service = new TiendaNubeProductService();
        $service->crearOActualizarProducto($article);
    }
}
