<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `articles.embedding_queued_at` (misión embedding-un-solo-job): la marca de
 * "este artículo ya tiene UN job de embedding pendiente en la cola".
 *
 * Para qué sirve: ArticleEmbeddingDispatcher la reclama con un UPDATE atómico antes de
 * despachar GenerateArticleEmbeddingJob, de modo que dos cambios seguidos al mismo artículo
 * (o una pasada del scheduler sobre algo ya encolado) no dejen dos jobs. El job la libera al
 * empezar a correr. NULL = nada pendiente. Una marca más vieja que la vigencia
 * (ArticleEmbeddingDispatcher::QUEUED_MARK_TTL_MINUTES) se considera de un job perdido.
 *
 * Sin índice (el reclamo es por clave primaria), sin foreign key, nullable con default NULL.
 * Guard idempotente para poder re-correrla sin romper nada.
 */
class AddEmbeddingQueuedAtToArticlesTable extends Migration
{
    /**
     * Agrega la columna si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('articles', 'embedding_queued_at')) {
            Schema::table('articles', function (Blueprint $table) {
                $table->timestamp('embedding_queued_at')->nullable();
            });
        }
    }

    /**
     * Revierte la columna si existe.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('articles', 'embedding_queued_at')) {
            Schema::table('articles', function (Blueprint $table) {
                $table->dropColumn('embedding_queued_at');
            });
        }
    }
}
