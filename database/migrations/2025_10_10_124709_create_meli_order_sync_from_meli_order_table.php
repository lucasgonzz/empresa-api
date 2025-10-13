<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMeliOrderSyncFromMeliOrderTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('meli_order_sync_from_meli_order', function (Blueprint $table) {
            $table->id();

            $table->integer('sync_from_meli_order_id');
            $table->integer('meli_order_id');
            $table->string('status')->nullable();
            $table->text('error_code')->nullable();

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
        Schema::dropIfExists('meli_order_sync_from_meli_order');
    }
}
