<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\StockMovement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessSetStockResultante implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $user;

    public function __construct($user)
    {
        $this->user = $user;
    }

    
    public function handle()
    {
        Log::info('Entro a hanlde de ProcessSetStockResultante');
        $articles = Article::where('user_id', $this->user->id)
                            ->get();

        foreach ($articles as $article) {
            $this->set_stock_resultante_del_ultimo($article);
        }
        Log::info('Termino');
    }



    function set_stock_resultante_del_ultimo($article) {

        $last_stock_movement = StockMovement::where('article_id', $article->id)
                                            ->orderBy('created_at', 'DESC')
                                            ->first();

        if (!is_null($last_stock_movement)) {
            $last_stock_movement->stock_resultante = $article->stock;
            $last_stock_movement->observations = 'Se seteo stock resultante con el stock actual';
            $last_stock_movement->save();

            $this->limpiar_stock_resultante_de_los_anteriores($article, $last_stock_movement);

        }


    }

    function limpiar_stock_resultante_de_los_anteriores($article, $last_stock_movement) {

        $stock_movements_anteriories = StockMovement::where('article_id', $article->id)
                                                    ->where('id', '!=', $last_stock_movement->id)
                                                    ->orderBy('created_at', 'DESC')
                                                    ->get();

        foreach ($stock_movements_anteriories as $stock_movement) {
            $stock_movement->observations = null;
            $stock_movement->stock_resultante = null;
            $stock_movement->save();
        }
    }
}
