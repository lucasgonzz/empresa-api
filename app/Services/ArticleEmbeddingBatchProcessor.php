<?php

namespace App\Services;

use App\Exceptions\CupoEmbeddingsDePruebaException;
use App\Exceptions\EmbeddingsCredencialRechazadaException;
use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Models\Article;
use App\Models\EmbeddingRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Procesador común de embeddings de artículos por LOTE (misión embeddings-lote-cola).
 *
 * Es la lógica que antes vivía dentro de `GenerateArticleEmbeddingJob::handle()` generalizada a una
 * lista de ids. La usan los dos jobs: `GenerateArticleEmbeddingsBatchJob` (el lote que arma el
 * comando, hasta TAMANO_LOTE artículos con UN solo pedido a OpenAI) y `GenerateArticleEmbeddingJob`
 * (un artículo, el que despachan los observers, que delega acá con una lista de uno). Así hay una
 * sola copia de cada garantía y los tests del job por artículo siguen siendo la red de regresión.
 *
 * Garantías que se conservan, una por una:
 *  1. Se libera la marca `embedding_queued_at` de TODOS los ids al empezar, antes de leer el texto.
 *  2. Artículo borrado → salteado. Texto sin cambios (huella igual y vector presente) → se sella
 *     `embedding_generated_at` y se saltea SIN llamar a OpenAI. Texto vacío → se sella y se saltea.
 *  3. El resto son candidatos: UNA llamada `generate_embeddings_batch()` por dueño (en la práctica uno).
 *  4. Cada artículo cae en exactamente uno de generados / salteados / fallados, y la tanda
 *     (`embedding_runs`) se actualiza con UN solo UPDATE al final. Si algo lanza antes, NO se cuenta
 *     nada: un reintento no cuenta dos veces.
 *  5. Credencial rechazada → fallados, sin re-lanzar y sin re-marcar (como hoy). Cupo de prueba
 *     agotado → salteados.
 *  6. Un 400/413/422 con más de un candidato (un texto inválido tira todo el pedido) se AÍSLA: cada
 *     candidato se reintenta solo con su propio pedido; el que vuelve a dar 4xx queda fallado (sin marca
 *     y sin huella: el comando lo reintenta en la pasada siguiente) y los demás se generan.
 *  7. Cualquier otro error (429 de rate limit, 5xx, timeout) se re-lanza: el lote entero se reintenta
 *     con la marca re-puesta si quedan intentos.
 */
class ArticleEmbeddingBatchProcessor
{
    /**
     * Artículos por lote (y por pedido a OpenAI).
     *
     * OpenAI acepta hasta 2048 entradas y ~300.000 tokens por pedido; el texto de un artículo está
     * acotado (descripciones 4000 caracteres, más nombre/marca/categoría/código: unos 2.000 tokens), así
     * que 100 × 2.000 = 200.000 entra con holgura. Con 100, 22.700 artículos son 227 pedidos en vez de
     * 22.700. Más grande casi no gana y agranda lo que se repite en un reintento.
     *
     * @var int
     */
    const TAMANO_LOTE = 100;

    /**
     * Etapa que ve el comerciante en "Procesos en segundo plano" mientras los lotes avanzan.
     *
     * @var string
     */
    const ETAPA_PREPARANDO = 'Preparando artículos para la búsqueda';

    /**
     * Códigos HTTP con los que OpenAI rechaza un pedido por su CONTENIDO (texto inválido o demasiado
     * largo) y no por un problema pasajero: reintentar el mismo pedido da lo mismo. Disparan el
     * aislamiento de candidatos.
     *
     * @var int[]
     */
    const CODIGOS_DE_TEXTO_INVALIDO = [400, 413, 422];

    /**
     * Servicio que arma el texto, llama a OpenAI y persiste el vector.
     *
     * @var ArticleEmbeddingService
     */
    protected $service;

    /**
     * @param ArticleEmbeddingService $service Servicio de embeddings (lo inyecta el contenedor).
     */
    public function __construct(ArticleEmbeddingService $service)
    {
        $this->service = $service;
    }

    /**
     * Procesa una lista de artículos: genera sus embeddings (con una sola llamada por dueño) y suma el
     * resultado a la tanda.
     *
     * @param int[]    $article_ids      Artículos a vectorizar (hasta TAMANO_LOTE).
     * @param int|null $embedding_run_id Tanda a la que rendirle cuentas, o null si no hay tanda.
     * @param int      $intento_actual   Número de intento del job (1 en el primero).
     * @param int      $intentos_maximos `tries` del job: en el último intento no se re-marca.
     *
     * @return void
     *
     * @throws \Throwable Si el error es transitorio: la cola reintenta el lote entero.
     */
    public function procesar(array $article_ids, ?int $embedding_run_id, int $intento_actual = 1, int $intentos_maximos = 3): void
    {
        // Ids normalizados a entero y en el orden recibido.
        $ids = array_values(array_map('intval', $article_ids));

        if (empty($ids)) {
            return;
        }

        // Primero se libera la marca de "encolado" de TODOS (misión embedding-un-solo-job), ANTES de
        // leer los artículos: un cambio que llegue mientras este job corre ya puede encolar otro (este
        // pudo haber leído el texto viejo), y uno que llegó antes de correr no encoló nada porque este
        // job lee el texto vigente.
        ArticleEmbeddingDispatcher::release_batch($ids);

        // Resultado de cada artículo, en memoria: se vuelca a la tanda de una sola vez al final.
        $resultados = ['generados' => 0, 'salteados' => 0, 'fallados' => 0];

        try {
            $this->procesar_articulos($ids, $resultados);
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('ArticleEmbeddingBatchProcessor: error al generar embeddings.', [
                'cantidad'         => count($ids),
                'primer_articulo'  => $ids[0],
                'embedding_run_id' => $embedding_run_id,
                'error'            => $exception->getMessage(),
            ]);

            // Re-lanzar para que la cola registre el fallo y reintente. Acá NO se cuenta nada: mientras
            // al job le queden reintentos sigue vivo, y sumar en cada intento cerraría la tanda de más.
            // El conteo del fracaso definitivo lo hace fallo_definitivo(), una sola vez.
            //
            // Si todavía quedan intentos el job sigue pendiente por el backoff: se vuelve a marcar a los
            // artículos para que nadie despache otro mientras tanto. En el último intento no se re-marca.
            if ($intento_actual < $intentos_maximos) {
                ArticleEmbeddingDispatcher::mark_batch_as_queued($ids);
            }

            throw $exception;
        }

        $this->registrar_resultados($embedding_run_id, $resultados);

        // Un solo log por lote (no una línea por artículo).
        Log::channel('daily')->info('ArticleEmbeddingBatchProcessor: lote procesado.', [
            'cantidad'         => count($ids),
            'generados'        => $resultados['generados'],
            'salteados'        => $resultados['salteados'],
            'fallados'         => $resultados['fallados'],
            'embedding_run_id' => $embedding_run_id,
        ]);
    }

    /**
     * Cuenta como fallados a todos los artículos del job cuando muere en forma definitiva.
     *
     * 🔴 `failed()` CORRE UNA SOLA VEZ, AL AGOTAR LOS `tries` — no en cada reintento. Es lo correcto:
     * mientras el job siga reintentando todavía puede terminar generando los vectores y la tanda tiene
     * que seguir esperándolo. Con `backoff = 60`, un lote que falla las tres veces tarda unos dos minutos
     * en llegar acá; es parte de por qué la ventana de vencimiento del finalizador es de veinte minutos.
     *
     * Libera las marcas (ya no hay job pendiente: el comando los puede volver a encolar) y suma
     * `fallados += cantidad, terminados += cantidad` a la tanda.
     *
     * @param int[]      $article_ids      Artículos del job.
     * @param int|null   $embedding_run_id Tanda, o null si el job no pertenece a ninguna.
     * @param \Throwable $exception        Lo que mató al job (sólo para el log).
     *
     * @return void
     */
    public function fallo_definitivo(array $article_ids, ?int $embedding_run_id, \Throwable $exception): void
    {
        // Ids normalizados.
        $ids = array_values(array_map('intval', $article_ids));

        Log::channel('daily')->error('ArticleEmbeddingBatchProcessor: el job murió definitivamente.', [
            'cantidad'         => count($ids),
            'primer_articulo'  => $ids[0] ?? null,
            'embedding_run_id' => $embedding_run_id,
            'error'            => $exception->getMessage(),
        ]);

        // El job ya no está pendiente: se liberan las marcas para que se puedan volver a encolar.
        ArticleEmbeddingDispatcher::release_batch($ids);

        $this->registrar_resultados($embedding_run_id, ['generados' => 0, 'salteados' => 0, 'fallados' => count($ids)]);
    }

    /**
     * Carga los artículos, descarta lo que no hay que mandar a OpenAI y genera el resto.
     *
     * @param int[] $ids        Ids del lote.
     * @param array $resultados Acumulador `generados|salteados|fallados` (por referencia).
     *
     * @return void
     */
    protected function procesar_articulos(array $ids, array &$resultados): void
    {
        // Artículos con las relaciones para armar el texto, indexados por id. Un id que no vuelve fue
        // eliminado entre el despacho y la ejecución.
        $articulos = Article::with(['category', 'brand', 'descriptions'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        // Ids cuyo texto ya está reflejado en su vector: sólo se les refresca la marca de tiempo.
        $ids_al_dia = [];

        // Candidatos a OpenAI: id, texto, huella y dueño, en el orden de los ids.
        $candidatos = [];

        foreach ($ids as $id) {
            $article = $articulos->get($id);

            if (! $article) {
                // Salteado y no fallado: no hay nada roto que arreglar, pero la tanda tiene que contarlo
                // igual o se queda esperando para siempre algo que ya terminó.
                $resultados['salteados']++;

                continue;
            }

            // El texto que efectivamente se vectoriza y su huella. Se calculan ANTES de cualquier
            // llamada a OpenAI: son lo único que permite saber si el artículo cambió en algo que al
            // embedding le mueva la aguja.
            $texto = $this->service->embedding_for_article($article);
            $hash  = sha1($texto);

            if ($texto === '') {
                // Artículo sin nombre, marca, categoría ni descripciones: no hay qué vectorizar. Queda
                // sellado con la huella del texto vacío (y sin vector) para que el comando no lo traiga
                // de nuevo para siempre.
                DB::table('articles')
                    ->where('id', $article->id)
                    ->update(['embedding_generated_at' => now(), 'embedding_source_hash' => $hash]);

                $resultados['salteados']++;

                continue;
            }

            if (! is_null($article->embedding) && (string) $article->embedding_source_hash === $hash) {
                // El texto relevante no cambió (caso típico: una importación que sólo movió precio o
                // stock): el vector que devolvería OpenAI es el que ya está guardado. Se refresca sólo la
                // marca de tiempo, para que el scheduler deje de re-encolarlo, y NO se gasta la llamada.
                $ids_al_dia[] = (int) $article->id;
                $resultados['salteados']++;

                continue;
            }

            $candidatos[] = [
                'id'      => (int) $article->id,
                'texto'   => $texto,
                'hash'    => $hash,
                'user_id' => (int) $article->user_id,
            ];
        }

        if (! empty($ids_al_dia)) {
            DB::table('articles')
                ->whereIn('id', $ids_al_dia)
                ->update(['embedding_generated_at' => now()]);
        }

        // Candidatos agrupados por dueño (en la práctica hay uno solo): una llamada por grupo.
        $grupos = [];
        foreach ($candidatos as $candidato) {
            $grupos[$candidato['user_id']][] = $candidato;
        }

        foreach ($grupos as $user_id => $grupo) {
            $this->procesar_grupo((int) $user_id, $grupo, $resultados, false);
        }
    }

    /**
     * Genera los vectores de un grupo de candidatos con una sola llamada y los persiste. Maneja cada
     * tipo de error como se describe en el docblock de la clase.
     *
     * @param int    $user_id    Dueño al que se le imputa el consumo.
     * @param array  $candidatos Lista de [id, texto, hash, user_id].
     * @param array  $resultados Acumulador `generados|salteados|fallados` (por referencia).
     * @param bool   $aislado    true cuando es el pedido de UN candidato reintentado por separado tras un
     *                           4xx del lote: ahí un nuevo 4xx lo deja fallado en vez de re-lanzar.
     *
     * @return void
     *
     * @throws \Throwable Para los errores transitorios (el job reintenta).
     */
    protected function procesar_grupo(int $user_id, array $candidatos, array &$resultados, bool $aislado): void
    {
        // Textos e ids en el mismo orden: el servicio devuelve un vector por texto en esa posición.
        $textos      = [];
        $article_ids = [];

        foreach ($candidatos as $candidato) {
            $textos[]      = $candidato['texto'];
            $article_ids[] = $candidato['id'];
        }

        try {
            $vectores = $this->service->generate_embeddings_batch($textos, $user_id, $article_ids);
        } catch (CupoEmbeddingsDePruebaException $exception) {
            // Sin cupo en la prueba gratuita: no se reintenta, se saltea.
            $resultados['salteados'] += count($candidatos);

            return;
        } catch (EmbeddingsCredencialRechazadaException $exception) {
            /*
             * 🔴 CREDENCIAL RECHAZADA (misión embeddings-corte-credencial-20261123): error PERMANENTE, y
             * este catch tiene que ir ANTES del `\Throwable` de abajo. NO se re-lanza (0 reintentos, 0
             * filas en `failed_jobs`) y NO se re-marca: la marca ya se liberó al empezar y tiene que
             * quedar así, o los artículos quedarían trabados 12 h esperando un reintento que no va a
             * haber. Tampoco se escribe embedding_generated_at ni embedding_source_hash: siguen
             * PENDIENTES y la primera pasada del comando con el corte vencido los vuelve a tomar.
             *
             * Se cuenta como `fallados` y no se espera a failed(): como el job termina acá y no falla,
             * failed() nunca corre, y sin esta suma la tanda no llegaría a `terminados == total_jobs`.
             */
            $resultados['fallados'] += count($candidatos);

            return;
        } catch (\Throwable $exception) {
            if ($this->es_texto_invalido($exception) && ($aislado || count($candidatos) > 1)) {
                if ($aislado) {
                    // El candidato solo también da 4xx: el problema es SU texto. Queda fallado, sin marca
                    // y sin huella nueva; el comando lo reintenta en la pasada siguiente (OpenAI no cobra
                    // un pedido rechazado, así que reintentarlo cada 30 min no cuesta).
                    Log::channel('daily')->warning('ArticleEmbeddingBatchProcessor: OpenAI rechazó el texto de un artículo.', [
                        'article_id' => $article_ids[0],
                        'error'      => $exception->getMessage(),
                    ]);

                    $resultados['fallados']++;

                    return;
                }

                // Un 4xx del lote entero no dice cuál texto es el culpable: se reintenta cada candidato
                // con su propio pedido (dentro del mismo job) para no perder a los que están bien.
                Log::channel('daily')->warning('ArticleEmbeddingBatchProcessor: OpenAI rechazó el lote; se reintenta cada artículo por separado.', [
                    'cantidad' => count($candidatos),
                    'error'    => $exception->getMessage(),
                ]);

                foreach ($candidatos as $candidato) {
                    $this->procesar_grupo($user_id, [$candidato], $resultados, true);
                }

                return;
            }

            // Transitorio (429 de rate limit, 5xx, timeout, respuesta inesperada) o un 4xx de un
            // artículo solo sin aislar: se re-lanza y el job reintenta.
            throw $exception;
        }

        foreach ($candidatos as $posicion => $candidato) {
            // Escribe el vector (y descarta el compacto viejo; ver persistir_embedding()).
            $this->service->persistir_embedding($candidato['id'], $vectores[$posicion]);

            // Cuándo se generó (para que el scheduler detecte ediciones posteriores) y la huella del
            // texto usado (para cortar la próxima vez si el artículo se toca pero el texto queda igual).
            DB::table('articles')
                ->where('id', $candidato['id'])
                ->update([
                    'embedding_generated_at' => now(),
                    'embedding_source_hash'  => $candidato['hash'],
                ]);

            $resultados['generados']++;
        }
    }

    /**
     * ¿El error es un rechazo de OpenAI por el CONTENIDO del pedido (400/413/422)?
     *
     * El servicio lanza `\RuntimeException` con el status HTTP como código
     * (ver ArticleEmbeddingService::lanzar_por_respuesta_fallida()).
     *
     * @param \Throwable $exception
     *
     * @return bool
     */
    protected function es_texto_invalido(\Throwable $exception): bool
    {
        return $exception instanceof \RuntimeException
            && in_array((int) $exception->getCode(), self::CODIGOS_DE_TEXTO_INVALIDO, true);
    }

    /**
     * Suma el resultado del job a la tanda con UN solo UPDATE y avanza su proceso visible.
     *
     * UPDATE con expresiones crudas (`campo + n`) y no leer-sumar-guardar: con varios workers en
     * paralelo el read-modify-write pierde incrementos, y perder uno significa que la tanda nunca llega
     * a `terminados == total_jobs`. `terminados` se suma en el mismo UPDATE que los contadores
     * específicos, para que nunca haya un instante con uno sumado y el otro no.
     *
     * Los jobs de ArticleObserver y DescriptionObserver llegan sin tanda: no hay a quién rendirle cuentas
     * y se sale sin tocar la base.
     *
     * @param int|null $embedding_run_id Tanda, o null.
     * @param array    $resultados       `generados|salteados|fallados`.
     *
     * @return void
     */
    protected function registrar_resultados(?int $embedding_run_id, array $resultados): void
    {
        if (is_null($embedding_run_id)) {
            return;
        }

        // Enteros: se concatenan en el SQL, así que nunca pueden venir de afuera sin castear.
        $generados = (int) $resultados['generados'];
        $salteados = (int) $resultados['salteados'];
        $fallados  = (int) $resultados['fallados'];

        DB::table('embedding_runs')
            ->where('id', $embedding_run_id)
            ->update([
                'generados'  => DB::raw('generados + '.$generados),
                'salteados'  => DB::raw('salteados + '.$salteados),
                'fallados'   => DB::raw('fallados + '.$fallados),
                'terminados' => DB::raw('terminados + '.($generados + $salteados + $fallados)),
                'updated_at' => now(),
            ]);

        $this->avanzar_proceso_visible($embedding_run_id);
    }

    /**
     * Si la tanda tiene un proceso visible en "Procesos en segundo plano", lo avanza con el valor
     * ABSOLUTO `embedding_runs.terminados` leído DESPUÉS del UPDATE atómico: idempotente y sin
     * depender del orden en que terminen los lotes. Nunca lanza (el helper tampoco): un fallo del
     * aviso no puede tirar abajo el job que ya escribió los vectores.
     *
     * @param int $embedding_run_id Tanda.
     *
     * @return void
     */
    protected function avanzar_proceso_visible(int $embedding_run_id): void
    {
        try {
            $tanda = EmbeddingRun::find($embedding_run_id);

            if (is_null($tanda)) {
                return;
            }

            $proceso = BackgroundProcessHelper::por_referencia($tanda);

            if (is_null($proceso)) {
                return;
            }

            BackgroundProcessHelper::avanzar($proceso, (int) $tanda->terminados, ['etapa' => self::ETAPA_PREPARANDO]);
        } catch (\Throwable $exception) {
            Log::channel('daily')->warning('ArticleEmbeddingBatchProcessor: no se pudo avanzar el proceso visible.', [
                'embedding_run_id' => $embedding_run_id,
                'error'            => $exception->getMessage(),
            ]);
        }
    }
}
