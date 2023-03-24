<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCommissionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('commissioner_id')->unsigned();
            $table->bigInteger('sale_id')->unsigned()->nullable();
            $table->integer('page')->nullable();
            $table->integer('percentage')->nullable();
            $table->decimal('monto');
            $table->decimal('saldo', 10, 2)->nullable();
            $table->string('detalle')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->boolean('is_seller')->default(false);
            // $table->foreign('commissioner_id')->references('id')->on('commissioners');
            // $table->foreign('sale_id')->references('id')->on('sales');
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
        Schema::dropIfExists('commissions');
    }
}
