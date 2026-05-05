<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla 1:1 con sales: overrides opcionales para la etiqueta de envío.
 * La relación lógica con sales se maneja en Eloquent; sin FK en MySQL (convención del proyecto).
 */
class CreateSaleDeliveryInfosTable extends Migration
{
    public function up()
    {
        Schema::create('sale_delivery_infos', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('sale_id');
            $table->unique('sale_id', 'sdi_sale_id_uniq');

            $table->string('first_name', 120)->nullable();
            $table->string('last_name', 120)->nullable();
            $table->string('phone', 80)->nullable();
            $table->string('dni', 32)->nullable();
            $table->string('cuit', 32)->nullable();
            $table->string('locality', 120)->nullable();
            $table->string('province', 120)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('email', 191)->nullable();

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('sale_delivery_infos');
    }
}
