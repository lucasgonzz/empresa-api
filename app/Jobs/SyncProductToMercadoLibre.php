<?php

namespace App\Jobs;

use App\Models\Article;
use App\Services\MercadoLibre\ProductService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncProductToMercadoLibre implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $article_id;

    public function __construct(int $article_id)
    {
        $this->article_id = $article_id;
    }

    public function handle()
    {
        $article = Article::with(['images', 'sub_category'])->find($this->article_id);

        $service = new ProductService();
        $service->sync_article($article);
    }
}
