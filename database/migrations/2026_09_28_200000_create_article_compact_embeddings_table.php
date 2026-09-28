<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vector compacto de cada artículo para la búsqueda semántica del agente de WhatsApp (misión
 * rag-whatsapp-memoria-acotada, 28/9/2026).
 *
 * El problema que resuelve: en MySQL (toda la flota) la búsqueda del RAG traía TODOS los
 * `articles.embedding` del dueño a memoria en cada mensaje —JSON de 1536 floats, ~28 KB por
 * artículo— y en catálogos grandes el proceso moría por memoria (demo3, 28/9: `Allowed memory
 * size of 805306368 bytes exhausted` en cada "Sugerir respuesta"). Acá se guarda, por artículo,
 * una versión chica del mismo vector: los primeros 512 valores de `text-embedding-3-small`
 * normalizados a norma 1 y empaquetados como float32 binario (2048 bytes). La arma y la lee
 * `ArticleEmbeddingService`; ahí está explicado por qué truncar no pierde el concepto.
 *
 * 🔴 POR QUÉ UNA TABLA APARTE Y NO UNA COLUMNA EN `articles`: `articles` es base COMPARTIDA con
 * `tienda-api`. Una columna binaria nueva ahí se cuela en cualquier `SELECT *` que termine en
 * `json_encode` (empresa la podría esconder con `$hidden`, pero tienda-api y cualquier
 * `DB::table('articles')->get()` no la conocen): bytes que no son UTF-8 → `Malformed UTF-8
 * characters` → la respuesta entera se cae, en otro repo. En una tabla aparte el contrato de la
 * base compartida no cambia y ningún listado se entera. Y recorrer una tabla angosta es más rápido
 * que recorrer una ancha.
 *
 * - `article_id` es la clave primaria: una fila por artículo, y es la columna por la que se hace el
 *   JOIN y el upsert.
 * - `user_id` con índice: permite recorrer o limpiar lo de un dueño sin pasar por `articles`.
 * - `vector` BLOB NOT NULL: una fila sin vector no tiene sentido; el servicio borra la fila en vez
 *   de dejarla vacía.
 * - Sin foreign keys (regla del repo) y sin timestamps (nadie los lee; la fila se reescribe entera
 *   cada vez que cambia el vector del artículo).
 *
 * 🔴 SIN BACKFILL ACÁ, a propósito: esta migración la corre `DeploymentService` en cada cliente, y
 * compactar 15.000 artículos × 28 KB adentro del despliegue lo alarga sin necesidad. El relleno es
 * perezoso (lo hace la primera búsqueda de cada dueño) y hay un comando opcional para adelantarlo:
 * `php artisan articles:compactar-embeddings`.
 */
class CreateArticleCompactEmbeddingsTable extends Migration
{
    /**
     * Crea la tabla, con guard para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('article_compact_embeddings')) {
            return;
        }

        Schema::create('article_compact_embeddings', function (Blueprint $table) {
            /* Artículo al que pertenece el vector. Una fila por artículo. */
            $table->unsignedBigInteger('article_id')->primary();

            /* Dueño del artículo, copiado de articles.user_id al compactar. */
            $table->unsignedBigInteger('user_id');
            $table->index('user_id', 'ace_user_idx');

            /* 512 floats float32 little-endian (pack('g*')), vector de norma 1: 2048 bytes. */
            $table->binary('vector');
        });
    }

    /**
     * Elimina la tabla si existe.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('article_compact_embeddings');
    }
}
