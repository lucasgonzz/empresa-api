<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `articles.embedding_source_hash` (misión whatsapp-agente): el sha1 del texto
 * que se vectorizó la última vez para ese artículo.
 *
 * 🔴 PARA QUÉ SIRVE, porque no se deduce del nombre:
 * El comando `articles:generate-embeddings` decide qué re-vectorizar con el criterio
 * `updated_at > embedding_generated_at`. Ese criterio es barato de consultar pero
 * grosero: CUALQUIER cambio del artículo mueve `updated_at`, así que una importación
 * de lista de precios que toca 5.000 artículos dispara 5.000 llamadas pagas a OpenAI
 * aunque no haya cambiado una sola palabra del nombre, la categoría, la marca, el
 * código ni la descripción — que es lo único que se vectoriza.
 *
 * Con esta columna, `GenerateArticleEmbeddingJob` compara el hash del texto actual
 * contra el guardado y, si son iguales, corta ANTES de salir a la red: solo refresca
 * `embedding_generated_at` para que el scheduler deje de re-encolar ese artículo.
 * El filtro SQL del comando queda igual a propósito (sigue siendo barato); el corte
 * fino se hace por artículo, donde sí se puede materializar el texto.
 *
 * Guard idempotente para poder re-correrla sin romper nada.
 */
class AddEmbeddingSourceHashToArticlesTable extends Migration
{
    /**
     * Agrega la columna si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('articles', 'embedding_source_hash')) {
            Schema::table('articles', function (Blueprint $table) {
                $table->string('embedding_source_hash', 64)->nullable();
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
        if (Schema::hasColumn('articles', 'embedding_source_hash')) {
            Schema::table('articles', function (Blueprint $table) {
                $table->dropColumn('embedding_source_hash');
            });
        }
    }
}
