<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cantidad de contenido del artículo en la unidad indicada por unidad_medida_id (ej. 2.5 litros).
 */
class AddMedidaToArticlesTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->decimal('medida', 12, 4)->nullable()->after('unidad_medida_id');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn('medida');
        });
    }
}
