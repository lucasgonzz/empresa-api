<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddEmbeddingsTokensUsadosToPruebaConfig extends Migration
{
    public function up()
    {
        Schema::table('prueba_config', function (Blueprint $table) {
            // Guarda de reintento: si la columna ya existe (vuelta atrás que dejó columnas) no se crea de nuevo.
            if (!Schema::hasColumn('prueba_config', 'embeddings_tokens_usados')) {
                $table->unsignedBigInteger('embeddings_tokens_usados')->nullable();
            }
        });
    }

    public function down()
    {
        Schema::table('prueba_config', function (Blueprint $table) {
            $table->dropColumn('embeddings_tokens_usados');
        });
    }
}
