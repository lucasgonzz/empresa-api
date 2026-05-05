<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remitentes configurables para la etiqueta de envío (datos del negocio en el PDF).
 */
class CreateSaleSenderInfosTable extends Migration
{
    public function up()
    {
        Schema::create('sale_sender_infos', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('user_id');
            $table->index('user_id', 'ssi_user_id_idx');

            $table->string('name', 191);
            $table->string('mail', 191)->nullable();
            $table->string('cuit', 32)->nullable();

            $table->unsignedInteger('provincia_id')->nullable();
            $table->unsignedInteger('location_id')->nullable();

            $table->string('postal_code', 20)->nullable();

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('sale_sender_infos');
    }
}
