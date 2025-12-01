<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCompanyPerformanceInfoFacturacionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('company_performance_info_facturacions', function (Blueprint $table) {
            $table->id();

            $table->integer('company_performance_id');
            $table->integer('afip_information_id');
            $table->integer('afip_tipo_comprobante_id');
            $table->decimal('total_facturado')->nullable();
            $table->decimal('total_iva')->nullable();

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
        Schema::dropIfExists('company_performance_info_facturacions');
    }
}
