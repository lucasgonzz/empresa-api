<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBarCodeToArticleVariantsTable extends Migration
{
    /**
     * Migración idempotente para agregar la columna bar_code a article_variants.
     *
     * La columna almacena el código de barras de la variante, calculado como '0'+id.
     * Se persiste para unificar búsquedas y escaneo directo en el vender.
     *
     * @return void
     */
    public function up()
    {
        // Solo agrega la columna si no existe (idempotente).
        Schema::table('article_variants', function (Blueprint $table) {
            if (!Schema::hasColumn('article_variants', 'bar_code')) {
                // Agregamos columna string(20) nullable después de variant_description.
                $table->string('bar_code', 20)->nullable()->after('variant_description');

                // Índice sobre bar_code para optimizar búsquedas en el vender.
                $table->index('bar_code');
            }
        });
    }

    /**
     * Revierte la migración: elimina la columna si existe.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('article_variants', function (Blueprint $table) {
            if (Schema::hasColumn('article_variants', 'bar_code')) {
                $table->dropIndex(['bar_code']);
                $table->dropColumn('bar_code');
            }
        });
    }
}
