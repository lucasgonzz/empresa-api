<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateVentaTerminadaCommissionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {

        /* 
            Esta tabla la uso para las comisiones que deben liquidarse cuando una venta 
            se marca como terminada.
            En el caso de TruvariBebidas, eso significa que una venta fue ENTREGADA, por lo tanto se usa para las comisiones de los vendedores
        */
        Schema::create('venta_terminada_commissions', function (Blueprint $table) {
            $table->id();

            $table->decimal('monto_fijo', 22,2);
            $table->integer('seller_id');
            $table->integer('user_id');

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
        Schema::dropIfExists('venta_terminada_commissions');
    }
}
