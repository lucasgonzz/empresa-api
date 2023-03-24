<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAfipInformationTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('afip_information', function (Blueprint $table) {
            $table->id();
            $table->integer('iva_condition_id')->unsigned()->nullable();
            $table->string('razon_social')->nullable();
            $table->string('domicilio_comercial')->nullable();
            $table->string('cuit')->nullable();
            $table->string('ingresos_brutos')->nullable();
            $table->timestamp('inicio_actividades')->nullable();
            $table->integer('punto_venta')->nullable();
            $table->boolean('afip_ticket_production')->default(0);
            $table->integer('user_id')->unsigned();
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
        Schema::dropIfExists('afip_information');
    }
}
