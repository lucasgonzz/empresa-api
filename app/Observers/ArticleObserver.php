<?php

namespace App\Observers;

use App\Http\Controllers\Helpers\UserHelper;
use App\Jobs\GenerateArticleEmbeddingJob;
use App\Models\Article;
use App\Models\ImportStatus;
use App\Models\User;

/**
 * Observer del modelo Article para mantener los embeddings vectoriales actualizados.
 *
 * Despacha GenerateArticleEmbeddingJob al crear un artículo nuevo o al modificar
 * alguno de los campos que efectivamente entran en el texto que se vectoriza. Así el
 * índice semántico queda sincronizado con el catálogo casi en el acto, en vez de
 * depender de esperar hasta media hora a que corra articles:generate-embeddings.
 *
 * El comando agendado NO se saca ni se toca: sigue siendo la red de seguridad que
 * levanta todo lo que el observer se saltea a propósito (lo que se guarda mientras
 * hay una importación en curso, por ejemplo).
 */
class ArticleObserver
{
    /**
     * Campos cuyo cambio invalida el embedding actual del artículo.
     * Si ninguno de estos campos cambió, no tiene sentido regenerar el vector.
     *
     * Ojo con lo que NO está en esta lista: final_price y stock quedaron afuera a
     * propósito. Son las dos columnas que más se mueven (una importación de precios
     * las pisa enteras) y ninguna de las dos entra en el texto que arma
     * ArticleEmbeddingService::embedding_for_article(), así que regenerar por ellas
     * sería pagarle a OpenAI para que devuelva exactamente el mismo vector.
     *
     * @var array<int, string>
     */
    private const EMBEDDING_RELEVANT_FIELDS = [
        'name',
        'bar_code',
        'category_id',
        'brand_id',
        'status',
    ];

    /**
     * Resultado del gate ya resuelto, indexado por user_id. Vive lo que vive el proceso:
     * un request web, un comando de consola o un worker de cola.
     *
     * Es UNO SOLO para los dos observers (Article y Description): DescriptionObserver
     * llama a debe_generar_embedding() de acá justamente para compartir este memo y que
     * resetear_cache_gate() siga siendo un único lugar donde limpiar.
     *
     * ESTO NO ES UNA OPTIMIZACIÓN OPCIONAL, es parte del diseño. El gate cuesta dos
     * consultas (el usuario con sus extensiones, y si hay una importación en curso) y el
     * observer corre UNA VEZ POR ARTÍCULO GUARDADO. Sin el memo, una importación de
     * 20.000 artículos son 40.000 consultas de más, todas devolviendo exactamente lo
     * mismo. Si alguien viene a "simplificar" borrando esto, está reintroduciendo esas
     * 40.000 consultas.
     *
     * Memorizar es correcto porque las importaciones marcan el ImportStatus en
     * 'en_proceso' ANTES de tocar el primer artículo (ver ProcessArticleChunk, que llama
     * a set_import_status_at_chunk_start() al arrancar el chunk y recién después corre el
     * importador), así que la primera consulta del proceso ya ve el estado definitivo.
     * Y si aun así el estado cambiara a mitad de camino, lo peor que pasa es que el
     * observer se saltee artículos: el comando articles:generate-embeddings, cada 30
     * minutos, los levanta igual.
     *
     * @var array<int, bool>
     */
    private static $cache_gate = [];

    /**
     * Prendido mientras corre un seeder que ya trae los vectores calculados.
     *
     * 🔴 ESTE FLAG NO REEMPLAZA AL GATE DE EXTENSIÓN NI AL DE IMPORTACIÓN. Es un TERCER
     * corte, con una razón distinta a las otras dos, y por eso está separado:
     *
     * - El gate de extensión corta porque el catálogo vectorial no lo consume nadie.
     * - El gate de importación corta porque las filas todavía están cambiando y el
     *   comando agendado las va a levantar igual más tarde.
     * - Este corta porque durante la semilla el vector NO SE CALCULA, SE COPIA: el seeder
     *   de la ferretería lee los embeddings horneados de un archivo commiteado en el repo
     *   y los persiste tal cual. Despachar el job sería salir a pagarle a OpenAI por un
     *   vector que ya está en el repo. Y no es una llamada por artículo: sembrar los 46
     *   artículos dispara 138 jobs (uno por `Article::create()` más dos por las
     *   `Description::create()` de cada uno), y los tres jobs de un mismo artículo ven
     *   textos distintos, así que ni el corte por `embedding_source_hash` los salva.
     *
     * Por qué el flag vive ACÁ y no en los otros dos lugares donde alguien lo pondría:
     *
     * - `Article::withoutEvents()` en el seeder apagaría TODOS los observers del modelo,
     *   no solo el de embeddings, y `crear_article()` hace bastante más que crear la fila.
     * - `Event::fake()` es una herramienta de tests; no tiene nada que hacer adentro de un
     *   seeder que se corre en local y en la demo.
     * - `debe_generar_embedding()` YA ES el punto único donde los DOS observers (Article y
     *   Description) deciden — `DescriptionObserver` la llama tal cual, justamente para no
     *   duplicar criterio. Poniendo el flag ahí queda cubierto de una sola vez el artículo
     *   Y sus descripciones, sin tocar `ArticleSeederHelper`, `Description` ni
     *   `AppServiceProvider`.
     *
     * @var bool
     */
    private static $semilla_en_curso = false;

    /**
     * Avisa que arranca una semilla con vectores ya horneados: a partir de acá no se
     * despacha ningún job de embedding hasta que se llame a terminar_semilla().
     *
     * El seeder tiene que apagarlo en un `finally`, no al final del `run()`: si el seeder
     * revienta a mitad de camino, el flag prendido silenciaría los embeddings del resto
     * del proceso.
     *
     * @return void
     */
    public static function empezar_semilla(): void
    {
        self::$semilla_en_curso = true;
    }

    /**
     * Cierra la semilla y devuelve los observers a su comportamiento normal.
     *
     * @return void
     */
    public static function terminar_semilla(): void
    {
        self::$semilla_en_curso = false;
    }

    /**
     * Creación de artículo: si el tenant pasa el gate, se encola la generación del vector.
     *
     * @param Article $article Artículo recién creado.
     *
     * @return void
     */
    public function created(Article $article): void
    {
        if (! self::debe_generar_embedding($article->user_id)) {
            return;
        }

        GenerateArticleEmbeddingJob::dispatch($article->id);
    }

    /**
     * Actualización de artículo: solo se encola si cambió algo que el embedding
     * realmente mira.
     *
     * El chequeo de wasChanged() va PRIMERO porque no toca la base (compara lo que
     * Eloquent ya tiene en memoria después del save), mientras que el gate sí consulta
     * la primera vez. De esta manera una importación que solo mueve final_price o stock
     * se corta acá sin gastar ni una consulta.
     *
     * @param Article $article Artículo recién actualizado.
     *
     * @return void
     */
    public function updated(Article $article): void
    {
        if (! $article->wasChanged(self::EMBEDDING_RELEVANT_FIELDS)) {
            return;
        }

        if (! self::debe_generar_embedding($article->user_id)) {
            return;
        }

        GenerateArticleEmbeddingJob::dispatch($article->id);
    }

    /**
     * Olvida el memo del gate.
     *
     * Existe para dos casos concretos: los tests, que crean usuarios y extensiones
     * dentro del mismo proceso de PHPUnit y necesitan que el gate se vuelva a evaluar de
     * cero en cada caso; y un worker de cola de vida larga que quiera refrescar el
     * criterio sin reiniciarse.
     *
     * Limpia el memo para los DOS observers, porque el memo es uno solo.
     *
     * 🔴 LIMPIA TAMBIÉN EL FLAG DE SEMILLA, y no es de yapa. Los tests que corren el seeder
     * de la ferretería lo prenden; si uno de esos tests revienta entre `empezar_semilla()` y
     * el `finally` que lo apaga, el flag queda prendido para TODO el resto de la suite de
     * PHPUnit, que es un solo proceso. A partir de ahí ningún test de embeddings vuelve a
     * ver un job despachado y todos fallan por un motivo que no tiene nada que ver con lo
     * que están probando. Este método ya se llama en el `setUp`/`tearDown` de esos tests,
     * así que es el lugar donde el reseteo se garantiza solo.
     *
     * @return void
     */
    public static function resetear_cache_gate(): void
    {
        self::$cache_gate       = [];
        self::$semilla_en_curso = false;
    }

    /**
     * Decide si corresponde despachar el job de embedding para el dueño de un artículo.
     *
     * Replica el mismo criterio que usa el comando articles:generate-embeddings, para que
     * las dos puntas (observer inmediato y scheduler cada 30 min) no se contradigan.
     *
     * Es pública y estática a propósito: DescriptionObserver la usa tal cual para no
     * duplicar el criterio (extensión whatsapp_ia + importación en curso) ni tener un
     * segundo memo que resetear_cache_gate() se olvidaría de limpiar. Si mañana hay que
     * cambiar el criterio, se cambia acá y las dos puntas quedan iguales solas.
     *
     * @param int|string|null $user_id Dueño del artículo (articles.user_id).
     *
     * @return bool
     */
    public static function debe_generar_embedding($user_id): bool
    {
        // 0. Semilla en curso: se corta ANTES QUE NADA, sin mirar usuario ni base. El
        // seeder ya trae los vectores calculados y los persiste él mismo; encolar el job
        // sería pagarle a OpenAI por algo que está commiteado en el repo. Ver el docblock
        // de $semilla_en_curso para el porqué de que el corte viva acá y no en el seeder.
        if (self::$semilla_en_curso) {
            return false;
        }

        // 1. Sin dueño no hay a quién preguntarle por la extensión.
        if (empty($user_id)) {
            return false;
        }

        $clave = (int) $user_id;

        // 🔴 EL MEMO CUBRE SOLO LA EXTENSIÓN, NO LA IMPORTACIÓN. No unifiques los dos
        // chequeos en una sola entrada de caché, por más que ahorre una consulta.
        //
        // La extensión de un negocio no cambia durante la vida de un proceso, así que
        // memorizarla es seguro. El estado de la importación SÍ cambia, y justo en el
        // orden que rompe todo: un `queue:work` de vida larga procesa cualquier job que
        // guarde un artículo, deja memorizado "sí, generá", y recién DESPUÉS el dueño
        // sube un Excel de 20.000 filas. Con el memo unificado, ese worker nunca vuelve
        // a preguntar y despacha los 20.000 jobs — exactamente la avalancha que el punto
        // 3 de abajo viene a evitar.
        if (array_key_exists($clave, self::$cache_gate) && self::$cache_gate[$clave] === false) {
            return false;
        }

        // 2. Extensión: se resuelve una sola vez por proceso y por tenant.
        if (! array_key_exists($clave, self::$cache_gate)) {
            self::$cache_gate[$clave] = false;

            $user = User::with('extencions')->find($clave);

            if (is_null($user)) {
                return false;
            }

            // Sin la extensión whatsapp_ia el catálogo vectorial no lo consume nadie, así
            // que generarlo sería gasto puro.
            if (! UserHelper::hasExtencion('whatsapp_ia', $user)) {
                return false;
            }

            self::$cache_gate[$clave] = true;
        }

        // 3. Importación en curso: se pregunta SIEMPRE, sin memo. Una importación toca
        // miles de artículos de una y encolaría una tormenta de jobs sobre filas que
        // además siguen cambiando. Lo que el observer se saltea acá lo levanta después el
        // comando agendado, que busca los artículos con updated_at > embedding_generated_at.
        $importacion_activa = ImportStatus::where('user_id', $clave)
            ->where('status', 'en_proceso')
            ->exists();

        if ($importacion_activa) {
            return false;
        }

        return true;
    }
}
