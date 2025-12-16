<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class FixRangosToCompanyPerformanceInfoFacturacionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('company_performance_info_facturacions', function (Blueprint $table) {
            $table->decimal('total_facturado', 35,2)->nullable()->change();
            $table->decimal('total_iva', 35,2)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('company_performance_info_facturacions', function (Blueprint $table) {
            //
        });
    }
}
