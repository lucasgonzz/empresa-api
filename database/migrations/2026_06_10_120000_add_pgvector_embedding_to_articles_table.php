<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Habilita la extensión pgvector en Postgres y agrega la columna embedding
 * a la tabla articles para búsqueda semántica por similitud de coseno.
 *
 * El índice IVFFLAT acelera las consultas de vecinos más cercanos cuando
 * la tabla tiene muchos registros (decenas de miles de artículos).
 *
 * Nota: no se usa Schema Builder porque el tipo vector() no está soportado
 * nativamente en Laravel 8; se usan sentencias SQL crudas en su lugar.
 */
class AddPgvectorEmbeddingToArticlesTable extends Migration
{
    /**
     * Aplica la migración:
     *   1. Instala la extensión vector si no existe.
     *   2. Agrega la columna embedding (1536 dimensiones, nullable).
     *   3. Crea un índice IVFFLAT para búsqueda por coseno.
     *
     * @return void
     */
    public function up(): void
    {
        // Habilitar la extensión pgvector (idempotente, no falla si ya existe).
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        // Columna nullable: los artículos existentes no tienen embedding todavía.
        // Se rellena progresivamente con el job GenerateArticleEmbeddingJob.
        DB::statement('ALTER TABLE articles ADD COLUMN IF NOT EXISTS embedding vector(1536)');

        // Índice IVFFLAT con 100 listas; apropiado para tablas de ~50.000 filas.
        // vector_cosine_ops: usa distancia de coseno, coherente con text-embedding-3-small.
        DB::statement(
            'CREATE INDEX IF NOT EXISTS articles_embedding_idx '
            . 'ON articles USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100)'
        );
    }

    /**
     * Revierte la migración: elimina el índice y la columna.
     * La extensión vector NO se deshabilita porque puede estar siendo usada
     * por otras tablas o migraciones futuras.
     *
     * @return void
     */
    public function down(): void
    {
        // Eliminar índice antes que la columna (requerido por Postgres).
        DB::statement('DROP INDEX IF EXISTS articles_embedding_idx');

        DB::statement('ALTER TABLE articles DROP COLUMN IF EXISTS embedding');
    }
}
