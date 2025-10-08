<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMeliOrderCancelDetailsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('meli_order_cancel_details', function (Blueprint $table) {
            $table->id();

            $table->integer('meli_order_id');
            $table->string('group')->nullable();
            $table->string('code')->nullable();
            $table->string('description')->nullable();
            $table->string('requested_by')->nullable();
            $table->timestamp('date')->nullable();
            $table->string('application_id')->nullable();

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
        Schema::dropIfExists('meli_order_cancel_details');
    }
}
