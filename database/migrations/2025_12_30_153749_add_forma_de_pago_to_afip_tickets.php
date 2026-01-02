<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFormaDePagoToAfipTickets extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('afip_tickets', function (Blueprint $table) {
            $table->text('forma_de_pago')->nullable();
            $table->text('permiso_existente')->nullable();
            $table->string('moneda')->nullable();
            $table->decimal('moneda_cotizacion', 20,2)->nullable();
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
            //
        });
    }
}
