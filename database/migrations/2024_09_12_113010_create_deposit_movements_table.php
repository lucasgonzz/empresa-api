<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDepositMovementsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('deposit_movements', function (Blueprint $table) {
            $table->id();
            $table->integer('num');

            $table->text('notes')->nullable();

            $table->integer('from_address_id');
            $table->integer('to_address_id');

            $table->integer('deposit_movement_status_id');

            $table->integer('employee_id')->nullable();

            $table->integer('user_id');

            $table->timestamp('recibido_at')->nullable();

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
        Schema::dropIfExists('deposit_movements');
    }
}
