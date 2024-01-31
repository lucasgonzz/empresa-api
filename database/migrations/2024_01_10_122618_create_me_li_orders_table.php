<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMeLiOrdersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('me_li_orders', function (Blueprint $table) {
            $table->id();
            $table->string('me_li_order_id')->nullable();
            $table->string('status')->nullable();
            $table->text('status_detail')->nullable();
            $table->string('date_created')->nullable();
            $table->string('date_closed')->nullable();
            $table->decimal('total', 12,2)->nullable();
            $table->integer('me_li_buyer_id')->nullable();
            $table->integer('user_id')->nullable();
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
        Schema::dropIfExists('me_li_orders');
    }
}
