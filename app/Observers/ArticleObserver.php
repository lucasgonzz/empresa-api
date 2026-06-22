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
     * Creación de artículo: los embeddings ya no se generan desde el observer.
     * El scheduler (articles:generate-embeddings cada 30 min) los procesa en lote.
     * Ver GenerateArticleEmbeddings y Kernel.php.
     *
     * @param Article $article Artículo recién creado.
     *
     * @return void
     */
    public function created(Article $article): void
    {
        // Los embeddings se generan por scheduler (articles:generate-embeddings).
        // Ver GenerateArticleEmbeddings y Kernel.php.
    }

    /**
     * Actualización de artículo: los embeddings ya no se regeneran desde el observer.
     * El scheduler detecta artículos modificados comparando updated_at con embedding_generated_at.
     * Ver GenerateArticleEmbeddings y Kernel.php.
     *
     * @param Article $article Artículo recién actualizado.
     *
     * @return void
     */
    public function updated(Article $article): void
    {
        // Los embeddings se generan por scheduler (articles:generate-embeddings).
        // Ver GenerateArticleEmbeddings y Kernel.php.
    }
}
