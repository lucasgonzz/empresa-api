<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la columna embedding a articles para búsqueda semántica.
 *
 * - PostgreSQL: extensión pgvector, tipo vector(1536) e índice IVFFLAT.
 * - MySQL (WAMP local): columna JSON nullable; la similitud se calcula en PHP.
 */
class AddPgvectorEmbeddingToArticlesTable extends Migration
{
    /**
     * Aplica la migración según el driver de base de datos configurado.
     *
     * @return void
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $this->up_postgresql();

            return;
        }

        if ($driver === 'mysql') {
            $this->up_mysql();

            return;
        }

        throw new \RuntimeException(
            'AddPgvectorEmbeddingToArticlesTable: driver no soportado ('.$driver.'). Usá pgsql o mysql.'
        );
    }

    /**
     * PostgreSQL: pgvector + índice IVFFLAT para búsqueda por coseno.
     *
     * @return void
     */
    private function up_postgresql(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        DB::statement('ALTER TABLE articles ADD COLUMN IF NOT EXISTS embedding vector(1536)');

        DB::statement(
            'CREATE INDEX IF NOT EXISTS articles_embedding_idx '
            . 'ON articles USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100)'
        );
    }

    /**
     * MySQL: columna JSON para almacenar el array de 1536 floats en desarrollo local.
     *
     * @return void
     */
    private function up_mysql(): void
    {
        if (! Schema::hasColumn('articles', 'embedding')) {
            Schema::table('articles', function (Blueprint $table) {
                $table->json('embedding')->nullable();
            });
        }
    }

    /**
     * Revierte la migración según el driver activo.
     *
     * @return void
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS articles_embedding_idx');
            DB::statement('ALTER TABLE articles DROP COLUMN IF EXISTS embedding');

            return;
        }

        if ($driver === 'mysql' && Schema::hasColumn('articles', 'embedding')) {
            Schema::table('articles', function (Blueprint $table) {
                $table->dropColumn('embedding');
            });
        }
    }
}
