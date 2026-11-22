<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddUpdateProviderExplicitToArticleProviderOrder extends Migration
{
    public function up()
    {
        Schema::table('article_provider_order', function (Blueprint $table) {
            // Guarda de reintento: si la columna ya existe (vuelta atrás que dejó columnas) no se crea de nuevo.
            if (!Schema::hasColumn('article_provider_order', 'update_provider_explicit')) {
                $table->boolean('update_provider_explicit')->default(false);
            }
        });
    }

    public function down()
    {
        Schema::table('article_provider_order', function (Blueprint $table) {
            $table->dropColumn('update_provider_explicit');
        });
    }
}
