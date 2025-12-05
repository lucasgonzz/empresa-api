<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AfipTicketsFecha extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('afip_tickets', function (Blueprint $table) {
            $table->date('afip_fecha_emision')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('afip_tickets', function (Blueprint $table) {
            $table->dropColumn('afip_fecha_emision');
        });
    }
}
