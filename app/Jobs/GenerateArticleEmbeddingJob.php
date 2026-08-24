<?php

namespace App\Jobs;

use App\Models\Article;
use App\Services\ArticleEmbeddingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job que genera y persiste el embedding vectorial de un artículo.
 *
 * Recibe solo el ID del artículo (no la instancia Eloquent) para evitar
 * serializar el modelo completo con todas sus relaciones en la cola,
 * siguiendo el mismo patrón de ProcessSyncArticleToTiendaNube.
 *
 * El job carga las relaciones necesarias para armar el texto representativo
 * (category, brand, descriptions) y delega la generación y persistencia
 * al ArticleEmbeddingService.
 *
 * Antes de salir a la red compara la huella (sha1) del texto a vectorizar contra la
 * que quedó guardada en articles.embedding_source_hash la última vez. Si coinciden, el
 * vector que devolvería OpenAI sería idéntico al que ya está en la base, así que el job
 * corta ahí y solo refresca la marca de tiempo. Es lo que evita que una importación que
 * únicamente movió precios o stock termine pagando una llamada por artículo.
 *
 * Si el job pertenece a una tanda (`embedding_run_id`), además suma su resultado a los
 * contadores de `embedding_runs`: cada camino de salida cae en exactamente uno de
 * generados / salteados / fallados. Eso es lo que después le permite a FinalizeEmbeddingRun
 * saber cuántos vectores se escribieron DE VERDAD, que no es lo mismo que cuántos jobs se
 * despacharon.
 */
class GenerateArticleEmbeddingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Número de reintentos automáticos en caso de fallo.
     * Útil si la API de OpenAI responde con error transitorio (429, 5xx).
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Segundos a esperar entre reintentos (backoff lineal).
     *
     * @var int
     */
    public $backoff = 60;

    /**
     * ID del artículo a embeddear.
     * Se guarda el ID para evitar serialización del modelo completo.
     *
     * @var int
     */
    protected $article_id;

    /**
     * ID de la tanda (embedding_runs) a la que este job le tiene que rendir cuentas, o null
     * si el job no pertenece a ninguna.
     *
     * @var int|null
     */
    protected $embedding_run_id;

    /**
     * El segundo parámetro es nullable con default A PROPÓSITO, no por comodidad de firma:
     * ArticleObserver y DescriptionObserver siguen despachando este job con un solo argumento
     * y NO se tocan. Un artículo que alguien edita a mano vectoriza al instante y no genera
     * ningún toast, porque el aviso al comerciante es por LOTE — avisarle "se actualizó 1
     * artículo" justo después de que él mismo lo guardó no le informa nada y le tapa la
     * pantalla. Solo las tandas del scheduler y las post-importación llevan run_id.
     *
     * @param int      $article_id       ID del artículo cuyo embedding debe generarse.
     * @param int|null $embedding_run_id ID de la tanda a la que reportar, o null si no hay tanda.
     */
    public function __construct(int $article_id, ?int $embedding_run_id = null)
    {
        $this->article_id       = $article_id;
        $this->embedding_run_id = $embedding_run_id;
    }

    /**
     * Ejecuta el job: carga el artículo, genera su embedding y lo persiste.
     *
     * Si el artículo no existe (fue eliminado antes de que el job se procesara)
     * retorna silenciosamente sin lanzar excepción ni marcar como fallido.
     *
     * @param ArticleEmbeddingService $service Inyectado por el container de Laravel.
     *
     * @return void
     */
    public function handle(ArticleEmbeddingService $service): void
    {
        // Cargar el artículo con las relaciones necesarias para armar el texto.
        // Si fue eliminado (soft delete o delete real), retornar silenciosamente.
        $article = Article::with(['category', 'brand', 'descriptions'])
            ->find($this->article_id);

        if (! $article) {
            Log::channel('daily')->info('GenerateArticleEmbeddingJob: artículo no encontrado, se omite.', [
                'article_id' => $this->article_id,
            ]);

            // Salteado y no fallado: el artículo se borró entre el despacho y la ejecución.
            // No hay nada roto que arreglar, pero la tanda tiene que contarlo igual o se
            // queda esperando para siempre un job que ya terminó.
            $this->registrar_resultado('salteados');

            return;
        }

        try {
            // El texto que efectivamente se vectoriza y su huella. Se calculan ACÁ, antes
            // de cualquier llamada a OpenAI, porque son lo único que permite saber si el
            // artículo cambió en algo que al embedding le mueva la aguja.
            $texto = $service->embedding_for_article($article);
            $hash  = sha1($texto);

            if ($texto !== ''
                && ! is_null($article->embedding)
                && (string) $article->embedding_source_hash === $hash) {
                // El texto relevante no cambió. Caso típico: una importación que solo movió
                // final_price o stock, y el comando agendado lo reencola igual porque su
                // filtro mira updated_at > embedding_generated_at.
                //
                // Se refresca solo la marca de tiempo, para que el scheduler deje de
                // re-encolarlo, y se corta acá SIN gastar la llamada paga a OpenAI: el
                // vector que devolvería es exactamente el que ya está guardado. El filtro
                // SQL del comando queda como está (es barato); el corte real es este.
                DB::table('articles')
                    ->where('id', $article->id)
                    ->update(['embedding_generated_at' => now()]);

                Log::channel('daily')->info('GenerateArticleEmbeddingJob: el texto del artículo no cambió, se omite la llamada a OpenAI.', [
                    'article_id' => $this->article_id,
                ]);

                // Salteado: el job terminó bien, pero no escribió ningún vector nuevo. Para el
                // comerciante un artículo que ya estaba vectorizado no es una novedad, así que
                // este caso NO puede sumar a `generados`. Es, textualmente, la diferencia entre
                // "jobs despachados" y "embeddings generados" que motivó toda la tabla.
                $this->registrar_resultado('salteados');

                return;
            }

            // Generar y persistir el embedding via el servicio. Devuelve false cuando el texto
            // representativo quedó vacío (artículo sin nombre, sin marca, sin categoría y sin
            // descripciones): ahí el servicio sale por su return mudo sin escribir vector, y
            // contar eso como "generado" sería inflar el número del toast con artículos que
            // siguen sin aparecer en la búsqueda.
            //
            // La escritura de embedding_generated_at / embedding_source_hash de abajo se hace
            // igual en los dos casos, como venía haciéndose: es la conducta previa y no es lo
            // que esta misión vino a cambiar. El bool decide UNICAMENTE en qué contador cae.
            $genero_vector = $service->update_article_embedding($article);

            // Registrar cuándo se generó el embedding para que el scheduler detecte
            // si el artículo se modifica después de esta fecha (updated_at > embedding_generated_at),
            // y la huella del texto usado, que es lo que permite cortar la próxima vez si
            // el artículo se toca pero el texto queda igual.
            DB::table('articles')
                ->where('id', $article->id)
                ->update([
                    'embedding_generated_at' => now(),
                    'embedding_source_hash'  => $hash,
                ]);

            if ($genero_vector) {
                Log::channel('daily')->info('GenerateArticleEmbeddingJob: embedding generado correctamente.', [
                    'article_id'   => $this->article_id,
                    'article_name' => $article->name,
                ]);

                $this->registrar_resultado('generados');
            } else {
                Log::channel('daily')->info('GenerateArticleEmbeddingJob: el artículo no tiene texto que vectorizar, se omite.', [
                    'article_id'   => $this->article_id,
                    'article_name' => $article->name,
                ]);

                $this->registrar_resultado('salteados');
            }
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('GenerateArticleEmbeddingJob: error al generar embedding.', [
                'article_id' => $this->article_id,
                'error'      => $exception->getMessage(),
            ]);

            // Re-lanzar para que el sistema de colas registre el fallo y reintente.
            // Ojo: acá NO se cuenta nada. Mientras al job le queden reintentos sigue vivo, y
            // sumarle un resultado a la tanda en cada intento la cerraría de más (tres intentos
            // de un solo artículo contarían como tres jobs terminados). El conteo del fracaso
            // definitivo lo hace failed(), una sola vez.
            throw $exception;
        }
    }

    /**
     * Suma uno al contador que corresponda de la tanda, si este job pertenece a alguna.
     *
     * Un solo UPDATE con expresiones crudas (`campo + 1`) y no leer-sumar-guardar: con varios
     * workers en paralelo el read-modify-write pierde incrementos en silencio, y perder uno
     * significa que la tanda nunca llega a `terminados == total_jobs` y termina cerrándose por
     * vencimiento veinte minutos después.
     *
     * `terminados` se incrementa siempre junto con el contador específico, en el mismo UPDATE,
     * para que nunca exista un instante en que uno esté sumado y el otro no.
     *
     * El nombre del campo se concatena en el SQL, así que no puede venir de afuera: los tres
     * únicos llamadores son este archivo y le pasan literales.
     *
     * @param string $campo 'generados' | 'salteados' | 'fallados'.
     *
     * @return void
     */
    private function registrar_resultado(string $campo): void
    {
        // Los jobs de ArticleObserver y DescriptionObserver llegan sin tanda: no hay a quién
        // rendirle cuentas y se sale sin tocar la base.
        if (is_null($this->embedding_run_id)) {
            return;
        }

        DB::table('embedding_runs')
            ->where('id', $this->embedding_run_id)
            ->update([
                $campo       => DB::raw($campo.' + 1'),
                'terminados' => DB::raw('terminados + 1'),
                'updated_at' => now(),
            ]);
    }

    /**
     * Cuenta el job como fallado en la tanda cuando muere en forma definitiva.
     *
     * 🔴 failed() CORRE UNA SOLA VEZ, AL AGOTAR LOS 3 `tries` — no en cada reintento. Eso es lo
     * correcto: mientras el job siga reintentando todavía puede terminar generando el vector, y
     * la tanda tiene que seguir esperándolo en vez de darlo por perdido.
     *
     * Pero tiene una consecuencia que hay que tener presente antes de tocar los tiempos de
     * FinalizeEmbeddingRun: con `backoff = 60`, un artículo que falla las tres veces tarda unos
     * DOS MINUTOS en llegar hasta acá, y durante esos dos minutos la tanda entera queda abierta
     * esperándolo. Sumado a que el worker se levanta por minuto desde el scheduler, esa demora
     * se estira más todavía. Es parte de por qué la ventana de vencimiento del finalizador es de
     * veinte minutos y no de dos.
     *
     * @param \Throwable $exception
     *
     * @return void
     */
    public function failed($exception)
    {
        Log::channel('daily')->error('GenerateArticleEmbeddingJob: el job murió definitivamente.', [
            'article_id'       => $this->article_id,
            'embedding_run_id' => $this->embedding_run_id,
            'error'            => $exception->getMessage(),
        ]);

        $this->registrar_resultado('fallados');
    }
}
