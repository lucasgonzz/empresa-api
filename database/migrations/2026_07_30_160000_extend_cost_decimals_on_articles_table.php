<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Amplía la escala de cost, costo_real y costo_mano_de_obra en articles de 2 a 6
 * decimales, para que la importación de Excel pueda guardar los decimales que ya
 * interpreta (ProcessRow::$numeric_precision los declara con 6 decimales desde
 * hace tiempo, pero la columna real seguía en decimal(22,2) desde la migración
 * original de 2019 y los truncaba en silencio).
 *
 * La precisión total se mantiene en 22 dígitos: la parte entera admitida baja de
 * 20 a 16 dígitos. Ver el prompt 02 del grupo 282, que ajusta en consecuencia la
 * validación de rango del importador.
 */
class ExtendCostDecimalsOnArticlesTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->decimal('cost', 22, 6)->nullable()->change();
            $table->decimal('costo_real', 22, 6)->nullable()->change();
            $table->decimal('costo_mano_de_obra', 22, 6)->nullable()->change();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->decimal('cost', 22, 2)->nullable()->change();
            $table->decimal('costo_real', 22, 2)->nullable()->change();
            $table->decimal('costo_mano_de_obra', 22, 2)->nullable()->change();
        });
    }
}
