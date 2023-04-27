<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProviderPriceListsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('provider_price_lists', function (Blueprint $table) {
            $table->id();
            $table->integer('num')->nullable();
            $table->string('name')->nullable();
            $table->decimal('percentage', 10,2)->nullable();
            $table->integer('provider_id')->unsigned()->nullable();
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
        Schema::dropIfExists('provider_price_lists');
    }
}
