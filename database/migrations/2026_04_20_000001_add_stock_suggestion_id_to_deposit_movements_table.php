<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddStockSuggestionIdToDepositMovementsTable extends Migration
{
    public function up()
    {
        Schema::table('deposit_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('stock_suggestion_id')->nullable();
        });
    }

    public function down()
    {
        Schema::table('deposit_movements', function (Blueprint $table) {
            $table->dropColumn('stock_suggestion_id');
        });
    }
}
