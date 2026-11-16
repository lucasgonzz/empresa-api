<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMedidasEnvioToPromocionesYCombos extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('medidas_envio_paquetes')) {
            Schema::create('medidas_envio_paquetes', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('tipo', 32);
                $table->unsignedBigInteger('paquete_id');
                $table->unique(['tipo', 'paquete_id']);
                foreach (['peso', 'alto', 'ancho', 'profundidad'] as $campo) {
                    $table->decimal($campo, 30, 4)->nullable();
                }
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('medidas_envio_paquetes');
    }
}
