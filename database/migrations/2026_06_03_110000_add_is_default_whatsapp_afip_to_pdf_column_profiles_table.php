<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil PDF predeterminado para WhatsApp cuando la venta tiene factura ARCA.
 */
class AddIsDefaultWhatsappAfipToPdfColumnProfilesTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('pdf_column_profiles', function (Blueprint $table) {
            $table->boolean('is_default_whatsapp_afip')->default(false)->after('is_default_whatsapp');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('pdf_column_profiles', function (Blueprint $table) {
            $table->dropColumn('is_default_whatsapp_afip');
        });
    }
}
