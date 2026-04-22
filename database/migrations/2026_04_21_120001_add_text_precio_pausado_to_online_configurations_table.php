<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Texto configurable en tienda cuando un artículo tiene precio pausado.
 */
class AddTextPrecioPausadoToOnlineConfigurationsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            $table->string('text_precio_pausado', 255)->nullable()->after('show_articles_without_images');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            $table->dropColumn('text_precio_pausado');
        });
    }
}
