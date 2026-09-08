<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddShowClientDescriptionToPdfColumnProfilesTable extends Migration
{
    /**
     * Agrega flag para mostrar u ocultar las observaciones del cliente (clients.description)
     * en el PDF de venta. Default true para mantener compatibilidad con perfiles existentes
     * (hoy se imprimen siempre que el cliente tenga observaciones cargadas).
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('pdf_column_profiles', 'show_client_description')) {
            Schema::table('pdf_column_profiles', function (Blueprint $table) {
                $table->boolean('show_client_description')->default(true)->after('header_layout');
            });
        }
    }

    /**
     * Revierte la columna show_client_description del perfil PDF.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('pdf_column_profiles', 'show_client_description')) {
            Schema::table('pdf_column_profiles', function (Blueprint $table) {
                $table->dropColumn('show_client_description');
            });
        }
    }
}
