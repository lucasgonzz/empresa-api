<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: agrega soporte para ventas de consolidación de facturación.
 *
 * Una "venta consolidada" agrupa varias ventas individuales de un cliente
 * en un período para emitir una única factura AFIP. No impacta stock,
 * cuenta corriente ni reportes de ventas.
 *
 * Columnas:
 *   - is_consolidacion_facturacion: marca la venta contenedor (no es venta real).
 *   - consolidacion_facturacion_id: en ventas originales, apunta a la venta
 *     consolidada que las cubre. Nulo = venta normal sin consolidar.
 */
class AddConsolidacionFacturacionToSales extends Migration
{
    public function up()
    {
        Schema::table('sales', function (Blueprint $table) {
            /** Flag que distingue las ventas contenedor (solo para facturar AFIP) de las ventas reales. */
            $table->boolean('is_consolidacion_facturacion')->default(0)->nullable()->after('send_mail');

            /** FK lógica (sin restricción en motor) hacia la venta consolidada que cubre esta venta original. */
            $table->unsignedBigInteger('consolidacion_facturacion_id')->nullable()->after('is_consolidacion_facturacion');

            /** Índice para filtrar rápidamente ventas reales vs contenedoras en reportes. */
            $table->index('is_consolidacion_facturacion', 'sales_is_consol_fac_idx');

            /** Índice para consultar las ventas originales agrupadas en una consolidación. */
            $table->index('consolidacion_facturacion_id', 'sales_consol_fac_id_idx');
        });
    }

    public function down()
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_is_consol_fac_idx');
            $table->dropIndex('sales_consol_fac_id_idx');
            $table->dropColumn('is_consolidacion_facturacion');
            $table->dropColumn('consolidacion_facturacion_id');
        });
    }
}
