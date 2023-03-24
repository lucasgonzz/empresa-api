<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCuponsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('cupons', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount', 8, 2)->nullable();
            $table->decimal('percentage', 8, 2)->nullable();
            $table->decimal('min_amount', 8, 2)->nullable();
            $table->string('code')->nullable();
            $table->dateTime('expiration_date')->nullable();
            $table->integer('expiration_days')->nullable();
            $table->enum('type', ['normal', 'for_new_buyers'])->default('normal');
            $table->integer('buyer_id')->unsigned()->nullable();
            $table->integer('user_id')->unsigned()->nullable();
            $table->boolean('valid')->default(1);
            $table->boolean('read')->default(0);
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
        Schema::dropIfExists('cupons');
    }
}
