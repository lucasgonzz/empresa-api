<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Misión mercado-libre-integracion (27/10/2026): `pack_id` y `shipping_id` del pedido de ML.
 *
 * ML asocia todas las órdenes a un `pack_id` (un carrito con varias órdenes comparte el pack y un
 * solo envío). Se guardan para poder agrupar el carrito, consultar el envío y, más adelante, subir
 * la factura al pack (`POST /packs/{id}/fiscal_documents`). Se guardan como texto: los ids de ML
 * ya superan Int32.
 *
 * Aditiva y nullable, sin índices (se consultan por pedido, no se buscan).
 */
class AddPackYShippingToMeliOrdersTable extends Migration
{
    /**
     * Agrega las columnas que falten.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('meli_orders')) {
            return;
        }

        $falta_pack = !Schema::hasColumn('meli_orders', 'pack_id');
        $falta_shipping = !Schema::hasColumn('meli_orders', 'shipping_id');

        if (!$falta_pack && !$falta_shipping) {
            return;
        }

        Schema::table('meli_orders', function (Blueprint $table) use ($falta_pack, $falta_shipping) {
            if ($falta_pack) {
                $table->string('pack_id', 40)->nullable();
            }
            if ($falta_shipping) {
                $table->string('shipping_id', 40)->nullable();
            }
        });
    }

    /**
     * Saca las columnas.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('meli_orders')) {
            return;
        }

        foreach (['pack_id', 'shipping_id'] as $columna) {
            if (Schema::hasColumn('meli_orders', $columna)) {
                Schema::table('meli_orders', function (Blueprint $table) use ($columna) {
                    $table->dropColumn($columna);
                });
            }
        }
    }
}
