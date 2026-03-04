<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProviderOrderAfipTicketIvasTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('provider_order_afip_ticket_ivas', function (Blueprint $table) {
            $table->id();

            $table->integer('provider_order_afip_ticket_id')->nullable();
            $table->integer('iva_id')->nullable();

            $table->decimal('neto', 20, 2)->nullable(0);
            $table->decimal('iva_importe', 20, 2)->nullable(0);
            
            $table->string('temporal_id')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('provider_order_afip_ticket_ivas');
    }
}
