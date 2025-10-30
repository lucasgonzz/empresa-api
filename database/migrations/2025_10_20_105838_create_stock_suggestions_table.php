<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStockSuggestionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('stock_suggestions', function (Blueprint $table) {
            $table->id();

            $table->string('modo'); // minimo / ideal
            $table->string('origen'); // absoluto / relativo
            $table->string('limite_origen'); // minimo / ideal / sin_limite
            $table->integer('total_chunks')->default(0);
            $table->integer('processed_chunks')->default(0);
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
        Schema::dropIfExists('stock_suggestions');
    }
}
