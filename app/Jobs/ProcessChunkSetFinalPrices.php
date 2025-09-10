<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\Article;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessChunkSetFinalPrices implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $article_ids;
    protected $user;

    public function __construct(array $article_ids, $user)
    {
        $this->article_ids = $article_ids;
        $this->user = $user;
    }

    public function handle()
    {
        $articles = Article::whereIn('id', $this->article_ids)->get();

        foreach ($articles as $article) {
            ArticleHelper::setFinalPrice($article, $this->user->id, $this->user);
        }
    }
}
