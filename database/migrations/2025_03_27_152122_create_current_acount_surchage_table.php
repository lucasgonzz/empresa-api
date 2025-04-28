<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCurrentAcountSurchageTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('current_acount_surchage', function (Blueprint $table) {
            $table->id();
            $table->integer('current_acount_id')->unsigned();
            $table->integer('surchage_id')->unsigned();
            $table->decimal('percentage', 20,2)->nullable();
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
        Schema::dropIfExists('current_acount_surchage');
    }
}
