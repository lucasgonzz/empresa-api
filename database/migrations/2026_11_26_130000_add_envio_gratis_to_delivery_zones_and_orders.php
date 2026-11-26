<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Envío gratis de las formas de envío propias (misión truvari-carrito-entrega-pago, 26/11/2026).
 *
 * Contrato entre empresa-api y tienda-api (la base es compartida; sólo empresa-api migra):
 *
 *  - `delivery_zones.envio_gratis_desde`: monto de compra desde el que el envío de la forma es gratis
 *    (null = nunca gratis por monto).
 *  - `delivery_zones.envio_gratis_con_combo`: si el pedido con un combo o promoción también sale gratis.
 *  - `orders.envio_zona_precio`: foto de lo que se cobró de envío por la forma propia (null = pedido sin
 *    foto, se lee el precio vivo de la forma).
 *  - `orders.envio_zona_gratis_motivo`: `monto` | `combo` | null.
 *
 * Idempotente (cada columna se agrega sólo si falta, porque la base la comparten dos frentes y un
 * reintento del migrador no puede fallar), sin foreign keys ni índices, y con default en todo: ninguna
 * columna se vuelve obligatoria de golpe, así que el código viejo sigue andando.
 */
class AddEnvioGratisToDeliveryZonesAndOrders extends Migration
{
    /**
     * Agrega las cuatro columnas que todavía no existan.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('delivery_zones')) {
            if (!Schema::hasColumn('delivery_zones', 'envio_gratis_desde')) {
                Schema::table('delivery_zones', function (Blueprint $table) {
                    // Monto de compra mínimo para envío gratis; null = nunca gratis por monto.
                    $table->decimal('envio_gratis_desde', 20, 2)->nullable();
                });
            }
            if (!Schema::hasColumn('delivery_zones', 'envio_gratis_con_combo')) {
                Schema::table('delivery_zones', function (Blueprint $table) {
                    // Envío gratis si el pedido tiene un combo o una promoción.
                    $table->boolean('envio_gratis_con_combo')->default(false);
                });
            }
        }

        if (Schema::hasTable('orders')) {
            if (!Schema::hasColumn('orders', 'envio_zona_precio')) {
                Schema::table('orders', function (Blueprint $table) {
                    // Foto del envío cobrado por la forma propia; null = pedido sin foto.
                    $table->decimal('envio_zona_precio', 20, 2)->nullable();
                });
            }
            if (!Schema::hasColumn('orders', 'envio_zona_gratis_motivo')) {
                Schema::table('orders', function (Blueprint $table) {
                    // Por qué fue gratis: monto | combo | null.
                    $table->string('envio_zona_gratis_motivo', 20)->nullable();
                });
            }
        }
    }

    /**
     * Saca las cuatro columnas si existen.
     *
     * @return void
     */
    public function down()
    {
        $columnas = [
            'delivery_zones' => ['envio_gratis_desde', 'envio_gratis_con_combo'],
            'orders'         => ['envio_zona_precio', 'envio_zona_gratis_motivo'],
        ];

        foreach ($columnas as $tabla => $nombres) {
            if (!Schema::hasTable($tabla)) {
                continue;
            }
            foreach ($nombres as $nombre) {
                if (Schema::hasColumn($tabla, $nombre)) {
                    Schema::table($tabla, function (Blueprint $table) use ($nombre) {
                        $table->dropColumn($nombre);
                    });
                }
            }
        }
    }
}
