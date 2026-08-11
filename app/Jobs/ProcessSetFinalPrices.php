<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\PriceUpdateRunHelper;
use App\Http\Controllers\Helpers\SetFinalPricesNotificationHelper;
use App\Models\Article;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Jobs\ProcessChunkSetFinalPrices;
use App\Jobs\FinalizeSetFinalPrices;

class ProcessSetFinalPrices implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */

    public $user_id, $from_model_id, $model_id, $from_dolar, $origen, $origen_detalle;

    /**
     * $origen y $origen_detalle van AL FINAL de la firma y con default a propósito: así los
     * llamados que ya existen siguen andando sin tocarlos, y los que quieran contar por qué
     * se recalcularon los precios lo agregan de a uno.
     */
    public function __construct($user_id, $from_model_id = null, $model_id = null, $from_dolar = false, $origen = 'otro', $origen_detalle = null)
    {

        $this->user_id = $user_id;
        $this->from_model_id = $from_model_id;
        $this->model_id = $model_id;
        $this->from_dolar = $from_dolar;
        $this->origen = $origen;
        $this->origen_detalle = $origen_detalle;

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

            $run = PriceUpdateRunHelper::abrir_o_reusar($this->user_id, $this->origen, $this->origen_detalle);

            /** Chunks despachados en esta pasada; se suman a los que la corrida ya tenía. */
            $chunks_de_esta_pasada = 0;

            $articles_query->chunk(100, function ($articles_chunk) use ($run, &$chunks_de_esta_pasada) {
                $ids = $articles_chunk->pluck('id')->toArray();
                dispatch(new ProcessChunkSetFinalPrices($ids, $this->user_id, $run->id));
                $chunks_de_esta_pasada++;
            });

            if ($chunks_de_esta_pasada > 0) {
                DB::table('price_update_runs')
                    ->where('id', $run->id)
                    ->update(['total_chunks' => DB::raw('total_chunks + ' . (int) $chunks_de_esta_pasada)]);
            } else if ((int) $run->total_chunks === 0) {
                /*
                 * No hay un solo artículo que recalcular. Se cierra acá y se notifica igual:
                 * un recálculo que no encontró nada es información, no silencio (decisión de
                 * Lucas). Sin esto la corrida quedaría abierta para siempre y bloquearía los
                 * avisos siguientes de este usuario.
                 */
                PriceUpdateRunHelper::cerrar_sin_articulos($run);
                SetFinalPricesNotificationHelper::notify_prices_updated($this->user_id, $run);
                return;
            }

            /*
             * Recién acá se declara que ya no se despachan más chunks. El finalizador exige
             * este flag ADEMAS del conteo: sin él cerraría la corrida apenas los primeros
             * chunks terminen, mientras este mismo bucle todavía está despachando el resto.
             */
            DB::table('price_update_runs')
                ->where('id', $run->id)
                ->update(['chunks_encolados' => 1]);

            /*
             * 🔴 Acá ya NO se notifica. El aviso "Precios actualizados" se mandaba en este
             * mismo punto, o sea cuando el proceso RECIEN ARRANCABA: el usuario lo leía como
             * "listo" con el catálogo todavía sin recalcular. Ahora notifica el finalizador,
             * cuando los números son ciertos. Consecuencia aceptada: el aviso llega más
             * tarde que antes, minutos en un catálogo grande.
             */
            dispatch(new FinalizeSetFinalPrices($this->user_id, $run->id));

        } catch (\Exception $e) {
            Log::error("Error en ProcessSetFinalPrices: " . $e->getMessage());
            SetFinalPricesNotificationHelper::notify_prices_update_failed($this->user_id);
        }
    }
}
