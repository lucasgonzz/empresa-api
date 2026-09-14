<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columnas del envío cotizado por Zipnova en `carts` y en `orders` (misión zipnova-envios, 14/9/2026).
 *
 * El pedido de la tienda nunca tuvo un lugar para el envío: `orders.total` es SOLO el subtotal de
 * artículos, la dirección es un texto libre (`orders.address`) y el costo de envío se recalculaba
 * cada vez desde `delivery_zones.price`. Con un envío cotizado por un correo hay tres cosas que
 * tienen que quedar clavadas en el momento de la compra, porque después cambian:
 *
 *  - `envio_cotizacion` (json): la cotización completa que hizo el SERVIDOR (código postal,
 *    localidad, provincia, hash de los ítems, todas las opciones). Es la prueba de qué se le
 *    ofreció al comprador y con qué precios.
 *  - `envio_opcion` (json): la opción que eligió (correo, servicio, precio, fecha estimada,
 *    sucursal si es retiro en punto). Es lo que después se usa para crear el envío en Zipnova.
 *  - `envio_destino` (json): destinatario y dirección estructurada (nombre, documento, email,
 *    teléfono, calle, número, piso/depto, localidad, provincia, código postal, referencia, lat/lng).
 *  - `envio_precio` (decimal): lo que paga el comprador por el envío. Es columna aparte, y no una
 *    clave adentro del json, para poder sumarla en SQL y para que la preferencia de Mercado Pago y
 *    el total del mail lean el mismo número.
 *
 * Van en `carts` porque el checkout se arma sobre el carrito y `OrderController@store` copia del
 * carrito al pedido (igual que `delivery_zone_id`, `deliver`, `fecha_entrega`). `orders` suma
 * `envio_proveedor` ('zipnova') para distinguir un envío por correo de una zona propia.
 *
 * Todo nullable y aditivo: un carrito o pedido sin envío por correo sigue exactamente igual.
 * `tienda-api` comparte la base y chequea `Schema::hasColumn('carts', 'envio_opcion')` antes de
 * escribir, porque los dos proyectos no llegan a producción al mismo tiempo.
 */
class AddEnvioToCartsAndOrdersTables extends Migration
{
    /**
     * Tablas que reciben las columnas de envío.
     *
     * @var array<int, string>
     */
    const TABLAS = ['carts', 'orders'];

    /**
     * Agrega las columnas que falten en cada tabla.
     *
     * @return void
     */
    public function up()
    {
        foreach (self::TABLAS as $tabla) {
            if (!Schema::hasTable($tabla)) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                if (!Schema::hasColumn($tabla, 'envio_cotizacion')) {
                    $table->json('envio_cotizacion')->nullable();
                }
                if (!Schema::hasColumn($tabla, 'envio_opcion')) {
                    $table->json('envio_opcion')->nullable();
                }
                if (!Schema::hasColumn($tabla, 'envio_destino')) {
                    $table->json('envio_destino')->nullable();
                }
                if (!Schema::hasColumn($tabla, 'envio_precio')) {
                    $table->decimal('envio_precio', 20, 2)->nullable();
                }
                if ($tabla === 'orders' && !Schema::hasColumn($tabla, 'envio_proveedor')) {
                    $table->string('envio_proveedor', 20)->nullable();
                }
            });
        }
    }

    /**
     * Saca las columnas. Se pierde la cotización y el destino de los pedidos con envío por correo;
     * los pedidos en sí quedan (siguen teniendo `address` en texto).
     *
     * @return void
     */
    public function down()
    {
        foreach (self::TABLAS as $tabla) {
            if (!Schema::hasTable($tabla)) {
                continue;
            }

            $columnas = ['envio_cotizacion', 'envio_opcion', 'envio_destino', 'envio_precio'];
            if ($tabla === 'orders') {
                $columnas[] = 'envio_proveedor';
            }

            $presentes = [];
            foreach ($columnas as $columna) {
                if (Schema::hasColumn($tabla, $columna)) {
                    $presentes[] = $columna;
                }
            }

            if (count($presentes) === 0) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $table) use ($presentes) {
                $table->dropColumn($presentes);
            });
        }
    }
}
