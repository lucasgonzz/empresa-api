<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProviderOrderDiscountsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('provider_order_discounts', function (Blueprint $table) {
            $table->id();

            $table->string('description')->nullable();
            $table->decimal('percentage', 20,2)->nullable();
            $table->decimal('monto', 20,2)->nullable();
            $table->integer('provider_order_id')->nullable();
            $table->string('temporal_id')->nullable();

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
        Schema::dropIfExists('provider_order_discounts');
    }
}
