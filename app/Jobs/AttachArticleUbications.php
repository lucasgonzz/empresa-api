<?php

namespace App\Jobs;

use App\Jobs\AttachArticleUbicationChunk;
use App\Models\Article;
use App\Models\ArticleUbication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AttachArticleUbications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $article_ubication_id;

    public function __construct($article_ubication_id)
    {
        $this->article_ubication_id = $article_ubication_id;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $article_ubication = ArticleUbication::find($this->article_ubication_id);

        if (!$article_ubication) {
            return;
        }

        Article::where('user_id', $article_ubication->user_id)
            ->chunk(1000, function ($articles) use ($article_ubication) {
                $article_ids = $articles->pluck('id')->toArray();
                AttachArticleUbicationChunk::dispatch($article_ids, $article_ubication->id);
            });
    }
}
