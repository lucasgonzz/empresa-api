<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permite que description en sale_statuses sea opcional (NULL).
 */
class SetNullableDescriptionToSaleStatusesTable extends Migration
{
    /**
     * Aplica nullable en description de sale_statuses.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('sale_statuses', 'description')) {
            Schema::table('sale_statuses', function (Blueprint $table) {
                $table->text('description')->nullable()->change();
            });
        }
    }

    /**
     * Revierte description a NOT NULL (solo si no hay filas con NULL).
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('sale_statuses', 'description')) {
            Schema::table('sale_statuses', function (Blueprint $table) {
                $table->text('description')->nullable(false)->change();
            });
        }
    }
}
