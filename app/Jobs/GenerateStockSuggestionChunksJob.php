<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Models\Article;
use App\Models\StockSuggestion;
use App\Models\StockSuggestionArticle;
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
     * Si la corrida se muestra en la píldora de procesos (misión procesos-en-segundo-plano,
     * 18/9/2026). Lo prende SOLO el controller cuando encola por catálogo grande: el camino
     * inline (hasta 500 artículos) termina dentro del mismo request y la vista ya lo ve, y el
     * comando programado corre sin que nadie lo haya pedido. Default false para que esos dos
     * caminos —y un job encolado antes de este deploy— sigan exactamente igual.
     *
     * @var bool
     */
    protected $visible_para_el_usuario = false;

    /**
     * @param int  $stock_suggestion_id      ID de stock_suggestions
     * @param bool $visible_para_el_usuario  true sólo al encolar desde el controller.
     */
    public function __construct($stock_suggestion_id, $visible_para_el_usuario = false)
    {
        $this->stock_suggestion_id = $stock_suggestion_id;
        $this->visible_para_el_usuario = (bool) $visible_para_el_usuario;
    }

    /**
     * El registro visible de esta corrida, o null si no corresponde mostrarla.
     *
     * Con $tries = 3 un reintento vuelve a entrar acá: se reusa la fila abierta que dejó el
     * intento anterior (por_referencia) en vez de abrir una segunda, y el avance arranca de
     * cero junto con la re-entrada limpia del handle().
     *
     * @param  \App\Models\StockSuggestion $suggestion
     * @return \App\Models\BackgroundProcess|null
     */
    protected function proceso_visible($suggestion)
    {
        if (!$this->visible_para_el_usuario) {
            return null;
        }

        $proceso = BackgroundProcessHelper::por_referencia($suggestion);

        if (!is_null($proceso)) {
            return $proceso;
        }

        return BackgroundProcessHelper::iniciar($suggestion->user_id, 'sugerencias_stock', 'Sugerencias de stock', [
            'referencia' => $suggestion,
            'unidad'     => 'lotes',
            'etapa'      => 'Preparando los lotes',
        ]);
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

        $proceso = $this->proceso_visible($suggestion);

        // Re-entrada limpia ANTES de calcular total_chunks: con $tries = 3, un
        // reintento tras un fallo parcial re-inserta desde cero en vez de
        // duplicar lo que la corrida anterior alcanzó a escribir; y si un
        // update() encoló una segunda corrida, la segunda limpia lo de la
        // primera y el resultado final es consistente (worker único secuencial).
        StockSuggestionArticle::where('stock_suggestion_id', $suggestion->id)->delete();

        $suggestion->update([
            'processed_chunks'  => 0,
            'status'            => 'pendiente',
            'error_mensaje'     => null,
            'resumen_ia'        => null,
            'resumen_ia_estado' => null,
            'resumen_ia_error'  => null,
        ]);

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

        // Recién acá el registro visible conoce su total en lotes (procesados en 0: si es un
        // reintento, arranca de nuevo igual que la corrida).
        BackgroundProcessHelper::avanzar($proceso, 0, ['total' => $chunk_count, 'etapa' => 'Evaluando el catálogo']);

        if ($chunk_count === 0) {
            $suggestion->update(['status' => 'terminado']);
            BackgroundProcessHelper::completar($proceso, ['articulos' => 0], 'Sin artículos que evaluar');
            return;
        }

        $lotes_procesados = 0;

        foreach ($article_ids_batches as $article_ids) {
            (new ProcessStockSuggestionChunkJob($article_ids, $suggestion->id))->handle();

            $lotes_procesados++;
            BackgroundProcessHelper::avanzar($proceso, $lotes_procesados, [
                'etapa' => 'Lote ' . $lotes_procesados . ' de ' . $chunk_count,
            ]);
        }

        // Los artículos sugeridos son las filas que dejaron los lotes: es el número que va a
        // ver en la vista, no el del catálogo recorrido.
        BackgroundProcessHelper::completar($proceso, [
            'articulos' => (int) StockSuggestionArticle::where('stock_suggestion_id', $suggestion->id)->count(),
        ]);
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

        // El registro visible cae con la corrida. Si no se mostraba (inline o scheduler),
        // por_referencia() no encuentra nada y el helper no hace nada.
        BackgroundProcessHelper::fallar(BackgroundProcessHelper::por_referencia($suggestion), $exception->getMessage());

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
