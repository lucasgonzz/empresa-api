<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProvidersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('providers', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->integer('num')->nullable();
            $table->string('name', 128);
            $table->string('phone', 128)->nullable();
            $table->text('address')->nullable();
            $table->string('email', 128)->nullable();
            $table->string('razon_social', 128)->nullable();
            $table->string('cuit', 128)->nullable();
            $table->text('observations')->nullable();
            $table->integer('location_id')->unsigned()->default(0);
            $table->integer('iva_condition_id')->unsigned()->default(0);
            $table->decimal('percentage_gain', 8,2)->nullable();
            $table->decimal('dolar', 14,2)->nullable();
            $table->decimal('saldo', 12,2)->nullable();
            $table->integer('comercio_city_user_id')->unsigned()->nullable();
            $table->integer('user_id')->unsigned();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->softDeletes();

            $table->foreign('user_id')
                    ->references('id')->on('users');

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
        Schema::dropIfExists('providers');
    }
}
