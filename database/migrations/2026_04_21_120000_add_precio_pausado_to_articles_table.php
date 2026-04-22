<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega bandera por artículo para mostrar texto fijo en tienda en lugar del importe.
 */
class AddPrecioPausadoToArticlesTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->boolean('precio_pausado')->default(false)->after('in_offer');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn('precio_pausado');
        });
    }
}
