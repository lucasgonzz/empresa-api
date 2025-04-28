<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateClientsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->integer('num')->nullable();
            $table->string('name', 128);
            $table->string('email', 128)->nullable();
            $table->string('phone', 128)->nullable();
            $table->text('address')->nullable();
            $table->string('cuil', 128)->nullable();
            $table->string('cuit', 128)->nullable();
            $table->string('dni', 128)->nullable();
            $table->string('razon_social', 128)->nullable();
            $table->integer('iva_condition_id')->unsigned()->nullable();
            $table->integer('price_type_id')->unsigned()->nullable();
            $table->integer('location_id')->unsigned()->nullable();
            $table->text('description')->nullable();
            $table->decimal('saldo', 12,2)->nullable();
            $table->integer('comercio_city_user_id')->unsigned()->nullable();
            $table->integer('user_id')->unsigned();
            $table->bigInteger('seller_id')->nullable()->unsigned();
            $table->boolean('pagos_checkeados')->default(0);
            $table->string('status')->default('active');
            $table->boolean('pasar_ventas_a_la_cuenta_corriente_sin_esperar_a_facturar')->default(0);
            $table->integer('address_id')->nullable();
            $table->softDeletes();

            $table->text('link_google_maps')->nullable();


            $table->foreign('user_id')
                    ->references('id')->on('users');
            // $table->foreign('seller_id')
            //         ->references('id')->on('sellers');

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
        Schema::dropIfExists('clients');
    }
}
