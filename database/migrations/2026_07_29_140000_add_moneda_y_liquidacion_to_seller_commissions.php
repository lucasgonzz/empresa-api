<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grupo 268 · Prompt 01 — agrega moneda y fecha de liquidacion al ledger de comisiones de vendedor.
 *
 * moneda_id: moneda del ledger al que pertenece este movimiento. Se hereda de sales.moneda_id
 * cuando la comision viene de una venta. null se interpreta como pesos (1) en todo el read-path,
 * por compatibilidad con las filas historicas.
 *
 * liquidada_at: momento en que la comision quedo lista para pagarse. Para los vendedores con
 * commission_after_pay_sale = 0 coincide con la creacion. Para los que lo tienen en 1, es el
 * momento en que la venta quedo saldada por completo. null mientras la comision este inactive.
 */
class AddMonedaYLiquidacionToSellerCommissions extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('seller_commissions', function (Blueprint $table) {
            $table->integer('moneda_id')->nullable()->after('percentage');
            $table->timestamp('liquidada_at')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('seller_commissions', function (Blueprint $table) {
            $table->dropColumn('moneda_id');
            $table->dropColumn('liquidada_at');
        });
    }
}
