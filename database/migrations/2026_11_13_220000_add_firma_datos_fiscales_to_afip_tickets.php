<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFirmaDatosFiscalesToAfipTickets extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('afip_tickets', 'firma_datos_fiscales')) {
            Schema::table('afip_tickets', function (Blueprint $table) {
                $table->string('firma_datos_fiscales', 64)->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('afip_tickets', 'firma_datos_fiscales')) {
            Schema::table('afip_tickets', function (Blueprint $table) {
                $table->dropColumn('firma_datos_fiscales');
            });
        }
    }
}
