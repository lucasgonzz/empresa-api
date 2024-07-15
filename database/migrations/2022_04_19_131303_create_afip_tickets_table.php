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
            $table->string('cuit_negocio')->nullable();
            $table->string('iva_negocio')->nullable();
            $table->string('punto_venta')->nullable();
            $table->string('cbte_numero')->nullable();
            $table->string('cbte_letra')->nullable();
            $table->string('cbte_tipo')->nullable();
            $table->string('importe_total')->nullable();
            $table->string('moneda_id')->nullable();
            $table->string('resultado')->nullable();
            $table->string('concepto')->nullable();
            $table->string('cuit_cliente')->nullable();
            $table->string('iva_cliente')->nullable();
            $table->string('cae')->nullable();
            $table->timestamp('cae_expired_at')->nullable();
            $table->integer('sale_id')->unsigned()->nullable();
            $table->integer('sale_nota_credito_id')->unsigned()->nullable();
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
