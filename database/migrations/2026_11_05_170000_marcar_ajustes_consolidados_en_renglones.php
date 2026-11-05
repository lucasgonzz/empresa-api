<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MarcarAjustesConsolidadosEnRenglones extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('sales', 'ajustes_consolidados_en_renglones')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->boolean('ajustes_consolidados_en_renglones')->default(false);
            });
        }
    }

    public function down()
    {
        if (!Schema::hasColumn('sales', 'ajustes_consolidados_en_renglones')) {
            return;
        }

        if (Schema::hasColumn('sales', 'forzar_total_monto')
            && DB::table('sales')->where('ajustes_consolidados_en_renglones', true)
                ->where('forzar_total_monto', '<>', 0)->exists()) {
            throw new RuntimeException('Quitar la marca volvería a aplicar ajustes ya incluidos en los renglones.');
        }

        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('ajustes_consolidados_en_renglones');
        });
    }
}
