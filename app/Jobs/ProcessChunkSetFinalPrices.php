<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\SetFinalPricesNotificationHelper;
use App\Models\Article;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessChunkSetFinalPrices implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $article_ids;
    protected $user_id;

    /**
     * Corrida a la que pertenece este chunk. Opcional por compatibilidad: los llamados
     * viejos siguen funcionando y simplemente no registran nada.
     *
     * @var int|null
     */
    protected $price_update_run_id;

    public function __construct(array $article_ids, $user_id, $price_update_run_id = null)
    {
        $this->article_ids = $article_ids;
        $this->user_id = $user_id;
        $this->price_update_run_id = $price_update_run_id;
    }

    public function handle()
    {
        Log::info('Procesando chunck');
        $user = User::find($this->user_id);

        $articles = Article::whereIn('id', $this->article_ids)->get();

        /** Ids de los artículos cuyo final_price efectivamente cambió en este chunk. */
        $ids_que_cambiaron = [];

        foreach ($articles as $article) {
            /*
             * El precio de antes se guarda ACA y no adentro del helper: setFinalPrice ya
             * hace su propia comparación para price_changes, pero no la expone. Tener el
             * artículo en la mano alcanza, y así el helper no se toca (está bajo refactor
             * en otra rama y tocarlo garantiza conflicto).
             */
            $precio_anterior = $article->final_price;

            ArticleHelper::setFinalPrice($article, $user->id, $user);

            if (!is_null($this->price_update_run_id) && $precio_anterior != $article->final_price) {
                $ids_que_cambiaron[] = $article->id;
            }
        }

        $this->registrar_articulos_que_cambiaron($ids_que_cambiaron);
        $this->marcar_chunk_procesado();
    }

    /**
     * Se ejecuta cuando el chunk falla de forma definitiva, INCLUIDO cuando el proceso muere
     * sin llegar a ningún catch (OOM, timeout, worker reiniciado). Corre en un proceso
     * fresco, así que puede escribir en la base aunque el anterior se haya quedado sin
     * memoria. Mismo patrón que ProcessArticleChunk.
     *
     * 🔴 Sin esto, un chunk muerto deja processed_chunks por debajo de total_chunks para
     * siempre: el finalizador se re-despacha cada 10 segundos indefinidamente y el usuario
     * nunca recibe nada. Y ahora que el aviso sale al final, no recibir nada es no enterarse
     * de que su recálculo se murió.
     *
     * @param  \Throwable $e
     * @return void
     */
    public function failed($e)
    {
        Log::error('ProcessChunkSetFinalPrices fallo: ' . $e->getMessage());

        SetFinalPricesNotificationHelper::notify_prices_update_failed(
            $this->user_id,
            $this->price_update_run_id,
            'Se interrumpió el recálculo de un lote de artículos y la actualización quedó incompleta: ' . $e->getMessage()
        );
    }

    /**
     * Guarda los artículos que cambiaron de precio, en lotes.
     *
     * insertOrIgnore contra el único (price_update_run_id, article_id): si este job se
     * reintenta, los que ya estaban no se duplican, y como el total sale de un COUNT y no
     * de un contador acumulado, el número que ve el usuario sigue siendo el real.
     *
     * @param  array $ids_que_cambiaron
     * @return void
     */
    protected function registrar_articulos_que_cambiaron($ids_que_cambiaron)
    {
        if (is_null($this->price_update_run_id) || count($ids_que_cambiaron) == 0) {
            return;
        }

        foreach (array_chunk($ids_que_cambiaron, 500) as $lote) {
            $filas = [];

            foreach ($lote as $article_id) {
                $filas[] = [
                    'price_update_run_id' => $this->price_update_run_id,
                    'article_id'          => $article_id,
                ];
            }

            DB::table('price_update_run_articles')->insertOrIgnore($filas);
        }
    }

    /**
     * Un solo UPDATE con DB::raw, igual que ProcessArticleChunk: dos workers terminando a
     * la vez no se pisan el incremento.
     *
     * @return void
     */
    protected function marcar_chunk_procesado()
    {
        if (is_null($this->price_update_run_id)) {
            return;
        }

        DB::table('price_update_runs')
            ->where('id', $this->price_update_run_id)
            ->update(['processed_chunks' => DB::raw('processed_chunks + 1')]);
    }
}
