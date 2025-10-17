<?php

namespace App\Jobs;

use App\Models\ArticleUbication;
use App\Models\Article;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class AttachArticleUbicationChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $article_ids;
    protected $article_ubication_id;

    public function __construct(array $article_ids, $article_ubication_id)
    {
        $this->article_ids = $article_ids;
        $this->article_ubication_id = $article_ubication_id;
    }

    public function handle(): void
    {
        $article_ubication = ArticleUbication::find($this->article_ubication_id);

        if (!$article_ubication) return;

        $articles = Article::whereIn('id', $this->article_ids)->get();

        foreach ($articles as $article) {
            $article->article_ubications()->attach($article_ubication->id);
        }
    }
}
