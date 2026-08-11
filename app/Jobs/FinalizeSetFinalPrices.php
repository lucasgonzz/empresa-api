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

    /**
     * Horas después de las cuales una corrida que no puede cerrar se da por perdida.
     *
     * No es un número fino: es el techo que impide el re-despacho eterno. Un recálculo del
     * catálogo entero de un comercio grande se mide en minutos, así que dos horas es
     * holgadamente "esto ya no va a terminar".
     */
    const TOPE_HORAS = 2;

    /**
     * Minutos que se le dan al productor para terminar de encolar sus chunks.
     *
     * El bucle que los despacha se mide en segundos y el worker lo mata a los 60, así que
     * media hora es holgadamente "este job ya no existe".
     */
    const TOPE_MINUTOS_SIN_ENCOLAR = 30;

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
            /*
             * 🔴 Topes de reloj. El re-despacho no consume intentos, así que sin esta guarda
             * una corrida que perdió un chunk —o cuyo productor se murió antes de encolarlos—
             * se re-despacharía para siempre y el usuario nunca recibiría nada. Ahora que el
             * aviso sale al final, no recibir nada es no enterarse de que el recálculo murió.
             */
            $detalle = $this->motivo_para_darla_por_perdida($run);

            if (!is_null($detalle)) {
                Log::error('FinalizeSetFinalPrices: se paso del tope de reloj, se cierra en error', [
                    'price_update_run_id' => $run->id,
                    'chunks_encolados'    => $run->chunks_encolados,
                    'processed_chunks'    => $run->processed_chunks,
                    'total_chunks'        => $run->total_chunks,
                ]);

                SetFinalPricesNotificationHelper::notify_prices_update_failed(
                    $this->user_id,
                    $run->id,
                    $detalle
                );

                return;
            }

            Log::info('FinalizeSetFinalPrices: todavia faltan chunks, re-dispatch', [
                'price_update_run_id' => $run->id,
                'chunks_encolados'    => $run->chunks_encolados,
                'processed_chunks'    => $run->processed_chunks,
                'total_chunks'        => $run->total_chunks,
            ]);

            /*
             * El delay son 10 segundos, pero el worker de este proyecto corre con
             * --stop-when-empty una vez por minuto (app/Console/Kernel.php), así que un job
             * demorado no lo mantiene vivo: en la práctica reintenta una vez por minuto. Los
             * topes de arriba están puestos en esa escala, no en la de los 10 segundos.
             */
            self::dispatch($this->user_id, $this->price_update_run_id)
                ->delay(now()->addSeconds(10))
                ->onConnection($this->connection)
                ->onQueue($this->queue);

            return;
        }

        $articles_updated = (int) DB::table('price_update_run_articles')
            ->where('price_update_run_id', $run->id)
            ->count();

        /*
         * Sólo proveedores: las categorías salieron del modal por decisión de Lucas
         * (11/8/2026), y calcular un desglose que nadie mira es un GROUP BY sobre decenas de
         * miles de filas para tirarlo.
         */
        $stats = [
            'proveedores' => $this->agrupar_proveedores($run->id),
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
     * Agrupa por proveedor los artículos de la corrida que cambiaron de precio.
     *
     * Se hace por SQL y no en PHP porque la lista puede tener decenas de miles de filas:
     * traerlas para contarlas en memoria es lo que después no escala.
     *
     * Los artículos sin proveedor NO se descartan: se agrupan bajo "Sin proveedor".
     * Descartarlos haría que el modal diga "de 3 proveedores" cuando en realidad hubo un
     * cuarto grupo, y el usuario lo lee como un error.
     *
     * La cantidad por proveedor ya no se muestra en el modal, pero se sigue guardando: es lo
     * que hace que stats_json siga sirviendo para entender una corrida vieja sin tener que
     * rehacer el SQL contra un catálogo que mientras tanto cambió.
     *
     * @param  int $run_id
     * @return array
     */
    protected function agrupar_proveedores($run_id)
    {
        $filas = DB::table('price_update_run_articles')
            ->join('articles', 'articles.id', '=', 'price_update_run_articles.article_id')
            ->leftJoin('providers', 'providers.id', '=', DB::raw('articles.provider_id'))
            ->where('price_update_run_articles.price_update_run_id', $run_id)
            ->select(
                DB::raw('articles.provider_id as relacion_id'),
                DB::raw('providers.name as nombre'),
                DB::raw('COUNT(*) as cantidad')
            )
            ->groupBy(DB::raw('articles.provider_id'), DB::raw('providers.name'))
            ->orderBy('cantidad', 'DESC')
            ->get();

        $resultado = [];

        foreach ($filas as $fila) {
            $nombre = $fila->nombre;

            if (is_null($nombre) || $nombre === '') {
                $nombre = 'Sin proveedor';
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
     * Por qué esta corrida ya no va a poder cerrarse bien, o null si todavía hay que esperar.
     *
     * Son dos topes y no uno porque son dos muertes distintas:
     *
     *  - El productor nunca terminó de encolar (`chunks_encolados` en 0). El bucle que
     *    despacha los chunks no dura más que segundos —el worker mismo lo mata a los 60— así
     *    que media hora sin el flag significa que ese job se murió. Esperar dos horas para
     *    avisar de algo que ya se sabe es dejar al usuario mirando la nada.
     *  - Los chunks se encolaron pero alguno no volvió nunca. Ahí sí hay trabajo real en
     *    curso y el tope tiene que ser holgado.
     *
     * @param  \App\Models\PriceUpdateRun $run
     * @return string|null
     */
    protected function motivo_para_darla_por_perdida($run)
    {
        if (is_null($run->started_at)) {
            return null;
        }

        $started_at = Carbon::parse($run->started_at);

        if (!$run->chunks_encolados) {
            if (!$started_at->copy()->addMinutes(self::TOPE_MINUTOS_SIN_ENCOLAR)->isPast()) {
                return null;
            }

            return 'El proceso que tenía que preparar el recálculo de precios se interrumpió y'
                . ' nunca llegó a repartir el trabajo, así que la actualización quedó sin hacer.'
                . ' Volvé a intentarlo.';
        }

        if (!$started_at->copy()->addHours(self::TOPE_HORAS)->isPast()) {
            return null;
        }

        return 'El recálculo de precios no terminó después de ' . self::TOPE_HORAS
            . ' horas y se cerró como incompleto. Se procesaron '
            . (int) $run->processed_chunks . ' de ' . (int) $run->total_chunks . ' lotes.';
    }

    /**
     * Si el finalizador muere de forma definitiva, la corrida no puede quedar en_proceso
     * para siempre y, sobre todo, el usuario no puede quedarse esperando un modal que ya no
     * va a llegar: éste es el último eslabón del aviso, así que si se muere en silencio el
     * recálculo entero se muere en silencio.
     *
     * @param  \Throwable $e
     * @return void
     */
    public function failed($e)
    {
        Log::error('FinalizeSetFinalPrices fallo: ' . $e->getMessage());

        SetFinalPricesNotificationHelper::notify_prices_update_failed(
            $this->user_id,
            $this->price_update_run_id,
            'No se pudo cerrar el recálculo de precios: ' . $e->getMessage()
        );
    }
}
