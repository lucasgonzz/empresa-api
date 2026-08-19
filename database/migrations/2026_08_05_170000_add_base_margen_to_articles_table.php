<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBaseMargenToArticlesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('articles', function (Blueprint $table) {
            // Base sobre la que se aplica el margen del articulo (articles.percentage_gain), o sea
            // el resultado de la cadena costo_real -> margen de usuario -> unidades individuales ->
            // cotizacion -> margen de proveedor -> margen de categoria. NO es costo_real pelado.
            // Con esto el front puede mostrar en vivo el margen que implica un precio cargado a
            // mano, con una division, sin reimplementar la cadena. Null cuando no hay costo.
            //
            // La escala acompana a costo_real (22,6) a proposito, aunque el prompt sugeria (12,4):
            // con (12,4) el tope es 99.999.999,9999 y un articulo en dolares con costo alto lo pasa
            // sin esfuerzo. Y como esta columna se escribe en el mismo save() que el resto del
            // articulo, desbordarla no rompe la feature nueva: rompe el guardado entero del
            // articulo, en la funcion que corre en cada guardado, cada import y cada recalculo
            // masivo. Verificado el 5/8/2026: con (12,4), un articulo de costo 500.000 en dolares a
            // 1200 con 30% de margen tiraba "Out of range value for column 'base_margen'".
            $table->decimal('base_margen', 22, 6)->nullable()->after('costo_real');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn('base_margen');
        });
    }
}
