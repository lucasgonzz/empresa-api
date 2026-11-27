<?php

namespace App\Console\Commands;

use App\Exceptions\CupoEmbeddingsDePruebaException;
use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\PruebaHelper;
use App\Jobs\FinalizeEmbeddingRun;
use App\Models\Article;
use App\Models\EmbeddingRun;
use App\Models\ImportacionDePrueba;
use App\Models\ImportStatus;
use App\Models\User;
use App\Services\ArticleEmbeddingBatchProcessor;
use App\Services\ArticleEmbeddingDispatcher;
use App\Services\ArticleEmbeddingService;
use App\Services\CupoEmbeddingsDePrueba;
use App\Services\EmbeddingsCredencialCorte;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Comando Artisan para mantener los embeddings vectoriales del catálogo actualizados.
 *
 * Se ejecuta periódicamente desde el scheduler (cada 30 minutos) y procesa:
 * - Artículos sin embedding todavía (embedding IS NULL).
 * - Artículos modificados desde la última generación (updated_at > embedding_generated_at).
 *
 * Solo se ejecuta si el usuario dueño de la instancia tiene activa la extensión
 * "agentes_ia". Si hay una importación en proceso para ese usuario, el comando
 * se saltea para evitar generar embeddings de artículos que pueden estar cambiando.
 *
 * Uso manual:
 *   php artisan articles:generate-embeddings
 *   php artisan articles:generate-embeddings --all    # regenera todos (re-index completo)
 */
class GenerateArticleEmbeddings extends Command
{
    /**
     * Pendientes a partir de los cuales la tanda se muestra en "Procesos en segundo plano" (misión
     * embeddings-lote-cola): un lote entero. Por debajo termina en un solo lote y sería ruido (el
     * scheduler cada 30 minutos suele encontrar pocos artículos).
     *
     * @var int
     */
    const UMBRAL_PROCESO_VISIBLE = ArticleEmbeddingBatchProcessor::TAMANO_LOTE;

    /**
     * Tipo del proceso visible de la tanda (columna `background_processes.tipo`).
     *
     * @var string
     */
    const TIPO_PROCESO = 'embeddings_articulos';

    /**
     * Nombre y firma del comando Artisan.
     * La opción --all fuerza regenerar embeddings incluso para artículos que ya los tienen.
     *
     * @var string
     */
    protected $signature = 'articles:generate-embeddings
                            {--all : Regenerar embeddings incluso para artículos que ya los tienen}
                            {--origen=scheduler : De dónde salió esta corrida (scheduler|importacion|manual)}
                            {--ignorar-importacion-en-curso : Ver FinalizeArticleImport; NO usar a mano}
                            {--importacion-prueba= : Corrida automática que ya terminó la etapa de imágenes}';

    /**
     * Descripción que aparece en php artisan list.
     *
     * @var string
     */
    protected $description = 'Despacha jobs para generar embeddings vectoriales de artículos pendientes (requiere extensión agentes_ia)';

    /**
     * Ejecuta el comando: verifica extensión activa, importaciones en curso y despacha
     * GenerateArticleEmbeddingsBatchJob por cada lote de hasta 100 artículos pendientes (una sola llamada
     * a OpenAI por lote). Si hay 100 pendientes o más, además abre un proceso visible en "Procesos en
     * segundo plano".
     *
     * @return int Código de salida (0 = éxito).
     */
    public function handle(): int
    {
        // Resolver el usuario dueño de la instancia (config app.USER_ID).
        $user_id = config('app.USER_ID');

        if (empty($user_id)) {
            $this->warn('articles:generate-embeddings: USER_ID no configurado en .env. Se omite.');
            return 0;
        }

        // Cargar el usuario con sus extensiones para verificar acceso.
        $user = User::with('extencions')->find($user_id);

        if (is_null($user)) {
            $this->warn("articles:generate-embeddings: usuario ID {$user_id} no encontrado. Se omite.");
            return 0;
        }

        $importacion_de_prueba = null;
        if (PruebaHelper::puede_haber_prueba() && Schema::hasTable('importaciones_de_prueba')) {
            $id = $this->option('importacion-prueba');
            $corridas = ImportacionDePrueba::where('user_id', $user_id);
            if ($id !== null) {
                $importacion_de_prueba = $corridas->where('estado', 'indexar')->find((int) $id);
                if ($importacion_de_prueba === null || !PruebaHelper::en_periodo_vigente($user_id)) {
                    return 0;
                }
            } elseif ($corridas->whereIn('estado', ['importando', 'imagenes', 'indexar', 'indexando'])->exists()) {
                return 0;
            }
        }

        /*
         * Pausa global (misión busqueda-lenta-y-pausa-embeddings): interruptor independiente de la
         * extensión agentes_ia, para poder cortar la generación en TODO el parque de un saque sin
         * ir comercio por comercio desactivando la extensión (que además apagaría con eso la
         * búsqueda del bot de WhatsApp para quien ya la esté usando). Va ANTES del gate de la
         * extensión a propósito: a diferencia del aviso de OPENAI_API_KEY de más abajo -que sigue
         * silencioso, porque no tener la extensión es la situación normal de la mayoría de las
         * instancias-, acá la pausa es una decisión operativa deliberada y conviene que quede en el
         * log del scheduler mientras esté prendida. Mismo interruptor que
         * ArticleObserver::debe_generar_embedding() (el disparo inmediato) y el Kernel (que ni
         * siquiera agenda el comando si está prendida). Default false: no cambia nada para nadie
         * hasta que alguien la prenda a mano en el .env.
         *
         * Se lee con config('services.openai.embeddings_generacion_pausada'), NUNCA con env()
         * directo acá: con config:cache activo (lo normal en producción) env() fuera de config/
         * devuelve el default y prender la variable en el .env de un cliente real no haría nada
         * — mismo bug que ya pasó con DURACION_REPORTES, ver config/services.php.
         */
        if (config('services.openai.embeddings_generacion_pausada')) {
            $this->warn('articles:generate-embeddings: generación pausada por EMBEDDINGS_GENERACION_PAUSADA. Se omite.');
            return 0;
        }

        // Solo procesar si el usuario tiene la extensión agentes_ia activa.
        if (! UserHelper::hasExtencion('agentes_ia', $user)) {
            // Silencioso en scheduler; el usuario simplemente no tiene la extensión.
            return 0;
        }

        /*
         * Sin clave de OpenAI no hay nada que despachar (misión optimizacion-vps-fase1, 4.0.24).
         *
         * Va DESPUÉS del gate de la extensión (que sigue silencioso: las instancias sin agentes_ia
         * no tienen por qué ver un aviso cada 30 minutos) y ANTES de tocar la base. El caso real:
         * el .env del segundo frente de ferretotal se instaló sin OPENAI_API_KEY y este comando
         * siguió encolando cada media hora — 35.324 jobs fallidos (401 de OpenAI, tres intentos
         * cada uno) sin que nadie lo viera. Un job que nace sin clave no puede terminar bien, así
         * que mejor un warn por ciclo en el log del scheduler. Cubre también el disparo
         * post-importación: FinalizeArticleImport llama a este mismo comando con
         * --ignorar-importacion-en-curso. El sync de claves entre frentes del deploy (admin-api,
         * misma misión) ataca la causa; esto es la red para cualquier otra forma de quedarse sin ella.
         */
        if (trim((string) config('services.openai.api_key')) === '') {
            $this->warn('articles:generate-embeddings: no hay OPENAI_API_KEY configurada. No se despacha ningún job hasta que se cargue.');
            return 0;
        }

        /*
         * 🔴 Corte por credencial rechazada (misión embeddings-corte-credencial-20261123). Si OpenAI ya
         * rechazó la clave (401/403 con cuerpo de OpenAI, o 429 sin crédito), no se abre tanda ni se
         * despacha un solo job hasta que venza el corte (EMBEDDINGS_CORTE_MINUTOS, default 60) o alguien lo
         * cierre con `php artisan cache:forget embeddings_credencial_rechazada`. Va DESPUÉS del gate de la
         * extensión y de la guarda de la clave (que siguen como estaban) y ANTES de tocar la base. Es la
         * misma forma que la pausa global de más arriba, sólo que esta se activa sola. Los artículos
         * quedan pendientes: la primera pasada con el corte vencido toma los mismos.
         */
        if (EmbeddingsCredencialCorte::abierto()) {
            $this->warn('articles:generate-embeddings: OpenAI rechazó la credencial y hay un corte abierto (se reintenta solo al vencer; para reintentar antes: php artisan cache:forget '.EmbeddingsCredencialCorte::CLAVE.'). No se despacha ningún job.');
            return 0;
        }

        try {
            CupoEmbeddingsDePrueba::comprobar((int) $user_id);
        } catch (CupoEmbeddingsDePruebaException $exception) {
            $this->info($exception->getMessage());
            return 0;
        }

        /*
         * Saltearse si hay una importación en proceso para este usuario.
         * Evita generar embeddings de artículos que pueden estar cambiando en este momento.
         *
         * 🔴 LA OPCIÓN QUE SALTEA ESTE GATE EXISTE PARA UN SOLO LLAMADOR: FinalizeArticleImport.
         * No la uses a mano y no la agregues a la llamada del scheduler.
         *
         * El motivo es que este gate mira TODOS los ImportStatus del usuario, no el de la
         * importación que acaba de terminar. Y eso lo vuelve peligroso justo en el momento en
         * que más falta hace generar embeddings:
         *
         * 1. FinalizeArticleImport corre, por construcción, cuando
         *    `processed_chunks >= total_chunks`; el ImportStatus de ESA importación ya quedó en
         *    'completado' desde ProcessArticleChunk::update_import_status().
         * 2. InitExcelImport::tiene_importacion_en_curso() impide que exista otra importación
         *    concurrente del mismo usuario.
         * 3. Por (1) y (2), cualquier ImportStatus en 'en_proceso' que este gate llegue a ver en
         *    ese instante es basura de una corrida que murió sin pasar por ImportFailureHandler
         *    (worker matado, OOM crudo). Nadie limpia esas filas: DetectarImportacionesColgadas
         *    filtra por ImportHistory, no por ImportStatus.
         *
         * O sea que sin la opción, un ImportStatus huérfano deja este comando mudo PARA SIEMPRE
         * -- tanto la corrida agendada como la post-importación --, y el agente de WhatsApp nunca
         * vuelve a encontrar un artículo nuevo. El test 17 fija las dos mitades: con la opción
         * corre igual, sin la opción no hace nada.
         */
        if (!$this->option('ignorar-importacion-en-curso')) {
            $importacion_activa = ImportStatus::where('user_id', $user_id)
                ->where('status', 'en_proceso')
                ->exists();

            if ($importacion_activa) {
                $this->info('articles:generate-embeddings: hay una importación en proceso. Se omite hasta el próximo ciclo.');
                return 0;
            }
        }

        // Determinar si se deben regenerar todos o solo los pendientes.
        $regenerate_all = (bool) $this->option('all');

        /*
         * Fase 1 (misión embedding-un-solo-job): sellar SIN job los artículos cuyo texto no cambió.
         *
         * Un artículo con `updated_at` movido por una venta, un cambio de stock o de precio matchea
         * el filtro de pendientes pero su texto vectorizable es el mismo: antes eso costaba un job
         * por artículo y por ciclo que cortaba por huella. Ahora se resuelve acá, sin cola, sin
         * tanda y sin contar. Con --all no corre: ahí se quiere forzar el re-despacho.
         */
        if (! $regenerate_all) {
            $this->sellar_articulos_al_dia((int) $user_id);
        }

        // Query base: pendientes del usuario (ver pendientes_query()).
        $query = $this->pendientes_query((int) $user_id, $regenerate_all);

        // Contar total para informar al usuario antes de comenzar.
        $total = $query->count();

        if ($total === 0) {
            $this->info('articles:generate-embeddings: todos los embeddings están actualizados.');
            return 0;
        }

        $mode_label = $regenerate_all ? 'todos (--all)' : 'pendientes';
        $this->info("articles:generate-embeddings: despachando lotes para {$total} artículos ({$mode_label})...");

        /*
         * Guarda anti-solapamiento. El scheduler corre esto cada 30 minutos y una importación
         * grande puede dispararlo en cualquier momento: si se abrieran dos tandas del mismo
         * usuario a la vez, el comerciante recibiría dos avisos pisándose y los mismos artículos
         * tendrían jobs duplicados en la cola.
         *
         * Una tanda vieja que quedó abierta NO bloquea para siempre: se la marca vencida y se
         * sigue. La ventana es la misma que usa FinalizeEmbeddingRun para darse por vencido, así
         * que las dos puntas coinciden en cuándo una tanda dejó de estar viva.
         */
        $run_abierta = EmbeddingRun::where('user_id', $user_id)
            ->whereIn('status', ['despachando', 'en_proceso'])
            ->orderBy('id', 'desc')
            ->first();

        if (!is_null($run_abierta)) {
            /** Momento a partir del cual una tanda abierta se considera abandonada. */
            $vence_at = FinalizeEmbeddingRun::vence_at($run_abierta);

            if (Carbon::now()->lessThan($vence_at)) {
                $this->info('articles:generate-embeddings: ya hay una tanda en curso (id '.$run_abierta->id.'). Se omite.');
                return 0;
            }

            $run_abierta->status = 'vencida';
            $run_abierta->save();

            // Su proceso visible (si tenía) se cierra igual: nadie más lo va a cerrar, el finalizador de
            // esa tanda ya se dio por vencido o murió con ella.
            FinalizeEmbeddingRun::cerrar_proceso_visible($run_abierta, 'vencida');
        }

        /*
         * 🔴 LA TANDA NACE EN 'despachando' Y RECIÉN DESPUÉS PASA A 'en_proceso'. Los dos estados
         * no son cosmética: son lo único que impide que la tanda se cierre antes de existir.
         *
         * Con QUEUE_CONNECTION=database el worker empieza a levantar los jobs del primer chunk
         * mientras este comando todavía está despachando el segundo. Si la tanda naciera
         * directamente en 'en_proceso' con `total_jobs = 0`, FinalizeEmbeddingRun podría correr
         * en ese hueco, ver `terminados (0) >= total_jobs (0)` y dar la tanda por terminada sin
         * que se hubiera despachado un solo job: aviso fantasma, y la tanda real quedando
         * huérfana sin que nadie la cierre nunca.
         */
        $run = EmbeddingRun::create([
            'user_id'    => $user_id,
            'origen'     => (string) $this->option('origen'),
            'status'     => 'despachando',
            'total_jobs' => 0,
        ]);
        if ($importacion_de_prueba !== null) {
            $importacion_de_prueba->update([
                'estado' => 'indexando', 'embedding_run_id' => $run->id,
            ]);
        }

        /*
         * Proceso visible en "Procesos en segundo plano" (misión embeddings-lote-cola). Se crea ACÁ, recién
         * creada la tanda y ANTES de despachar: con una cola `sync` o `database` los lotes ya corren
         * mientras se despacha, y el primero que termine tiene que encontrar el proceso para avanzarlo.
         * Sólo si hay un lote o más de pendientes; los observers (una edición suelta) nunca lo crean.
         * Nace `pendiente` ("En espera del procesador") y pasa a `en_proceso` con el primer avance.
         */
        $proceso = null;
        if ($total >= self::UMBRAL_PROCESO_VISIBLE) {
            $proceso = BackgroundProcessHelper::iniciar($user_id, self::TIPO_PROCESO, 'Preparando búsqueda inteligente de artículos', [
                'total'      => $total,
                'unidad'     => 'artículos',
                'status'     => 'pendiente',
                'etapa'      => 'En espera del procesador',
                'detalle'    => $this->detalle_del_proceso((string) $this->option('origen')),
                'referencia' => $run,
            ]);
        }

        // Cantidad de ARTÍCULOS despachados (no de jobs: `total_jobs` cuenta artículos desde esta misión).
        $dispatched = 0;

        // Se procesa de a un lote de artículos para no cargar todo en memoria y para que cada
        // despacho sea UN job con una sola llamada a OpenAI.
        $query->chunkById(ArticleEmbeddingBatchProcessor::TAMANO_LOTE, function ($articles) use (&$dispatched, $run) {
            // Sólo cuenta lo realmente reclamado: los artículos que ya tenían un job vigente (de un
            // observer o de otro proceso) no entran al lote, y total_jobs tiene que coincidir con lo que
            // va a reportar a la tanda o FinalizeEmbeddingRun no cierra.
            $dispatched += ArticleEmbeddingDispatcher::dispatch_batch_if_not_queued(
                $articles->pluck('id')->map(function ($id) {
                    return (int) $id;
                })->all(),
                (int) $run->id
            );

            $this->info("  {$dispatched} artículos despachados...");
        });

        // Cierre del despacho: recién ahora `total_jobs` es definitivo (cuenta ARTÍCULOS) y el
        // finalizador tiene permitido comparar contadores.
        $run->total_jobs = $dispatched;
        $run->status     = 'en_proceso';
        $run->save();

        // Si se despachó menos de lo contado (otro proceso ya había reclamado artículos), el total del
        // proceso visible se corrige. Sólo en ese caso: avanzar() lo saca de "pendiente".
        if (! is_null($proceso) && $dispatched !== $total) {
            BackgroundProcessHelper::avanzar($proceso, null, ['total' => $dispatched]);
        }

        /*
         * El finalizador es el que cuenta cuántos vectores se escribieron DE VERDAD y avisa. No
         * puede hacerlo este comando: los jobs corren en el worker, en otro proceso, y muchos van
         * a cortar por huella sin generar nada.
         */
        FinalizeEmbeddingRun::dispatch($run->id)
            ->delay(Carbon::now()->addSeconds(10));

        // El id va al output para poder ir a mirar la fila cuando el aviso no aparezca.
        $this->info("articles:generate-embeddings: listo. {$dispatched} artículos en cola (tanda {$run->id}).");

        return 0;
    }

    /**
     * Texto de detalle del proceso visible según de dónde salió la tanda.
     *
     * @param string $origen scheduler | importacion | prueba | manual.
     *
     * @return string
     */
    private function detalle_del_proceso(string $origen): string
    {
        switch ($origen) {
            case 'importacion':
                return 'Artículos de la importación';
            case 'prueba':
                return 'Catálogo de la prueba';
            case 'scheduler':
                return 'Artículos nuevos o modificados';
            default:
                return 'Pedido manual';
        }
    }

    /**
     * Arma la consulta de artículos que hay que despachar.
     *
     * Artículos activos y no borrados del usuario, que no tengan un job vigente (marca
     * `embedding_queued_at` vacía o vencida). Sin $regenerate_all, además tienen que estar
     * pendientes: nunca procesados, tocados después de la última generación, o sin vector salvo
     * que ya se hayan procesado con texto vacío (huella sha1 de '', que deja el vector en NULL a
     * propósito y no debe volver a traerse para siempre).
     *
     * @param int  $user_id        Dueño de la instancia.
     * @param bool $regenerate_all true con --all: sólo se respeta la marca.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function pendientes_query(int $user_id, bool $regenerate_all)
    {
        // Momento a partir del cual una marca se considera de un job perdido.
        $marca_vencida_antes_de = Carbon::now()->subMinutes(ArticleEmbeddingDispatcher::QUEUED_MARK_TTL_MINUTES);

        $query = Article::query()
            ->where('user_id', $user_id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->where(function ($q) use ($marca_vencida_antes_de) {
                $q->whereNull('embedding_queued_at')
                  ->orWhere('embedding_queued_at', '<', $marca_vencida_antes_de);
            })
            ->select('id');

        if (! $regenerate_all) {
            $query->where(function ($q) {
                $q->whereNull('embedding_generated_at')
                  ->orWhereColumn('updated_at', '>', 'embedding_generated_at')
                  ->orWhere(function ($sin_vector) {
                      $sin_vector->whereNull('embedding')
                          ->where(function ($huella) {
                              $huella->whereNull('embedding_source_hash')
                                  ->orWhere('embedding_source_hash', '<>', ArticleEmbeddingDispatcher::EMPTY_TEXT_HASH);
                          });
                  });
            });
        }

        return $query;
    }

    /**
     * Sella `embedding_generated_at` de los pendientes cuyo texto vectorizable no cambió.
     *
     * Recorre los pendientes de a 50 sin hidratar el vector (sólo se trae si existe, como
     * `sin_vector`), arma el texto actual y, si su huella coincide con la guardada y el vector
     * está (o el texto es vacío), los sella en bloque. Se sella sólo lo no editado desde el
     * inicio de la lectura del chunk (`updated_at < inicio`): lo editado en ese mismo instante
     * pasa a la fase de despacho, donde el job decide por huella.
     *
     * @param int $user_id Dueño de la instancia.
     *
     * @return void
     */
    private function sellar_articulos_al_dia(int $user_id): void
    {
        $service = new ArticleEmbeddingService();

        $query = $this->pendientes_query($user_id, false)
            ->select('id', 'user_id', 'name', 'bar_code', 'category_id', 'brand_id', 'embedding_source_hash')
            ->selectRaw('(embedding IS NULL) AS sin_vector')
            ->with(['category', 'brand', 'descriptions']);

        // Inicio de la lectura del chunk, tomado ANTES del SELECT que lo trae (ver el criterio en
        // el PHPDoc del método). El primero se toma acá; los siguientes, al final de cada callback,
        // que es justo antes de que chunkById consulte el bloque que sigue.
        $inicio_del_chunk = Carbon::now();

        $query->chunkById(50, function ($articles) use ($service, &$inicio_del_chunk) {

            // IDs cuyo texto ya está reflejado en el vector guardado.
            $ids_al_dia = [];

            foreach ($articles as $article) {
                $texto = $service->embedding_for_article($article);

                if (ArticleEmbeddingDispatcher::is_text_up_to_date(
                    $article->embedding_source_hash,
                    ! (bool) $article->sin_vector,
                    $texto
                )) {
                    $ids_al_dia[] = $article->id;
                }
            }

            if (! empty($ids_al_dia)) {
                DB::table('articles')
                    ->whereIn('id', $ids_al_dia)
                    ->where('updated_at', '<', $inicio_del_chunk)
                    ->update(['embedding_generated_at' => Carbon::now()]);
            }

            // El próximo bloque se lee después de este momento.
            $inicio_del_chunk = Carbon::now();
        });
    }
}
