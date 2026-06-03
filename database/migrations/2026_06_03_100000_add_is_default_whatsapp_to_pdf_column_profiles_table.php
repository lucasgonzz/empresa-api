<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca un perfil PDF como predeterminado para enlaces enviados por WhatsApp.
 */
class AddIsDefaultWhatsappToPdfColumnProfilesTable extends Migration
{
    /**
     * Agrega columna booleana sin FK (convención del proyecto).
     *
     * @return void
     */
    public function up()
    {
        Schema::table('pdf_column_profiles', function (Blueprint $table) {
            $table->boolean('is_default_whatsapp')->default(false)->after('is_default');
        });
    }

    /**
     * Revierte la columna agregada.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('pdf_column_profiles', function (Blueprint $table) {
            $table->dropColumn('is_default_whatsapp');
        });
    }
}
