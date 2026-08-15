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
     * Creación de artículo: si el tenant pasa el gate, se encola la generación del vector.
     *
     * @param Article $article Artículo recién creado.
     *
     * @return void
     */
    public function created(Article $article): void
    {
        if (! $this->debe_generar_embedding($article->user_id)) {
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

        if (! $this->debe_generar_embedding($article->user_id)) {
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
     * @return void
     */
    public static function resetear_cache_gate(): void
    {
        self::$cache_gate = [];
    }

    /**
     * Decide si corresponde despachar el job de embedding para el dueño de un artículo.
     *
     * Replica el mismo criterio que usa el comando articles:generate-embeddings, para que
     * las dos puntas (observer inmediato y scheduler cada 30 min) no se contradigan.
     *
     * @param int|string|null $user_id Dueño del artículo (articles.user_id).
     *
     * @return bool
     */
    private function debe_generar_embedding($user_id): bool
    {
        // 1. Sin dueño no hay a quién preguntarle por la extensión.
        if (empty($user_id)) {
            return false;
        }

        $clave = (int) $user_id;

        // Memo por proceso: ver el comentario largo de $cache_gate. Si este tenant ya se
        // resolvió, no se vuelve a la base.
        if (array_key_exists($clave, self::$cache_gate)) {
            return self::$cache_gate[$clave];
        }

        // Se cachea la negativa de entrada: cualquier salida temprana de acá abajo deja
        // false guardado sin tener que repetirlo en cada return.
        self::$cache_gate[$clave] = false;

        // 2. El usuario con sus extensiones cargadas.
        $user = User::with('extencions')->find($clave);

        if (is_null($user)) {
            return false;
        }

        // 3. Sin la extensión whatsapp_ia el catálogo vectorial no lo consume nadie, así
        // que generarlo sería gasto puro.
        if (! UserHelper::hasExtencion('whatsapp_ia', $user)) {
            return false;
        }

        // 4. Importación en curso: no se dispara nada. Una importación toca miles de
        // artículos de una y encolaría una tormenta de jobs sobre filas que además siguen
        // cambiando. Lo que el observer se saltea acá lo levanta después el comando
        // agendado, que justamente busca los artículos con updated_at > embedding_generated_at.
        $importacion_activa = ImportStatus::where('user_id', $clave)
            ->where('status', 'en_proceso')
            ->exists();

        if ($importacion_activa) {
            return false;
        }

        self::$cache_gate[$clave] = true;

        return true;
    }
}
