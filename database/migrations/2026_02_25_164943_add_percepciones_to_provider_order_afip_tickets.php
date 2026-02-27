<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPercepcionesToProviderOrderAfipTickets extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('provider_order_afip_tickets', function (Blueprint $table) {
            $table->decimal('percepcion_iibb', 22,2)->nullable();
            $table->decimal('percepcion_iva', 22,2)->nullable();
            $table->decimal('retencion_iibb', 22,2)->nullable();
            $table->decimal('retencion_iva', 22,2)->nullable();
            $table->decimal('retencion_ganancias', 22,2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('provider_order_afip_tickets', function (Blueprint $table) {
            //
        });
    }
}
