<?php

namespace App\Jobs;

use App\Events\ArticleEmbeddingsBatchGenerated;
use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Models\EmbeddingRun;
use App\Services\ArticleEmbeddingBatchProcessor;
use App\Services\ColaDeEmbeddings;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Cierra una tanda de generación de embeddings y, si algo se generó de verdad, emite el
 * aviso al comerciante.
 *
 * Existe porque el comando `articles:generate-embeddings` no puede saber cómo terminó lo que
 * despachó: el comando corre en el proceso del scheduler y los jobs corren en el worker. Lo
 * único que el comando sabe es cuántos jobs puso en la cola, y ese número no es el que hay
 * que informar — el corte por `embedding_source_hash` hace que un job termine bien sin
 * escribir ningún vector.
 *
 * Entonces este job se despacha detrás de la tanda y se re-despacha a sí mismo hasta que los
 * contadores de `embedding_runs` cierran, igual que hace FinalizeArticleImport con los chunks
 * de una importación.
 *
 * Minutos que puede tardar la ventana de vencimiento; ver la constante de abajo.
 */
class FinalizeEmbeddingRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Reintentos y espera, calcados de FinalizeArticleImport: este job se re-despacha solo
     * mientras la tanda no cierre, así que necesita margen para no morir en el camino.
     *
     * @var int
     */
    public $tries = 120;

    /**
     * @var int
     */
    public $backoff = 10;

    /**
     * Minutos después de los cuales una tanda que nunca cerró se da por vencida y se notifica
     * con lo que haya.
     *
     * 🔴 SON VEINTE Y NO DOS, Y NO ES UN NÚMERO AL AZAR. Hay tres modos de falla distintos que
     * dejan una tanda abierta, y manda el más lento de los tres:
     *
     * 1. `GenerateArticleEmbeddingJob::failed()` corre recién al agotar los 3 `tries`, y con
     *    `backoff = 60` eso son unos dos minutos por artículo que falla.
     * 2. El worker puede morir a mitad de tanda (OOM, reinicio); sus jobs no vuelven a correr
     *    hasta que el `queue:work` del minuto siguiente los recupere.
     * 3. `queue:work --stop-when-empty` (Kernel.php) termina cuando la cola queda sin jobs
     *    DISPONIBLES. Una tanda cuyos jobs quedaron `delayed` deja la cola aparentemente vacía,
     *    así que el worker se va y hay que esperar al del minuto siguiente.
     *
     * Veinte minutos entra cómodo debajo del ciclo de treinta del scheduler: en el peor caso
     * una tanda notifica de menos y la corrida siguiente levanta lo que quedó pendiente,
     * porque los artículos sin vector siguen matcheando el filtro `embedding IS NULL`.
     */
    const MINUTOS_PARA_VENCER = 20;

    /**
     * Segundos de plazo que suma cada lote de `ArticleEmbeddingBatchProcessor::TAMANO_LOTE` artículos de
     * la tanda al plazo de vencimiento (misión embeddings-lote-cola).
     *
     * 🔴 Con los 20 minutos fijos una tanda de 22.700 artículos "vencía" aunque siguiera avanzando
     * (con un solo worker, 227 lotes tardan bastante más). Ahora el plazo es el MAYOR entre
     * MINUTOS_PARA_VENCER y `ceil(total_jobs / 100) × 30 s`: 22.700 artículos → 114 minutos, y una tanda
     * chica sigue venciendo a los 20.
     */
    const SEGUNDOS_POR_LOTE = 30;

    /**
     * Segundos de espera cuando la tanda todavía no cerró.
     *
     * 🔴 En la práctica esto NO son quince segundos, son hasta un minuto largo, y conviene
     * saberlo antes de salir a buscar por qué el toast tardó cuarenta segundos en aparecer.
     * `Kernel.php` agenda `queue:work --stop-when-empty` cada minuto: al re-despacharse con
     * delay, este job queda `delayed`, la cola se queda sin jobs disponibles, el worker actual
     * termina, y a este job lo levanta el worker del minuto siguiente. Bajar este número no
     * acelera nada: el piso lo pone el scheduler, no el delay.
     *
     * @var int
     */
    const SEGUNDOS_DE_ESPERA = 15;

    /**
     * @var int
     */
    protected $embedding_run_id;

    /**
     * @param int $embedding_run_id Tanda a cerrar.
     */
    public function __construct(int $embedding_run_id)
    {
        // Cola propia de embeddings (misión embeddings-lote-cola): todo lo de embeddings, el finalizador
        // incluido, deja de pasar por `default`. Null (= `default`) si el interruptor está apagado.
        $this->queue = ColaDeEmbeddings::cola_para_despachar();

        $this->embedding_run_id = $embedding_run_id;
    }

    /**
     * Cierra la tanda si ya terminaron todos sus jobs; si no, se vuelve a despachar.
     *
     * @return void
     */
    public function handle(): void
    {
        $run = EmbeddingRun::find($this->embedding_run_id);

        if (is_null($run)) {
            return;
        }

        /*
         * 🔴 LA IDEMPOTENCIA VA POR `notificado_at`, NO POR EL STATUS. No lo cambies por un
         * chequeo de status por más que parezca lo mismo y más barato.
         *
         * Es exactamente el error que FinalizeArticleImport ya tiene documentado en rojo con el
         * evento de la demo: ahí se condicionó la emisión a que el import no estuviera ya en
         * 'terminado', y como OTRO código escribía ese status antes de que este job existiera,
         * la condición era falsa siempre y el evento no se emitía NUNCA.
         *
         * Acá pasa lo mismo: el status de una tanda lo escribe también la guarda
         * anti-solapamiento de GenerateArticleEmbeddings, que marca 'vencida' a las tandas
         * viejas que encuentra abiertas. O sea que el status no es una marca confiable de "ya
         * se notificó". `notificado_at` lo escribe un solo lugar: estas líneas.
         */
        if (!is_null($run->notificado_at)) {
            return;
        }

        /*
         * La tanda todavía se está despachando: el comando sigue metiendo jobs en la cola y
         * `total_jobs` no es definitivo.
         *
         * 🔴 Sin este corte, el caso se rompe solo. Con QUEUE_CONNECTION=database el worker
         * levanta los jobs del primer chunk mientras el comando todavía despacha el segundo, así
         * que este finalizador puede llegar acá con la tanda recién creada, `total_jobs = 0` y
         * `terminados = 0`. La comparación de más abajo daría `0 >= 0` y cerraría una tanda a la
         * que no se le despachó todavía ni un job.
         */
        if ($run->status === 'despachando') {
            /*
             * Pero el corte por 'despachando' TAMBIÉN vence, y por eso el chequeo está duplicado
             * acá adentro en vez de vivir solo más abajo.
             *
             * El comando crea la tanda en 'despachando' y la pasa a 'en_proceso' recién cuando
             * terminó de despachar. Si muere en el medio —un error de base a mitad de un
             * `chunkById`, el proceso del scheduler matado— la tanda se queda en 'despachando'
             * para siempre. Sin esta salida, este job se re-despacharía cada quince segundos
             * hasta agotar los 120 `tries`: media hora de intentos que no pueden terminar en
             * nada, porque no hay nadie que vaya a mover ese status.
             */
            if (Carbon::now()->greaterThanOrEqualTo(self::vence_at($run))) {
                Log::channel('daily')->warning('FinalizeEmbeddingRun: la tanda quedó trabada en despachando y venció.', [
                    'embedding_run_id' => $run->id,
                ]);

                $this->cerrar($run, 'vencida');

                return;
            }

            $this->volver_a_intentar();

            return;
        }

        if ((int) $run->terminados < (int) $run->total_jobs) {
            if (Carbon::now()->lessThan(self::vence_at($run))) {
                $this->volver_a_intentar();

                return;
            }

            /*
             * Vencida: se cierra igual y se notifica con lo que haya. Quedarse esperando para
             * siempre a un job que ya no va a llegar dejaría al comerciante sin el aviso de los
             * artículos que SÍ se vectorizaron, que es la información que le sirve.
             */
            Log::channel('daily')->warning('FinalizeEmbeddingRun: la tanda venció con jobs sin terminar.', [
                'embedding_run_id' => $run->id,
                'terminados'       => $run->terminados,
                'total_jobs'       => $run->total_jobs,
            ]);

            $this->cerrar($run, 'vencida');

            return;
        }

        $this->cerrar($run, 'completada');
    }

    /**
     * Momento a partir del cual la tanda se da por abandonada.
     *
     * Está en un método para que las dos salidas por vencimiento —la tanda trabada en
     * 'despachando' y la que espera jobs que no llegan— usen exactamente el mismo reloj. Si se
     * calculara en dos lugares, alcanzaría con que alguien tocara uno para que las dos puntas
     * dejaran de coincidir, y el desacuerdo no daría ningún error: solo tandas que cierran a
     * destiempo.
     *
     * @param EmbeddingRun $run
     *
     * @return \Carbon\Carbon
     */
    public static function vence_at(EmbeddingRun $run)
    {
        // Una prueba puede encolar miles de artículos. Mientras la tanda avance, su
        // duración total no indica que esté trabada; los jobs actualizan el sello por resultado.
        if ($run->origen === 'prueba' && $run->status === 'en_proceso') {
            return Carbon::parse($run->updated_at)->addMinutes(60);
        }

        // Plazo base y plazo proporcional al tamaño de la tanda (30 s por cada lote de artículos).
        $segundos_base = self::MINUTOS_PARA_VENCER * 60;
        $segundos_por_tamano = (int) ceil(((int) $run->total_jobs) / ArticleEmbeddingBatchProcessor::TAMANO_LOTE) * self::SEGUNDOS_POR_LOTE;

        return Carbon::parse($run->created_at)->addSeconds(max($segundos_base, $segundos_por_tamano));
    }

    /**
     * Marca la tanda como cerrada y emite el aviso si hubo al menos un vector nuevo.
     *
     * @param EmbeddingRun $run
     * @param string       $status 'completada' | 'vencida'.
     *
     * @return void
     */
    protected function cerrar(EmbeddingRun $run, string $status): void
    {
        /*
         * `notificado_at` se escribe SIEMPRE, incluso cuando no se emite nada. Es el candado de
         * idempotencia, no un registro de que salió un broadcast: si solo se escribiera al
         * emitir, una tanda que no generó nada quedaría para siempre sin marca y cualquier
         * re-despacho tardío la volvería a procesar.
         */
        $run->status         = $status;
        $run->notificado_at  = Carbon::now();
        $run->save();

        // Cierra el proceso visible de la tanda (si lo tiene) ANTES del corte por origen `prueba`: el
        // comerciante ve el proceso terminado aunque el aviso no se emita. Devuelve si había proceso.
        $con_proceso = self::cerrar_proceso_visible($run, $status);

        /*
         * Cero generados y no se avisa nada. Es el corazón de todo esto: la corrida agendada
         * cada treinta minutos encuentra artículos "pendientes" por el filtro
         * `updated_at > embedding_generated_at`, les despacha jobs, y los jobs cortan por huella
         * sin gastar una llamada porque el texto no cambió. Notificar ahí sería abrirle un aviso
         * al comerciante cada media hora para decirle que no pasó nada.
         */
        if ((string) $run->origen === 'prueba') {
            return;
        }
        if ((int) $run->generados < 1) {
            Log::channel('daily')->info('FinalizeEmbeddingRun: la tanda cerró sin vectores nuevos, no se notifica.', [
                'embedding_run_id' => $run->id,
                'salteados'        => $run->salteados,
                'fallados'         => $run->fallados,
            ]);

            return;
        }

        // `con_proceso` le dice a la SPA que el aviso ya lo da la pantalla de procesos y no muestre el
        // toast (el evento se sigue emitiendo: el dashboard de WhatsApp lo usa para refrescar).
        broadcast(new ArticleEmbeddingsBatchGenerated(
            $run->user_id,
            (int) $run->generados,
            (string) $run->origen,
            $con_proceso
        ));
    }

    /**
     * Cierra el proceso de "Procesos en segundo plano" de la tanda, si lo tiene (misión
     * embeddings-lote-cola): lo crea el comando para las tandas de 100 artículos o más.
     *
     *  - Sin vectores nuevos y con fallados → `fallar` con un mensaje que tranquiliza (se reintenta solo).
     *  - Cualquier otro caso → `completar` con `{preparados, al_dia, con_error}` y, si quedaron
     *    artículos sin terminar (la tanda venció), además `pendientes` y la etapa que explica que se
     *    completan en la pasada siguiente. Vencer no es fallar: el proceso se completa con lo que haya.
     *
     * `completar` y `fallar` son idempotentes y nunca lanzan; encontrar el proceso tampoco. Es público y
     * estático porque también lo usa el comando `articles:generate-embeddings` al marcar vencida a una
     * tanda vieja (su proceso tiene que cerrarse igual).
     *
     * @param EmbeddingRun $run    Tanda ya guardada como cerrada.
     * @param string       $status 'completada' | 'vencida'.
     *
     * @return bool true si la tanda tenía un proceso visible (abierto o ya cerrado).
     */
    public static function cerrar_proceso_visible(EmbeddingRun $run, string $status): bool
    {
        // Se busca también el cerrado: `con_proceso` tiene que ser true aunque otro lo haya cerrado antes.
        $proceso = BackgroundProcessHelper::por_referencia($run, false);

        if (is_null($proceso)) {
            return false;
        }

        // Números finales de la tanda, con los nombres que lee el comerciante.
        $resultado = [
            'preparados' => (int) $run->generados,
            'al_dia'     => (int) $run->salteados,
            'con_error'  => (int) $run->fallados,
        ];

        // Artículos que nunca llegaron a terminar (sólo hay si la tanda venció).
        $pendientes = max(0, (int) $run->total_jobs - (int) $run->terminados);

        if ((int) $run->generados < 1 && (int) $run->fallados > 0) {
            BackgroundProcessHelper::fallar(
                $proceso,
                'No se pudo preparar la búsqueda inteligente; el sistema lo reintenta solo.',
                $resultado
            );

            return true;
        }

        if ($status === 'vencida' && $pendientes > 0) {
            $resultado['pendientes'] = $pendientes;

            BackgroundProcessHelper::completar($proceso, $resultado, 'Lo que faltó se completa en la próxima pasada');

            return true;
        }

        BackgroundProcessHelper::completar($proceso, $resultado);

        return true;
    }

    /**
     * Se vuelve a poner en la cola para revisar la tanda más tarde.
     *
     * Se despacha una instancia NUEVA en vez de usar `release()` a propósito: `release()`
     * consume un intento de los `tries`, y una tanda que espera veinte minutos con reintentos
     * cada quince segundos se comería el presupuesto y moriría antes de poder cerrar nada. Es
     * el mismo patrón que usa FinalizeArticleImport cuando le faltan chunks.
     *
     * La cola NO se copia de esta instancia: la instancia nueva la toma de ColaDeEmbeddings en su
     * constructor. Así un finalizador viejo serializado en `default` (queue null) se muda solo a la cola
     * propia en su primer re-despacho.
     *
     * @return void
     */
    protected function volver_a_intentar(): void
    {
        self::dispatch($this->embedding_run_id)
            ->delay(Carbon::now()->addSeconds(self::SEGUNDOS_DE_ESPERA))
            ->onConnection($this->connection);
    }
}
