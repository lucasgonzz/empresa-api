<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBuyersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('buyers', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->integer('num')->nullable();
            $table->string('name', 128);
            $table->string('avatar')->nullable();
            $table->string('surname', 128)->nullable();
            $table->string('notification_id', 128)->nullable();
            $table->string('city', 128)->nullable();
            // $table->string('address', 128)->nullable();
            // $table->string('address_number', 128)->nullable();
            $table->string('phone', 128)->nullable();
            $table->string('email', 128)->nullable();
            $table->string('verification_code', 128)->nullable();
            $table->string('provider_id', 128)->nullable();
            $table->dateTime('last_login')->nullable();
            $table->boolean('isVerified')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password', 128)->nullable();
            $table->string('remember_token', 128)->nullable();
            $table->integer('comercio_city_client_id')->unsigned()->nullable();
            $table->integer('user_id')->unsigned()->nullable();

            $table->string('visible_password')->nullable();
            $table->integer('seller_id')->unsigned()->nullable();
            $table->text('address')->nullable();

            $table->text('barrio')->nullable();
            $table->text('ciudad')->nullable();

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
        Schema::dropIfExists('buyers');
    }
}
