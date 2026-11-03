<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AgregarPrecioPrecisoDeConsolidacionARenglones extends Migration
{
    private const DIGITOS_ENTEROS = [
        'article_sale' => 23,
        'combo_sale' => 28,
        'sale_service' => 10,
        'article_current_acount' => 10,
        'current_acount_service' => 10,
    ];

    public function up()
    {
        foreach (self::DIGITOS_ENTEROS as $tabla => $digitos) {
            if (!Schema::hasColumn($tabla, 'price_consolidacion')) {
                Schema::table($tabla, function (Blueprint $table) use ($digitos) {
                    $table->decimal('price_consolidacion', $digitos + 12, 12)->nullable();
                });
            }
        }
    }

    public function down()
    {
        $tablas_con_snapshot = array_filter(array_keys(self::DIGITOS_ENTEROS), function ($tabla) {
            return Schema::hasColumn($tabla, 'price_consolidacion');
        });

        foreach ($tablas_con_snapshot as $tabla) {
            if (DB::table($tabla)->whereNotNull('price_consolidacion')->exists()) {
                throw new RuntimeException('Quitar la columna cambiaria precios de ventas consolidadas ya guardadas.');
            }
        }

        foreach ($tablas_con_snapshot as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropColumn('price_consolidacion');
            });
        }
    }
}
