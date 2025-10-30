<?php

namespace App\Jobs;

use App\Models\StockSuggestion;
use App\Models\StockSuggestionArticle;
use App\Models\User;
use App\Notifications\GlobalNotification;
use App\Services\StockSuggestion\StockSuggestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessStockSuggestionChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $article_ids;
    protected $stock_suggestion_id;
    protected $suggestion;

    public function __construct(array $article_ids, int $stock_suggestion_id)
    {
        $this->article_ids = $article_ids;
        $this->stock_suggestion_id = $stock_suggestion_id;
        $this->suggestion = StockSuggestion::find($this->stock_suggestion_id);
    }

    public function handle()
    {
        $service = new StockSuggestionService($this->suggestion);

        $suggestions = $service->getSuggestionsForArticles($this->article_ids);

        foreach ($suggestions as $item) {
            StockSuggestionArticle::create([
                'stock_suggestion_id' => $this->stock_suggestion_id,
                'article_id' => $item['article_id'],
                'from_address_id' => $item['from_address_id'],
                'to_address_id' => $item['to_address_id'],
                'suggested_amount' => $item['suggested_amount'],
            ]);
        }

        $this->suggestion->increment('processed_chunks');

        if ($this->suggestion->processed_chunks === $this->suggestion->total_chunks) {
            $this->suggestion->status = 'terminado';
            $this->suggestion->save();
            $this->notificacion();
        }
    }

    function notificacion() {


        $functions_to_execute = [
            [
                'btn_text'      => 'Entendido',
                'btn_variant'   => 'primary',
            ],
        ];

        $info_to_show = [];

        $user = User::find($this->suggestion->user_id);

        $user->notify(new GlobalNotification([
            'message_text'              => 'Sugerencia de stock terminada',
            // 'message_text'              => 'Estamos actualizando tus precios, te notificaremos cuando finalice',
            'color_variant'             => 'primary',
            'functions_to_execute'      => $functions_to_execute,
            'info_to_show'              => $info_to_show,
            'owner_id'                  => $user->id,
            'is_only_for_auth_user'     => false,
        ]));
    }
}
