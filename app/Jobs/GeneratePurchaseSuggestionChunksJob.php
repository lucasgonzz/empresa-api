<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\PurchaseSuggestion;
use App\Models\PurchaseSuggestionArticle;
use App\Models\User;
use App\Notifications\GlobalNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Arma los lotes de artículos de una corrida de sugerencia de compra y
 * procesa cada uno en el mismo job (mismo patrón que
 * GenerateStockSuggestionChunksJob): no depende de varios workers.
 */
class GeneratePurchaseSuggestionChunksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int Reintentos antes de marcar la corrida como fallida */
    public $tries = 3;

    /** @var int Identificador de la sugerencia a procesar */
    protected $purchase_suggestion_id;

    /** @var int Cantidad de artículos por lote interno */
    protected $chunk_size = 5000;

    /**
     * @param int $purchase_suggestion_id ID de purchase_suggestions
     */
    public function __construct($purchase_suggestion_id)
    {
        $this->purchase_suggestion_id = $purchase_suggestion_id;
    }

    /**
     * Arma los lotes, persiste total_chunks y ejecuta cada lote de forma secuencial.
     *
     * @return void
     */
    public function handle()
    {
        $suggestion = PurchaseSuggestion::find($this->purchase_suggestion_id);

        if (!$suggestion) {
            return;
        }

        // Re-entrada limpia ANTES de calcular total_chunks: con $tries = 3,
        // un reintento tras un fallo parcial re-inserta desde cero en vez de
        // duplicar lo que la corrida anterior alcanzó a escribir; y si algo
        // volvió a encolar esta misma sugerencia, la corrida nueva limpia lo
        // de la anterior y el resultado final es consistente (worker único
        // secuencial, igual que GenerateStockSuggestionChunksJob).
        PurchaseSuggestionArticle::where('purchase_suggestion_id', $suggestion->id)->delete();

        $suggestion->update([
            'processed_chunks'    => 0,
            'status'              => 'pendiente',
            'error_mensaje'       => null,
            'resumen_ia'          => null,
            'resumen_ia_estado'   => null,
            'resumen_ia_error'    => null,
            // Arreglo A5 del chequeo post-misión: sin este reset, re-correr
            // una sugerencia que ya había cerrado dejaba el total y el
            // contexto financiero de la corrida ANTERIOR mostrados junto a
            // líneas nuevas — o junto a CERO líneas nuevas, si el catálogo
            // quedó vacío (chunk_count === 0 más abajo marca 'terminado' y
            // sale sin volver a calcular nada). Se resetean ACÁ, antes de
            // procesar, para que ningún estado intermedio ni el caso de
            // catálogo vacío puedan mostrar la plata de otra corrida. El
            // cierre normal (ProcessPurchaseSuggestionChunkJob) los vuelve a
            // calcular sobre las líneas de ESTA corrida.
            'total_estimado'      => null,
            'contexto_financiero' => null,
        ]);

        // Lotes de IDs para procesar sin disparar jobs hijos en cola.
        // Filtrado por el dueño de la sugerencia: sin este where, en una
        // base con varios user_id se mezclaba catálogo de otros comercios.
        // El filtro por status ('inactive' afuera) vive en
        // PurchaseSuggestionService, no acá: esta es solo la lista de IDs a
        // repartir en chunks.
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
            (new ProcessPurchaseSuggestionChunkJob($article_ids, $suggestion->id))->handle();
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
        Log::error('GeneratePurchaseSuggestionChunksJob: failed()', [
            'purchase_suggestion_id' => $this->purchase_suggestion_id,
            'message'                => $exception->getMessage(),
        ]);

        $suggestion = PurchaseSuggestion::find($this->purchase_suggestion_id);

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
            'message_text'          => 'La sugerencia de compra falló y quedó marcada con error',
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
