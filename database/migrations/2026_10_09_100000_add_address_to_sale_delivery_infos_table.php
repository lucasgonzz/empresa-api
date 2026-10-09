<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: la DIRECCIÓN del destinatario (calle y número) en los datos de envío de la etiqueta
 * (misión etiqueta-envio-direccion, 9/10/2026).
 *
 * Hasta hoy `sale_delivery_infos` guardaba nombre, teléfono, documento, localidad, provincia, CP y
 * mail, pero no la calle: la etiqueta de envío salía sin la dirección del destinatario. Con esta
 * columna el modal "Datos de envío (etiqueta)" la puede cargar o corregir; si queda en NULL (o
 * vacía), la etiqueta usa el domicilio del cliente (`clients.address`).
 *
 * NULL por defecto y sin FK: ninguna fila existente cambia. La base es compartida con `tienda`,
 * pero `tienda-api` no lee `sale_delivery_infos` (verificado en el plan de la misión).
 *
 * Guarda `hasColumn`: hay ~40 bases de clientes en estados de esquema distintos y una que ya tenga
 * la columna no puede tumbar la migración.
 */
class AddAddressToSaleDeliveryInfosTable extends Migration
{
    /**
     * Agrega la columna si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('sale_delivery_infos', 'address')) {
            return;
        }

        Schema::table('sale_delivery_infos', function (Blueprint $table) {
            // Calle y número del destinatario; NULL = usar el domicilio del cliente.
            // TEXT, igual que `clients.address`: el modal precarga el domicilio del cliente y lo
            // manda siempre, así que con un varchar(255) un domicilio largo daría "Data too long"
            // (MySQL estricto) al guardar el modal aunque solo se haya cambiado el teléfono.
            $table->text('address')->nullable()->default(null);
        });
    }

    /**
     * Saca la columna si existe.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasColumn('sale_delivery_infos', 'address')) {
            return;
        }

        Schema::table('sale_delivery_infos', function (Blueprint $table) {
            $table->dropColumn('address');
        });
    }
}
