<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\Article;
use App\Models\User;
use App\Notifications\GlobalNotification;
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
            } else if (!is_null($this->from_dolar)) {
                $articles_query = Article::where('user_id', $this->user_id)
                                        ->where('cost_in_dollars', 1)
                                        ->select('id');
                Log::info('Obteniendo articulos en con costos en dolares');
            } else {
                $articles_query = Article::where('user_id', $this->user_id)->select('id');
            }

            $articles_query->chunk(2000, function ($articles_chunk) {
                $ids = $articles_chunk->pluck('id')->toArray();
                dispatch(new ProcessChunkSetFinalPrices($ids, $this->user_id));
            });

            $this->notificacion();

        } catch (\Exception $e) {
            \Log::error("Error en ProcessSetFinalPrices: " . $e->getMessage());
            $this->notificacion_error();
        }
    }

    // public function handle()
    // {
    //     try {

    //         if (!is_null($this->from_model_id)) {

    //             $articles = Article::where($this->from_model_id, $this->model_id)
    //                                 ->get();

    //             Log::info('ProcessSetFinalPrices para '.count($articles).' articulos');

    //             foreach ($articles as $article) {
    //                 ArticleHelper::setFinalPrice($article, $this->user->id);
    //             }
    //         } else {
    //             ArticleHelper::setArticlesFinalPrice(null, $this->user->id);
    //         }
    //         $this->notificacion();

    //     } catch (\Exception $e) {
    //         $this->notificacion_error();
    //     }

    // }

    function notificacion() {

        $functions_to_execute = [
            [
                'btn_text'      => 'Entendido',
                'btn_variant'   => 'primary',
            ],
        ];

        $info_to_show = [];

        $user = User::find($this->user_id);

        $user->notify(new GlobalNotification([
            'message_text'              => 'Precios actualizados',
            // 'message_text'              => 'Estamos actualizando tus precios, te notificaremos cuando finalice',
            'color_variant'             => 'primary',
            'functions_to_execute'      => $functions_to_execute,
            'info_to_show'              => $info_to_show,
            'owner_id'                  => $user->id,
            'is_only_for_auth_user'     => false,
        ]));
    }

    function notificacion_error() {

        $functions_to_execute = [
            [
                'btn_text'      => 'Entendido',
                'btn_variant'   => 'primary',
            ],
        ];

        $info_to_show = [];

        $user = User::find($this->user_id);

        $user->notify(new GlobalNotification([
            'message_text'              => 'Error al actualizar Precios',
            // 'message_text'              => 'Estamos actualizando tus precios, te notificaremos cuando finalice',
            'color_variant'             => 'danger',
            'functions_to_execute'      => $functions_to_execute,
            'info_to_show'              => $info_to_show,
            'owner_id'                  => $user->id,
            'is_only_for_auth_user'     => false,
        ]));
    }
}
