<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Formas de envío a domicilio de la tienda online (misión formas-envio-direcciones-maps,
 * 19/10/2026).
 *
 * El comercio ofrece envío a domicilio (`online_configurations.has_delivery`) y elige con qué
 * formas:
 *
 *  - Por correo con Zipnova: el conector ya existía; ahora se puede dejar de ofrecer sin
 *    desconectarlo. `online_configurations.ofrecer_envio_zipnova`, default 1, para que un comercio
 *    que ya lo tiene conectado siga exactamente igual.
 *  - Formas propias: son las `delivery_zones` de siempre (nombre, descripción, precio). Se les suma
 *    `costo_a_convenir`: una forma como "Acordar con el vendedor" no le cobra nada online al
 *    comprador y el vendedor coordina la entrega (y el costo, si lo hay) cuando gestiona el pedido.
 *    Con la bandera prendida el precio se ignora en todos los totales.
 *
 * Aditivas y con default: una tienda vieja no lee las columnas nuevas y sigue andando.
 */
class AddFormasDeEnvioColumns extends Migration
{
    /**
     * Agrega las dos columnas si faltan.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('delivery_zones') && !Schema::hasColumn('delivery_zones', 'costo_a_convenir')) {
            Schema::table('delivery_zones', function (Blueprint $table) {
                $table->boolean('costo_a_convenir')->default(false);
            });
        }

        if (Schema::hasTable('online_configurations') && !Schema::hasColumn('online_configurations', 'ofrecer_envio_zipnova')) {
            Schema::table('online_configurations', function (Blueprint $table) {
                $table->boolean('ofrecer_envio_zipnova')->default(true)->nullable();
            });
        }
    }

    /**
     * Saca las dos columnas.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasTable('delivery_zones') && Schema::hasColumn('delivery_zones', 'costo_a_convenir')) {
            Schema::table('delivery_zones', function (Blueprint $table) {
                $table->dropColumn('costo_a_convenir');
            });
        }

        if (Schema::hasTable('online_configurations') && Schema::hasColumn('online_configurations', 'ofrecer_envio_zipnova')) {
            Schema::table('online_configurations', function (Blueprint $table) {
                $table->dropColumn('ofrecer_envio_zipnova');
            });
        }
    }
}
