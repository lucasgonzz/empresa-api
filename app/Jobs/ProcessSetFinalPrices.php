<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\SetFinalPricesNotificationHelper;
use App\Models\Article;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Jobs\ProcessChunkSetFinalPrices;

class ProcessSetFinalPrices implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */

    public $user_id, $from_model_id, $model_id, $from_dolar;

    public function __construct($user_id, $from_model_id = null, $model_id = null, $from_dolar = false)
    {

        $this->user_id = $user_id;
        $this->from_model_id = $from_model_id;
        $this->model_id = $model_id;
        $this->from_dolar = $from_dolar;
        
    }


    public function handle()
    {
        Log::info('ProcessSetFinalPrices');

        try {

            if (!is_null($this->from_model_id)) {
                $articles_query = Article::where($this->from_model_id, $this->model_id)->select('id');
                Log::info('Obteniendo articulos from_model_id');
            } else if (
                !is_null($this->from_dolar)
                && $this->from_dolar
            ) {
                // $articles_query = Article::where('user_id', $this->user_id)
                //                         ->where('cost_in_dollars', 1)
                //                         ->select('id');
                // Log::info('Obteniendo articulos en con costos en dolares');
                $articles_query = Article::where('user_id', $this->user_id)
                                        ->where(function ($q) {
                                            $q->where('cost_in_dollars', 1)
                                              ->orWhereHas('price_type_monedas', function ($q2) {
                                                  $q2->where('cotizar_desde_otra_moneda', 1);
                                              });
                                        })
                                        ->select('id');

                Log::info('Obteniendo articulos con costos en dolares O con price_type_monedas cotizando desde otra moneda');
            } else {
                $articles_query = Article::where('user_id', $this->user_id)->select('id');
            }

            $articles_query->chunk(100, function ($articles_chunk) {
                $ids = $articles_chunk->pluck('id')->toArray();
                dispatch(new ProcessChunkSetFinalPrices($ids, $this->user_id));
            });

            SetFinalPricesNotificationHelper::notify_prices_updated($this->user_id);

        } catch (\Exception $e) {
            Log::error("Error en ProcessSetFinalPrices: " . $e->getMessage());
            SetFinalPricesNotificationHelper::notify_prices_update_failed($this->user_id);
        }
    }
}
