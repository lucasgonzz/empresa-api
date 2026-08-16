<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tramos por cantidad de una oferta activa: cuando client_offers.tipo_descuento
 * es 'cantidad', el descuento no es un solo número sino N tramos que crecen con
 * la cantidad comprada. La tienda los lee con la segunda query del contrato
 * (ver el docblock de create_client_offers_table).
 *
 * 🔴 LA FORMA DEL TRAMO ES `min`/`max`, NO `modo`/`amount`, y esto se decidió
 * midiendo los dos mecanismos que YA existen en el sistema:
 * - `article_price_ranges` (2025_11_04_123716) usa `modo` ('Igual' / 'Mayor o
 *   igual') + `amount` + un precio unitario ABSOLUTO, y LA TIENDA NO LO LEE:
 *   se aplica solo en la SPA (empresa-spa/src/mixins/vender/article_price_range.js).
 * - `category_price_type_ranges` (2024_12_06_105545:16-30) usa `min`/`max` con
 *   `max` null = sin techo, y LA TIENDA SÍ LO LEE
 *   (tienda-api/app/Http/Controllers/Helpers/ArticleHelper.php:86-128,
 *   set_ranges()).
 * Copiamos la forma que la tienda YA SABE LEER en vez de inventar una tercera.
 * No "unificar" esto con article_price_ranges: son mecanismos distintos y ese
 * cambio rompería el lado que la misión 5 tiene que consumir.
 *
 * Invariantes de los tramos, sostenidos EN CÓDIGO al activar (la base no los
 * puede expresar): 2 o 3 tramos, el primero arranca en min = 1, contiguos y sin
 * huecos (`max` de uno = `min` del siguiente − 1), el último con `max` null, el
 * porcentaje creciente, y NINGÚN tramo por encima del techo del artículo.
 *
 * Sin foreign keys físicas, igual que el resto del schema.
 */
class CreateClientOfferRangesTable extends Migration
{
    /**
     * Crea la tabla, con guard hasTable para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('client_offer_ranges')) {
            return;
        }

        Schema::create('client_offer_ranges', function (Blueprint $table) {

            /* Clave primaria autoincremental */
            $table->id();

            /*
             * Espeja client_offers.id, que es id() -> bigint unsigned. Va
             * unsignedBigInteger y no integer por la misma razón que client_id y
             * article_id en las otras dos tablas: "columna derivada más angosta
             * que su fuente" (APRENDER_NO_PARCHEAR.md).
             */
            $table->unsignedBigInteger('client_offer_id');

            /*
             * El intervalo de cantidad. `max` NULL = SIN TECHO (el último
             * tramo), exactamente como category_price_type_ranges.max: la tienda
             * ya sabe interpretar ese null y cambiarlo por un número grande
             * rompería la lectura del lado de allá.
             */
            $table->integer('min');
            $table->integer('max')->nullable();

            /* El descuento de ese tramo */
            $table->decimal('porcentaje', 6, 2);

            $table->timestamps();

            /*
             * Único índice: la tienda pide los tramos de un puñado de ofertas y
             * los recorre ordenados por `min`, así que este índice cubre el
             * WHERE client_offer_id IN (...) y el ORDER BY r.min ASC de la
             * segunda query del contrato de una sola pasada. Ninguno más: cada
             * índice es un árbol que se actualiza en cada insert.
             */
            $table->index(['client_offer_id', 'min'], 'client_offer_ranges_offer_min_index');
        });
    }

    /**
     * Elimina la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('client_offer_ranges');
    }
}
