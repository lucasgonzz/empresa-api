<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\StockSuggestion;
use App\Models\User;
use App\Notifications\GlobalNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Genera y procesa sugerencias de stock por lotes de artículos.
 * Los chunks se procesan en el mismo job para no depender de múltiples workers.
 */
class GenerateStockSuggestionChunksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int Reintentos antes de marcar la corrida como fallida */
    public $tries = 3;

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

        // Lotes de IDs para procesar sin disparar jobs hijos en cola.
        // Filtrado por el dueño de la sugerencia: sin este where, en una base
        // con varios user_id se encolaban artículos de otros comercios.
        $article_ids_batches = [];

        Article::select('id')
            ->where('user_id', $suggestion->user_id)
            ->chunk($this->chunk_size, function ($articles) use (&$article_ids_batches) {
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

    /**
     * Marca la corrida como fallida cuando el job agotó sus reintentos: sin
     * esto, la sugerencia quedaba 'pendiente' para siempre.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        Log::error('GenerateStockSuggestionChunksJob: failed()', [
            'stock_suggestion_id' => $this->stock_suggestion_id,
            'message'             => $exception->getMessage(),
        ]);

        $suggestion = StockSuggestion::find($this->stock_suggestion_id);

        // Si por alguna carrera ya quedó terminada, no se pisa con error.
        if (!$suggestion || $suggestion->status === 'terminado') {
            return;
        }

        $suggestion->status = 'error';
        $suggestion->error_mensaje = $exception->getMessage();
        $suggestion->save();

        $user = User::find($suggestion->user_id);

        if (!$user) {
            return;
        }

        $user->notify(new GlobalNotification([
            'message_text'          => 'La sugerencia de stock falló y quedó marcada con error',
            'color_variant'         => 'danger',
            'functions_to_execute'  => [
                [
                    'btn_text'      => 'Entendido',
                    'btn_variant'   => 'primary',
                ],
            ],
            'info_to_show'          => [],
            'owner_id'              => $user->id,
            'is_only_for_auth_user' => false,
        ]));
    }
}
