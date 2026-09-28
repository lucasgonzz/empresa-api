<?php

namespace App\Console\Commands;

use App\Services\ArticleEmbeddingService;
use Illuminate\Console\Command;

/**
 * Adelanta el relleno de `article_compact_embeddings` (misión rag-whatsapp-memoria-acotada,
 * 28/9/2026).
 *
 * La búsqueda semántica del agente de WhatsApp compara contra un vector compacto de cada artículo
 * (512 dims normalizadas, float32 binario) en vez del JSON de 1536 floats. Ese compacto se arma
 * solo: lo escribe `ArticleEmbeddingService::persistir_embedding()` cada vez que se indexa un
 * artículo, y la propia búsqueda compacta al vuelo los que falten (backfill perezoso). Este comando
 * hace ese mismo backfill de una, para que el primer mensaje de cada cliente después del deploy no
 * pague el costo de compactar todo el catálogo.
 *
 * - Es OPCIONAL: sin correrlo todo funciona igual, solo que la primera búsqueda de cada dueño es
 *   más lenta.
 * - No se agenda en el scheduler: después de la primera corrida no queda nada que hacer, porque
 *   los artículos nuevos o re-indexados ya nacen con su compacto.
 * - NO llama a OpenAI: re-empaqueta el vector que ya está en `articles.embedding`. Por eso no lo
 *   frena `EMBEDDINGS_GENERACION_PAUSADA` ni el gate de la extensión `whatsapp_ia`, y no cuesta
 *   plata.
 * - Es idempotente: solo toca los artículos que todavía no tienen fila compacta.
 *
 * Uso:
 *   php artisan articles:compactar-embeddings              # toda la base
 *   php artisan articles:compactar-embeddings --user_id=12 # solo los artículos de un dueño
 */
class CompactarEmbeddingsDeArticulos extends Command
{
    /**
     * Nombre y firma del comando Artisan.
     *
     * @var string
     */
    protected $signature = 'articles:compactar-embeddings
                            {--user_id= : Solo los artículos de este dueño (sin la opción: toda la base)}';

    /**
     * Descripción que aparece en php artisan list.
     *
     * @var string
     */
    protected $description = 'Arma el vector compacto de la búsqueda semántica para los artículos que todavía no lo tienen (no llama a OpenAI)';

    /**
     * Artículos por tanda. Cada uno trae su JSON de ~28 KB, así que 200 son ~6 MB por tanda.
     */
    const TANDA = 200;

    /**
     * Ejecuta el comando: delega el recorrido en el servicio e informa cuántos compactó.
     *
     * @param ArticleEmbeddingService $service Inyectado por el contenedor.
     *
     * @return int Código de salida (0 = éxito, 1 = --user_id inválido).
     */
    public function handle(ArticleEmbeddingService $service): int
    {
        // Filtro opcional por dueño; vacío = toda la base.
        $user_id = $this->option('user_id');

        if (! is_null($user_id) && $user_id !== '' && ! ctype_digit((string) $user_id)) {
            $this->error('articles:compactar-embeddings: --user_id tiene que ser un número.');
            return 1;
        }

        $user_id = ($user_id === null || $user_id === '') ? null : (int) $user_id;

        $totales = $service->compactar_pendientes($user_id, self::TANDA);

        $this->info(
            'articles:compactar-embeddings: '.$totales['compactados'].' artículo(s) compactado(s)'
            .($totales['salteados'] > 0 ? ', '.$totales['salteados'].' salteado(s) por vector inválido o vacío' : '')
            .(is_null($user_id) ? '.' : ' (user_id '.$user_id.').')
        );

        return 0;
    }
}
