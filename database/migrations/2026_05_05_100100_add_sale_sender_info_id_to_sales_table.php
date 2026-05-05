<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remitente por defecto recordado para la etiqueta de envío (modal de selección).
 */
class AddSaleSenderInfoIdToSalesTable extends Migration
{
    public function up()
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->unsignedBigInteger('sale_sender_info_id')->nullable();
            $table->index('sale_sender_info_id', 'sales_sale_sender_info_id_idx');
        });
    }

    public function down()
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_sale_sender_info_id_idx');
            $table->dropColumn('sale_sender_info_id');
        });
    }
}
