<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: crea price_update_run_articles, los artículos que cambiaron de precio en
 * cada corrida.
 *
 * Es tabla de volumen: sin timestamps a propósito.
 *
 * 🔴 El índice único (price_update_run_id, article_id) es lo que hace que un reintento de
 * un chunk no infle el conteo. Se inserta con insertOrIgnore y el total sale de un count,
 * nunca de un contador acumulado: un contador se rompe con el primer job que se reintenta,
 * y el usuario ve un número mayor al real sin que nada falle.
 *
 * Sin foreign keys físicas, siguiendo el estilo del resto del schema.
 */
class CreatePriceUpdateRunArticlesTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('price_update_run_articles')) {
            return;
        }

        Schema::create('price_update_run_articles', function (Blueprint $table) {
            $table->id();
            $table->integer('price_update_run_id')->unsigned()->index();
            $table->integer('article_id')->unsigned();

            $table->unique(
                ['price_update_run_id', 'article_id'],
                'price_update_run_articles_run_article_unique'
            );
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('price_update_run_articles');
    }
}
