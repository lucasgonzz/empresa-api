<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSellerCommissionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('seller_commissions', function (Blueprint $table) {
            $table->id();
            $table->integer('num')->nullable();
            $table->integer('sale_id')->unsigned()->nullable();
            $table->integer('seller_id')->unsigned();
            $table->text('description')->nullable();
            $table->decimal('percentage')->nullable();
            $table->decimal('debe', 14,2)->nullable();
            $table->decimal('haber', 14,2)->nullable();
            $table->decimal('saldo', 14,2)->nullable();
            $table->string('status')->default('inactive');
            $table->integer('user_id')->unsigned();
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
        Schema::dropIfExists('seller_commissions');
    }
}
