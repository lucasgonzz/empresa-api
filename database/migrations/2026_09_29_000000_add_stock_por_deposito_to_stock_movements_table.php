<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock por depósito en cada movimiento de stock (misión stock-por-deposito-en-movimientos,
 * 29/9/2026). Ver App\Http\Controllers\Stock\SetStockPorDeposito.
 *
 *  - `stock_anterior`: `articles.stock` justo antes de aplicar el movimiento (el "después" ya
 *    estaba: `stock_resultante`).
 *  - `stock_por_deposito`: la foto de todos los depósitos del artículo, antes y después, en JSON.
 *
 * Solo agrega columnas nullable: la versión anterior del código (y `tienda-api`, que comparte la
 * base) sigue insertando y leyendo movimientos sin enterarse. Los movimientos anteriores a esta
 * migración quedan en NULL: lo histórico no se reconstruye.
 *
 * `text` y no `json` a propósito: hay clientes en MariaDB del shared hosting (donde `json` es un
 * alias con CHECK) y la columna no se consulta por SQL, solo se lee y se muestra.
 *
 * Sin `after()`: agregadas al final de la tabla, MySQL 8 y MariaDB 10.3+ las suman sin
 * reconstruir `stock_movements`, que en los clientes grandes tiene cientos de miles de filas.
 *
 * Idempotente con `hasColumn`, por si alguna base ya las tiene.
 */
class AddStockPorDepositoToStockMovementsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('stock_movements', 'stock_anterior')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->decimal('stock_anterior', 12, 2)->nullable();
            });
        }

        if (!Schema::hasColumn('stock_movements', 'stock_por_deposito')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->text('stock_por_deposito')->nullable();
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
        if (Schema::hasColumn('stock_movements', 'stock_por_deposito')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->dropColumn('stock_por_deposito');
            });
        }

        if (Schema::hasColumn('stock_movements', 'stock_anterior')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->dropColumn('stock_anterior');
            });
        }
    }
}
