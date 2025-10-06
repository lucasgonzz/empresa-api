<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\Article;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessChunkSetFinalPrices implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $article_ids;
    protected $user_id;

    public function __construct(array $article_ids, $user_id)
    {
        $this->article_ids = $article_ids;
        $this->user_id = $user_id;
    }

    public function handle()
    {
        Log::info('Procesando chunck');
        $user = User::find($this->user_id);
        
        $articles = Article::whereIn('id', $this->article_ids)->get();

        foreach ($articles as $article) {
            ArticleHelper::setFinalPrice($article, $user->id, $user);
        }
    }
}
