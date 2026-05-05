<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sustituye sale_type_id por sale_status_id en presupuestos
 * (corrección del esquema; la columna correcta alinea con sales.sale_status_id).
 */
class ReplaceBudgetSaleTypeIdWithSaleStatusId extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('budgets', 'sale_type_id')) {
            Schema::table('budgets', function (Blueprint $table) {
                $table->dropColumn('sale_type_id');
            });
        }
        if (!Schema::hasColumn('budgets', 'sale_status_id')) {
            Schema::table('budgets', function (Blueprint $table) {
                $table->integer('sale_status_id')->nullable()->after('price_type_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('budgets', 'sale_status_id')) {
            Schema::table('budgets', function (Blueprint $table) {
                $table->dropColumn('sale_status_id');
            });
        }
        if (!Schema::hasColumn('budgets', 'sale_type_id')) {
            Schema::table('budgets', function (Blueprint $table) {
                $table->unsignedInteger('sale_type_id')->nullable()->after('price_type_id');
            });
        }
    }
}
