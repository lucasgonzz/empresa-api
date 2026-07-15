<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `sale_factura_print_option` a `users`.
 *
 * Preferencia a nivel dueño de qué PDF abre el botón de imprimir de la factura ARCA
 * (tarjetita en Ventas y en "problemas al facturar"). Vive siempre en el usuario dueño
 * (owner_id null), nunca en un empleado. Valores: null/vacío = ticket común (default),
 * 'factura_ticket_pdf' = ticket común explícito, 'ticket_2' = Ticket 2.0,
 * 'factura_a4:{id}' = perfil PdfColumnProfile fiscal A4 con ese id.
 * Ver UserController@update y BtnFactura.vue (empresa-spa, prompt 415).
 */
class AddSaleFacturaPrintOptionToUsersTable extends Migration
{
    /**
     * Ejecuta la migración: agrega la columna nullable después de `sale_ticket_description`.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            // Preferencia del dueño sobre qué PDF/ticket imprimir por defecto para la factura ARCA.
            $table->string('sale_factura_print_option')->nullable()->after('sale_ticket_description');
        });
    }

    /**
     * Revierte la migración: elimina la columna agregada.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sale_factura_print_option');
        });
    }
}
