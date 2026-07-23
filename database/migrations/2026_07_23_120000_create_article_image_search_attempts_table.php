<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea `article_image_search_attempts`: el diagnóstico persistente de la asignación
 * automática de imágenes de artículos (grupo 201, Prompt 01). Hoy ese proceso corre en
 * `ProcessArticleBatchImagesJob` y su resultado solo queda en `storage/logs` y en el evento
 * `ArticleBatchImagesProcessed` de Pusher, que se pierde apenas se cierra el modal. Esta
 * tabla guarda una fila por artículo + criterio de búsqueda probado (un artículo buscado
 * primero por código de barras y después por nombre deja dos filas), para poder mostrar
 * después con qué criterio se buscó, cuántos resultados devolvió Google y por qué se
 * descartó cada candidato.
 *
 * Este prompt solo crea la tabla vacía: no modifica el job (prompt 03 de este grupo
 * escribe en ella) ni el modal del SPA (prompt 04 la lee).
 *
 * Sin foreign keys por convención del proyecto: la relación con `articles` y `users` se
 * resuelve en Eloquent (y de hecho el artículo puede haberse borrado para cuando se
 * consulta el diagnóstico, por eso se guarda un snapshot de su nombre/código de barras).
 *
 * Valores válidos de la columna `outcome` (fila completa, uno de estos):
 * - assigned                 : de este criterio salió la imagen que se asignó.
 * - no_query                 : el artículo no tenía código de barras GS1 válido ni nombre
 *                               (fila única, sin `query`).
 * - no_results                : Google respondió bien pero con cero items.
 * - api_error                 : Google devolvió error o la petición no llegó.
 * - quota                     : no se ejecutó porque se había agotado la cuota diaria.
 * - all_candidates_rejected   : Google devolvió items pero ninguno se pudo usar (el motivo
 *                               de cada uno está en `candidates`).
 *
 * Estructura de cada elemento del array `candidates` (json), en el orden en que Google los
 * devolvió:
 * {
 *   "position": 1,
 *   "image_url": "https://...",
 *   "thumbnail_url": "https://encrypted-tbn0.gstatic.com/...",
 *   "context_url": "https://...",
 *   "title": "...",
 *   "width": 800,
 *   "height": 800,
 *   "outcome": "assigned" | "download_failed" | "not_processable" | "rejected_by_prefilter"
 *              | "rejected_by_ai" | "not_evaluated",
 *   "reason": "frase corta en castellano, ya lista para mostrar",
 *   "http_status": 403,          // opcional, solo si aplica
 *   "used_thumbnail": false,     // opcional, solo si aplica
 *   "ai_type": "catalogo_o_lista_de_precios", // opcional, solo si aplica
 *   "ai_confidence": "high"      // opcional, solo si aplica
 * }
 */
class CreateArticleImageSearchAttemptsTable extends Migration
{
    /**
     * Crea la tabla si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasTable('article_image_search_attempts')) {
            Schema::create('article_image_search_attempts', function (Blueprint $table) {
                $table->id();

                // Multi-tenant: toda consulta sobre esta tabla filtra por user_id.
                $table->unsignedBigInteger('user_id');
                $table->index('user_id', 'aisa_user_id_idx');

                // Agrupa todas las filas generadas por una misma corrida del job de asignación masiva.
                $table->string('batch_uuid', 40);
                $table->index('batch_uuid', 'aisa_batch_uuid_idx');

                // Artículo sobre el que se intentó la búsqueda.
                $table->unsignedBigInteger('article_id');
                $table->index('article_id', 'aisa_article_id_idx');

                // Snapshot del nombre y código de barras del artículo al momento de la corrida
                // (el artículo puede haberse borrado o modificado para cuando se consulta el diagnóstico).
                $table->string('article_name', 255)->nullable();
                $table->string('article_bar_code', 100)->nullable();

                // Criterio probado en esta fila: 'bar_code' o 'name'.
                $table->string('criterion', 20);

                // Orden en que se probó este criterio para el artículo: 1 = primero, 2 = segundo.
                $table->unsignedTinyInteger('criterion_order');

                // Texto exacto enviado a Google Custom Search.
                $table->string('query', 255);

                // Cantidad de items devueltos por Google en la respuesta.
                $table->integer('google_items_count')->nullable();

                // searchInformation.totalResults de la respuesta de Google.
                $table->integer('google_total_results')->nullable();

                // Mensaje de error de Google si la petición falló.
                $table->text('api_error')->nullable();

                // Resultado final de este criterio de búsqueda (ver enum documentado arriba).
                $table->string('outcome', 40);

                // Frase corta en castellano, lista para mostrar en pantalla, explicando el outcome.
                $table->text('outcome_detail')->nullable();

                // Detalle por imagen candidata devuelta por Google (ver estructura documentada arriba).
                $table->json('candidates')->nullable();

                $table->timestamps();

                // Índice compuesto para listar/agrupar las filas de una misma corrida por usuario.
                $table->index(['user_id', 'batch_uuid'], 'aisa_user_batch_idx');
            });
        }
    }

    /**
     * Elimina la tabla si existe (rollback completo).
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('article_image_search_attempts');
    }
}
