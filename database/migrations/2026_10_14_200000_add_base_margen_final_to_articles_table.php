<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBaseMargenFinalToArticlesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('articles', function (Blueprint $table) {
            // La misma base que `base_margen` pero expresada en PRECIO DE VENTA: llevada a la
            // magnitud de un precio manual (con el IVA y los impuestos sobre ventas que le
            // correspondan). El front mide con ella el margen que implica un precio que se esta
            // tipeando. Null cuando no hay base, o en articulos calculados antes de esta columna
            // (el front cae a `base_margen` hasta el proximo recalculo).
            //
            // Sin ->after(): en MySQL anterior a 8.0.29 agregar una columna en el medio reconstruye
            // la tabla `articles`, que en algunos clientes es enorme; al final es instantaneo.
            //
            // Misma escala que base_margen (22,6) y por el mismo motivo: se escribe en el mismo
            // save() que el resto del articulo, y desbordarla romperia el guardado entero.
            $table->decimal('base_margen_final', 22, 6)->nullable();
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
            $table->dropColumn('base_margen_final');
        });
    }
}
