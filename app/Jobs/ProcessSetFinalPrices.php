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

        /*
         * Fuera del try a propósito: si la excepción salta después de abrir la corrida, el
         * catch tiene que poder cerrarla y decir por qué. Antes se notificaba el error sin
         * el id, así que la corrida quedaba en_proceso y el usuario recibía un aviso que no
         * apuntaba a nada.
         */
        $run = null;

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

            /*
             * Su propia corrida, siempre. Ver PriceUpdateRunHelper::abrir(): reusar la
             * corrida abierta del usuario hacía que dos productores compartieran contador y
             * flag, y el que terminaba primero cerraba por el otro con números parciales.
             */
            $run = PriceUpdateRunHelper::abrir($this->user_id, $this->origen, $this->origen_detalle);

            /*
             * 🔴 Un finalizador ACA, antes del bucle, además del de siempre que va al final.
             *
             * Es la única red que le queda a la corrida si este job se muere en el medio del
             * ->chunk(): ahí no hay catch que valga —el worker mata el proceso— y failed()
             * tampoco sirve, porque Laravel lo llama sobre una instancia deserializada del
             * payload original y el id de la corrida que se abrió recién no existe en ella.
             * Encolado desde el principio, el tope de reloj cierra la corrida y avisa igual.
             *
             * No cierra de más: el finalizador exige chunks_encolados, que este bucle pone
             * recién al final, así que mientras el productor viva sólo se re-despacha.
             */
            dispatch(new FinalizeSetFinalPrices($this->user_id, $run->id));

            /** Chunks despachados por este job, que es el único productor de esta corrida. */
            $chunks_despachados = 0;

            $articles_query->chunk(100, function ($articles_chunk) use ($run, &$chunks_despachados) {
                $ids = $articles_chunk->pluck('id')->toArray();
                dispatch(new ProcessChunkSetFinalPrices($ids, $this->user_id, $run->id));
                $chunks_despachados++;
            });

            if ($chunks_despachados > 0) {
                DB::table('price_update_runs')
                    ->where('id', $run->id)
                    ->update(['total_chunks' => (int) $chunks_despachados]);
            } else {
                /*
                 * No hay un solo artículo que recalcular. Se cierra acá y se notifica igual:
                 * un recálculo que no encontró nada es información, no silencio (decisión de
                 * Lucas). Sin esto la corrida quedaría abierta para siempre.
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
             *
             * Este segundo finalizador es el que cierra en el camino normal, y con una cola
             * que ejecuta inline es el único que puede: el de arriba corrió cuando todavía no
             * había un solo chunk procesado.
             */
            dispatch(new FinalizeSetFinalPrices($this->user_id, $run->id));

        } catch (\Throwable $e) {
            /*
             * \Throwable y no \Exception: en PHP 7 un TypeError o cualquier otro \Error no es
             * una Exception, así que con el catch anterior se escapaba sin avisarle a nadie.
             */
            Log::error("Error en ProcessSetFinalPrices: " . $e->getMessage());

            SetFinalPricesNotificationHelper::notify_prices_update_failed(
                $this->user_id,
                is_null($run) ? null : $run->id,
                'No se pudo iniciar el recálculo de precios: ' . $e->getMessage()
            );
        }
    }

    /*
     * ⚠️ Este job NO tiene failed(), y no es un olvido.
     *
     * Laravel llama a failed() sobre una instancia deserializada del payload original, así
     * que nada de lo que handle() haya escrito en el objeto —incluido el id de la corrida que
     * abrió— existe ahí. Buscar "la corrida abierta de este usuario" sería adivinar y podría
     * cerrar la de otro productor que está sano.
     *
     * Y notificar sin poder cerrar la corrida tampoco sirve: el finalizador que se encola
     * antes del bucle va a avisar igual cuando salte su tope de reloj, así que lo único que
     * se lograría es mandarle al usuario dos avisos de error con textos distintos por la
     * misma falla. El aviso lo da el finalizador, que sí sabe de qué corrida está hablando.
     */
}
