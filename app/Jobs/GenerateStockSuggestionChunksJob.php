<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\StockSuggestion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Genera y procesa sugerencias de stock por lotes de artículos.
 * Los chunks se procesan en el mismo job para no depender de múltiples workers.
 */
class GenerateStockSuggestionChunksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int Identificador de la sugerencia a procesar */
    protected $stock_suggestion_id;

    /** @var int Cantidad de artículos por lote interno */
    protected $chunk_size = 5000;

    /**
     * @param int $stock_suggestion_id ID de stock_suggestions
     */
    public function __construct($stock_suggestion_id)
    {
        $this->stock_suggestion_id = $stock_suggestion_id;
    }

    /**
     * Arma los lotes, persiste total_chunks y ejecuta cada lote de forma secuencial.
     *
     * @return void
     */
    public function handle()
    {
        $suggestion = StockSuggestion::find($this->stock_suggestion_id);

        if (!$suggestion) {
            return;
        }

        // Lotes de IDs para procesar sin disparar jobs hijos en cola
        $article_ids_batches = [];

        Article::select('id')->chunk($this->chunk_size, function ($articles) use (&$article_ids_batches) {
            $article_ids_batches[] = $articles->pluck('id')->toArray();
        });

        $chunk_count = count($article_ids_batches);

        // total_chunks antes de procesar evita condiciones de carrera al marcar terminado
        $suggestion->update(['total_chunks' => $chunk_count]);

        if ($chunk_count === 0) {
            $suggestion->update(['status' => 'terminado']);
            return;
        }

        foreach ($article_ids_batches as $article_ids) {
            (new ProcessStockSuggestionChunkJob($article_ids, $suggestion->id))->handle();
        }
    }
}
