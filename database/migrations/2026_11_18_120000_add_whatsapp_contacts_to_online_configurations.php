<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddWhatsappContactsToOnlineConfigurations extends Migration
{
    public function up()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            // Guarda de reintento: si la columna ya existe (vuelta atrás que dejó columnas) no se crea de nuevo.
            if (!Schema::hasColumn('online_configurations', 'whatsapp_consultations_phone')) {
                $table->string('whatsapp_consultations_phone', 15)->nullable();
            }
            if (!Schema::hasColumn('online_configurations', 'whatsapp_seller_phone')) {
                $table->string('whatsapp_seller_phone', 15)->nullable();
            }
        });
    }

    public function down()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_consultations_phone', 'whatsapp_seller_phone']);
        });
    }
}
