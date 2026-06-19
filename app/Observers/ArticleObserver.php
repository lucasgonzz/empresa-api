<?php

namespace App\Observers;

use App\Jobs\GenerateArticleEmbeddingJob;
use App\Models\Article;

/**
 * Observer del modelo Article para mantener los embeddings vectoriales actualizados.
 *
 * Despacha GenerateArticleEmbeddingJob al crear un artículo nuevo o al
 * modificar campos que afectan la representación semántica del artículo.
 * Esto garantiza que el índice vectorial esté siempre sincronizado con
 * el catálogo sin impactar el tiempo de respuesta del request (el job
 * se procesa de forma asincrónica en la cola).
 */
class ArticleObserver
{
    /**
     * Campos cuyo cambio invalida el embedding actual del artículo.
     * Si ninguno de estos campos cambió, no tiene sentido regenerar el vector.
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
     * Despacha el job de generación de embedding cuando se crea un artículo nuevo.
     * Siempre se genera porque el artículo aún no tiene embedding.
     *
     * @param Article $article Artículo recién creado.
     *
     * @return void
     */
    public function created(Article $article): void
    {
        GenerateArticleEmbeddingJob::dispatch($article->id);
    }

    /**
     * Despacha el job de regeneración de embedding cuando se actualiza un artículo,
     * pero solo si cambió al menos uno de los campos que afectan la representación
     * semántica del artículo (nombre, código, categoría, marca o estado).
     *
     * Verificar wasChanged() antes de despachar evita jobs innecesarios cuando
     * se actualizan campos como stock, precio o imágenes que no impactan la búsqueda.
     *
     * @param Article $article Artículo recién actualizado.
     *
     * @return void
     */
    public function updated(Article $article): void
    {
        // Solo despachar si cambió algún campo que modifica la semántica del artículo.
        if ($article->wasChanged(self::EMBEDDING_RELEVANT_FIELDS)) {
            GenerateArticleEmbeddingJob::dispatch($article->id);
        }
    }
}
