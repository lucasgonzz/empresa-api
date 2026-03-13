<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCuotaIdToCurrentAcountCurrentAcountPaymentMethod extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('current_acount_current_acount_payment_method', function (Blueprint $table) {
            $table->integer('cuota_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('current_acount_current_acount_payment_method', function (Blueprint $table) {
            //
        });
    }
}
