<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Direcciones de entrega del comprador de la tienda online (misión formas-envio-direcciones-maps,
 * 19/10/2026).
 *
 * Hasta acá el comprador escribía su dirección en campos de texto libre en cada compra y no quedaba
 * guardada en ningún lado (salvo el `buyers.address` del registro). Ahora la busca con el
 * autocompletado de Google Places, la elige de una lista y puede tener varias guardadas: al
 * finalizar el pedido elige una o agrega otra.
 *
 * Tabla nueva y no `addresses`: `addresses` es la tabla de sucursales y depósitos del ERP; mezclar
 * ahí los domicilios de los compradores obliga a filtrar `buyer_id` en cada consulta de sucursales
 * (ya pasó: `AddressController@index` no lo filtra).
 *
 * Columnas:
 *  - `user_id`: el comercio (dueño), como en el resto de las tablas de la tienda.
 *  - `buyer_id`: el comprador dueño de la dirección.
 *  - `street`, `street_number`, `apartment` (piso/depto), `city` (localidad), `province`,
 *    `zip_code`, `reference` (entre calles, timbre, etc.).
 *  - `formatted_address`: la línea que devolvió Google, tal cual se le mostró al comprador.
 *  - `lat`, `lng`: coordenadas del lugar elegido (el mapa del ERP las usa para el marcador).
 *  - `place_id`: id del lugar en Google, para poder volver a consultarlo.
 *  - `is_default`: la dirección que se preselecciona en el checkout.
 *
 * Sin foreign keys y con todo nullable salvo los ids: `tienda-api` comparte la base y no corre
 * migraciones; chequea `Schema::hasTable('buyer_addresses')` antes de usarla.
 */
class CreateBuyerAddressesTable extends Migration
{
    /**
     * Crea la tabla si no existe.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('buyer_addresses')) {
            return;
        }

        Schema::create('buyer_addresses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('buyer_id');
            $table->string('street', 160)->nullable();
            $table->string('street_number', 20)->nullable();
            $table->string('apartment', 60)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('province', 120)->nullable();
            $table->string('zip_code', 20)->nullable();
            $table->string('reference', 255)->nullable();
            $table->string('formatted_address', 255)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('place_id', 255)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index('buyer_id', 'buyer_addr_buyer_idx');
            $table->index('user_id', 'buyer_addr_user_idx');
        });
    }

    /**
     * Borra la tabla. Los pedidos ya hechos no pierden la dirección: cada uno guarda su copia en
     * `orders.envio_direccion` y la línea en texto en `orders.address`.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('buyer_addresses');
    }
}
