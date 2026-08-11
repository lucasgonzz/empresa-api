<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\SetFinalPricesNotificationHelper;
use App\Models\PriceUpdateRun;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cierra una corrida de recálculo de precios: agrega los números y recién ahí notifica.
 *
 * Calcado de FinalizeArticleImport, incluidos los reintentos: se re-despacha con delay
 * mientras falten chunks, lo que NO consume intentos. Se usa ese patrón y no Bus::batch
 * a propósito: InitExcelImport abandonó batch por una condición de carrera y dejó el
 * comentario explicando por qué.
 */
class FinalizeSetFinalPrices implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;
    public $tries = 120;
    public $backoff = 10;

    protected $user_id;
    protected $price_update_run_id;

    public function __construct($user_id, $price_update_run_id)
    {
        $this->user_id = $user_id;
        $this->price_update_run_id = $price_update_run_id;
    }

    public function handle()
    {
        $run = PriceUpdateRun::find($this->price_update_run_id);

        if (is_null($run)) {
            Log::warning('FinalizeSetFinalPrices: la corrida no existe', [
                'price_update_run_id' => $this->price_update_run_id,
            ]);
            return;
        }

        // Ya se cerró (por ejemplo, por un finalizador anterior que ganó la carrera).
        if ($run->status != 'en_proceso') {
            return;
        }

        /*
         * 🔴 Las DOS condiciones, no sólo el conteo. Los chunks se despachan dentro del
         * ->chunk(100, ...) de ProcessSetFinalPrices, así que al principio de la corrida
         * processed_chunks puede alcanzar a total_chunks simplemente porque el bucle
         * todavía no despachó el resto. Cerrar ahí daría un modal con números falsos y
         * sin ningún error visible.
         */
        if (!$run->chunks_encolados || (int) $run->processed_chunks < (int) $run->total_chunks) {
            Log::info('FinalizeSetFinalPrices: todavia faltan chunks, re-dispatch', [
                'price_update_run_id' => $run->id,
                'chunks_encolados'    => $run->chunks_encolados,
                'processed_chunks'    => $run->processed_chunks,
                'total_chunks'        => $run->total_chunks,
            ]);

            self::dispatch($this->user_id, $this->price_update_run_id)
                ->delay(now()->addSeconds(10))
                ->onConnection($this->connection)
                ->onQueue($this->queue);

            return;
        }

        $articles_updated = (int) DB::table('price_update_run_articles')
            ->where('price_update_run_id', $run->id)
            ->count();

        $stats = [
            'proveedores' => $this->agrupar_por($run->id, 'provider'),
            'categorias'  => $this->agrupar_por($run->id, 'category'),
        ];

        $run->articles_updated = $articles_updated;
        $run->stats_json       = json_encode($stats);
        $run->status           = $articles_updated > 0 ? 'terminado' : 'sin_cambios';
        $run->finished_at      = Carbon::now();
        $run->save();

        /*
         * Se notifica también cuando no cambió ningún precio (decisión de Lucas): un cambio
         * de configuración que no movió nada es información, no silencio. El modal tiene su
         * propio estado vacío para eso.
         */
        SetFinalPricesNotificationHelper::notify_prices_updated($this->user_id, $run);
    }

    /**
     * Agrupa los artículos de la corrida por proveedor o por categoría, de mayor a menor.
     *
     * Se hace por SQL y no en PHP porque la lista puede tener decenas de miles de filas:
     * traerlas para contarlas en memoria es lo que después no escala.
     *
     * Los artículos sin proveedor o sin categoría NO se descartan: se agrupan bajo
     * "Sin proveedor" / "Sin categoría". Descartarlos haría que la suma del desglose no
     * cierre con el número grande del modal, y el usuario lo lee como un error.
     *
     * @param  int    $run_id
     * @param  string $tipo  'provider' | 'category'
     * @return array
     */
    protected function agrupar_por($run_id, $tipo)
    {
        $es_proveedor = $tipo == 'provider';

        $tabla_relacionada = $es_proveedor ? 'providers' : 'categories';
        $columna_fk        = $es_proveedor ? 'articles.provider_id' : 'articles.category_id';
        $sin_nombre        = $es_proveedor ? 'Sin proveedor' : 'Sin categoría';

        $filas = DB::table('price_update_run_articles')
            ->join('articles', 'articles.id', '=', 'price_update_run_articles.article_id')
            ->leftJoin($tabla_relacionada, $tabla_relacionada . '.id', '=', DB::raw($columna_fk))
            ->where('price_update_run_articles.price_update_run_id', $run_id)
            ->select(
                DB::raw($columna_fk . ' as relacion_id'),
                DB::raw($tabla_relacionada . '.name as nombre'),
                DB::raw('COUNT(*) as cantidad')
            )
            ->groupBy(DB::raw($columna_fk), DB::raw($tabla_relacionada . '.name'))
            ->orderBy('cantidad', 'DESC')
            ->get();

        $resultado = [];

        foreach ($filas as $fila) {
            $nombre = $fila->nombre;

            if (is_null($nombre) || $nombre === '') {
                $nombre = $sin_nombre;
            }

            $resultado[] = [
                'id'       => $fila->relacion_id,
                'nombre'   => $nombre,
                'cantidad' => (int) $fila->cantidad,
            ];
        }

        return $resultado;
    }

    /**
     * Si el finalizador muere de forma definitiva, la corrida no puede quedar en_proceso
     * para siempre: bloquearía todos los avisos posteriores de ese usuario (ver la guarda
     * de reuso de corrida en ProcessSetFinalPrices).
     *
     * @param  \Throwable $e
     * @return void
     */
    public function failed($e)
    {
        Log::error('FinalizeSetFinalPrices fallo: ' . $e->getMessage());

        $run = PriceUpdateRun::find($this->price_update_run_id);

        if (!is_null($run) && $run->status == 'en_proceso') {
            $run->status      = 'error';
            $run->finished_at = Carbon::now();
            $run->save();
        }

        SetFinalPricesNotificationHelper::notify_prices_update_failed($this->user_id, $this->price_update_run_id);
    }
}
