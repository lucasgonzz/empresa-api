<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Http\Controllers\Helpers\SetFinalPricesNotificationHelper;
use App\Http\Controllers\Helpers\article\precios\RecalculoDePreciosEnLote;
use App\Models\PriceUpdateRun;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Un lote del recálculo de precios: recalcula sus artículos con el motor en lote y suma el lote a
 * la corrida.
 *
 * Misión recalculo-precios-motor-rapido (28/9/2026): el handle() recorría los artículos llamando
 * a ArticleHelper::setFinalPrice() uno por uno, con todas sus consultas y escrituras por artículo
 * (21 a 50 segundos por lote de 100 en Servian). Ahora delega en RecalculoDePreciosEnLote, que
 * deja la base exactamente igual —lo prueba RecalculoEnLote/1_Equivalencia_...— leyendo y
 * escribiendo en bloque.
 */
class ProcessChunkSetFinalPrices implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Segundos por intento. Una tanda de 1.000 artículos tarda segundos; 600 es el techo para un
     * cliente con Tienda Nube o Mercado Libre prendidos (esa marca de sincronización todavía es
     * por artículo) sin que un lote colgado retenga el worker toda la noche. Va en el job y no
     * solo en el worker: el worker de la cola 'excel' del shared corre con el --timeout por defecto.
     *
     * @var int
     */
    public $timeout = 600;

    /**
     * Tres intentos, y es seguro reintentar: cada tanda del motor es atómica (o se escribe entera
     * o no se escribe nada), y recalcular de nuevo lo que ya quedó bien no cambia nada. Un corte
     * de conexión o un deadlock contra otro recálculo del mismo catálogo ya no mata la corrida.
     * failed() —el aviso de error— corre recién cuando se agotan los tres.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Segundos entre intentos.
     *
     * @var int
     */
    public $backoff = 10;

    /*
     * 🔴 Las tres propiedades de siempre, con los mismos nombres y la misma visibilidad: hay jobs
     * ya encolados con la firma vieja (lotes de 100), y al deserializarlos se hidratan por nombre.
     */
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

        /*
         * En el shared hosting va a la cola 'excel' (la de los jobs pesados: el worker corre con
         * --memory=512 y --max-time=1200), separada del asistente de IA y de las notificaciones
         * que viven en 'default'. En el VPS, null: sigue en 'default', la única cola que el
         * supervisor de cada cliente consume. Mismo patrón que ProcessArticleChunk.
         *
         * 🔴 En el constructor (propiedad pública $queue del trait Queueable), no con viaQueue():
         * ese hook es solo para listeners de eventos y en un job no se invoca nunca.
         */
        $this->queue = config('app.VPS') ? null : 'excel';
    }

    public function handle()
    {
        Log::info('Procesando chunck');

        $user = User::find($this->user_id);

        if (is_null($user)) {
            throw new \RuntimeException('ProcessChunkSetFinalPrices: no existe el usuario ' . $this->user_id . ' dueño del recálculo.');
        }

        /*
         * auth_user_id en null, como antes: el employee_id de los price_changes lo resuelve el
         * motor con UserHelper::userId(false) (en el worker, config('app.USER_ID')). Los artículos
         * que cambiaron de precio se registran en price_update_run_articles adentro de la misma
         * transacción que sus precios: si el commit no llega, no quedan contados.
         */
        RecalculoDePreciosEnLote::recalcular($this->article_ids, $user, null, [
            'price_update_run_id' => $this->price_update_run_id,
        ]);

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
     * Con $tries = 3 esto corre después del tercer intento fallido, no del primero.
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

        $this->avanzar_el_registro_visible();
    }

    /**
     * Suma este lote en el registro que ve el usuario (misión procesos-en-segundo-plano).
     *
     * incrementar() y no avanzar(): los chunks de una corrida corren en paralelo en varios
     * workers, y dos que terminan a la vez no pueden leer-y-escribir el contador sin pisarse.
     * El total del registro lo fija el productor cuando termina de encolar; este lote no lo
     * toca. La etapa se arma con los contadores FRESCOS de la corrida, leídos después del
     * incremento atómico de arriba: total_chunks puede seguir en 0 si el productor todavía
     * está encolando, y ahí "Lote 3 de 0" sería mentira, así que se deja "Recalculando".
     *
     * @return void
     */
    protected function avanzar_el_registro_visible()
    {
        try {
            $run = PriceUpdateRun::find($this->price_update_run_id);

            if (is_null($run)) {
                return;
            }

            $etapa = (int) $run->total_chunks > 0
                ? 'Lote ' . min((int) $run->processed_chunks, (int) $run->total_chunks) . ' de ' . (int) $run->total_chunks
                : 'Recalculando';

            BackgroundProcessHelper::incrementar(BackgroundProcessHelper::por_referencia($run), 1, [
                'etapa' => $etapa,
            ]);
        } catch (\Throwable $e) {
            // Misma regla que el helper: el registro visible nunca voltea al lote. El chunk ya
            // quedó contado en price_update_runs; lo único que se pierde es un cuadro de la barra.
            Log::warning('ProcessChunkSetFinalPrices: no se pudo avanzar el registro visible', [
                'price_update_run_id' => $this->price_update_run_id,
                'error'               => $e->getMessage(),
            ]);
        }
    }
}
