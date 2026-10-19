<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dirección de entrega elegida en el checkout, en `carts` y en `orders` (misión
 * formas-envio-direcciones-maps, 19/10/2026).
 *
 * El comprador ahora elige una dirección de `buyer_addresses` (o la carga en el momento, si compra
 * como invitado) buscándola en Google Places. Esa dirección tiene que quedar clavada en el pedido
 * con sus coordenadas, por dos razones:
 *
 *  - el ERP muestra el marcador en un mapa al gestionar el envío, y necesita `lat`/`lng`;
 *  - el comprador puede editar o borrar la dirección después, y el pedido no puede cambiar.
 *
 * Columnas, iguales en las dos tablas:
 *  - `buyer_address_id`: la dirección guardada que eligió (null si compró como invitado o si la
 *    tienda es vieja).
 *  - `envio_direccion` (json): copia de la dirección en el momento de la compra, con las claves
 *    `buyer_address_id`, `formatted_address`, `street`, `street_number`, `apartment`, `city`,
 *    `province`, `zip_code`, `reference`, `lat`, `lng`, `place_id`.
 *
 * Van en `carts` porque el checkout se arma sobre el carrito y `OrderController@store` de la tienda
 * copia del carrito al pedido (igual que `envio_destino`). `orders.address` sigue llevando la línea
 * en texto, para el ERP viejo.
 *
 * Todo nullable y aditivo. `tienda-api` comparte la base y chequea las columnas antes de escribir.
 */
class AddEnvioDireccionToCartsAndOrdersTables extends Migration
{
    /**
     * Tablas que reciben las columnas.
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
                if (!Schema::hasColumn($tabla, 'buyer_address_id')) {
                    $table->unsignedBigInteger('buyer_address_id')->nullable();
                }
                if (!Schema::hasColumn($tabla, 'envio_direccion')) {
                    $table->json('envio_direccion')->nullable();
                }
            });
        }
    }

    /**
     * Saca las columnas. Los pedidos conservan la dirección en texto (`orders.address`).
     *
     * @return void
     */
    public function down()
    {
        foreach (self::TABLAS as $tabla) {
            if (!Schema::hasTable($tabla)) {
                continue;
            }

            $columnas = [];
            foreach (['buyer_address_id', 'envio_direccion'] as $columna) {
                if (Schema::hasColumn($tabla, $columna)) {
                    $columnas[] = $columna;
                }
            }

            if (count($columnas)) {
                Schema::table($tabla, function (Blueprint $table) use ($columnas) {
                    $table->dropColumn($columnas);
                });
            }
        }
    }
}
