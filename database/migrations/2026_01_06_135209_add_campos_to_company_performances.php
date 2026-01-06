<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCamposToCompanyPerformances extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('company_performances', function (Blueprint $table) {
            $table->decimal('ingresos_brutos', 33,2)->nullable();
            $table->decimal('ingresos_brutos_usd', 33,2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('company_performances', function (Blueprint $table) {
            //
        });
    }
}
