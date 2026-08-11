<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: agrega la columna image_url a addresses.
 *
 * Objetivo: cada sucursal puede tener su propio logo, que se usa en los
 * comprobantes emitidos desde ella (ventas) o asociados a ella (recibos de pago
 * de sus clientes) en lugar del logo del negocio. Si la sucursal no tiene logo
 * cargado, se sigue usando el del dueño exactamente como antes — la cadena la
 * resuelve AfipPdfHelper::resolve_logo_url(), en un solo lugar.
 *
 * La escribe el endpoint genérico POST set-image/{prop} de ImageController, el
 * mismo que ya administra Brand::image_url: no hay lógica de subida propia.
 *
 * Sin foreign key física, siguiendo el estilo del resto del schema (misma
 * decisión que default_afip_information_id, 16/7/2026).
 */
class AddImageUrlToAddressesTable extends Migration
{
    /**
     * Ejecuta la migración: agrega la columna si todavía no existe (guard
     * hasColumn para que sea segura de re-ejecutar).
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('addresses', 'image_url')) {
            Schema::table('addresses', function (Blueprint $table) {
                /**
                 * Logo propio de la sucursal. Nullable: si es null o vacío, los
                 * comprobantes caen al logo del negocio (users.image_url).
                 */
                $table->string('image_url')->nullable()->after('id');
            });
        }
    }

    /**
     * Revierte la migración: elimina la columna si existe.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('addresses', 'image_url')) {
            Schema::table('addresses', function (Blueprint $table) {
                $table->dropColumn('image_url');
            });
        }
    }
}
