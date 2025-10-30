<?php

namespace App\Jobs;

use App\Jobs\ProcessStockSuggestionChunkJob;
use App\Models\Article;
use App\Models\StockSuggestion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateStockSuggestionChunksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $stock_suggestion_id;
    protected $chunk_size = 5000;

    public function __construct($stock_suggestion_id)
    {
        $this->stock_suggestion_id = $stock_suggestion_id;
    }

    public function handle()
    {
        $suggestion = StockSuggestion::find($this->stock_suggestion_id);

        $chunk_count = 0;

        Article::select('id')->chunk($this->chunk_size, function ($articles) use ($suggestion, &$chunk_count) {
            $chunk_count++;

            $article_ids = $articles->pluck('id')->toArray();

            ProcessStockSuggestionChunkJob::dispatch(
                $article_ids,
                $suggestion->id,
            );
        });

        $suggestion->update(['total_chunks' => $chunk_count]);
    }
}
