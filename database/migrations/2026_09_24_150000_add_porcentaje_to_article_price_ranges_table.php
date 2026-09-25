<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El porcentaje de descuento de una oferta por cantidad (mision oferta-por-cantidad-porcentaje,
 * 24/9/2026).
 *
 * Hasta ahora un tramo de `article_price_ranges` solo sabia fijar un PRECIO ABSOLUTO (`price`).
 * Con esta columna puede, en cambio, descontar un PORCENTAJE sobre el precio que la linea iba a
 * tener — que es lo que hace que la oferta siga al precio del articulo cuando el comercio lo
 * cambia, sin tener que reescribir el tramo.
 *
 * 🔴 SON EXCLUYENTES, y quien lo garantiza es `CriterioDeOfertaPorCantidadHelper`, no esta
 * migracion. Acá la columna nace `nullable` a proposito: las filas que ya existen en las bases de
 * los ~40 clientes quedan con `porcentaje` NULL y siguen funcionando exactamente igual que antes.
 *
 * ⚠️ ADITIVA Y CON DEFAULT NULL, y eso no es estilo: `tienda-api` LEE esta tabla y se despliega a
 * mano, sitio por sitio, mientras el esquema llega por el release de empresa. Entre un despliegue
 * y el otro hay clientes con una punta nueva y la otra vieja. Una tienda vieja no conoce esta
 * columna y el tramo con porcentaje le queda sin valor usable: la linea sale al precio normal.
 * Cobra de mas, nunca de menos, y nada se rompe.
 */
class AddPorcentajeToArticlePriceRangesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('article_price_ranges', function (Blueprint $table) {
            /* 8,2 y no 5,2: deja pasar cualquier porcentaje que alguien tipee sin truncarlo en
               silencio. El criterio de que es un porcentaje VALIDO (> 0 y < 100) lo pone el
               helper, que es un solo lugar; la columna no valida reglas de negocio. */
            $table->decimal('porcentaje', 8, 2)->nullable()->after('price');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('article_price_ranges', function (Blueprint $table) {
            $table->dropColumn('porcentaje');
        });
    }
}
