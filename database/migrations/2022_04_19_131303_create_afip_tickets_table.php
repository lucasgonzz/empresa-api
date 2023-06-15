<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAfipTicketsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('afip_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('cuit_negocio');
            $table->string('iva_negocio');
            $table->string('punto_venta');
            $table->string('cbte_numero');
            $table->string('cbte_letra');
            $table->string('cbte_tipo');
            $table->string('importe_total');
            $table->string('moneda_id');
            $table->string('resultado');
            $table->string('concepto');
            $table->string('cuit_cliente');
            $table->string('iva_cliente');
            $table->string('cae');
            $table->string('cae_expired_at');
            $table->integer('sale_id')->unsigned()->nullable();
            $table->integer('nota_credito_id')->unsigned()->nullable();
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
        Schema::dropIfExists('afip_tickets');
    }
}
