<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Misión mostrador-caja-vencimientos (15/9/2026): `pendings.expense_amount` nació en 2024 como
 * `$table->decimal('expense_amount')`, que en Laravel es decimal(8,2): el techo es $ 999.999,99.
 * PendingController acepta cualquier monto mayor o igual a cero, así que una tarea de la Agenda
 * de $ 1.000.000 o más —alquiler, sueldos, impuestos: justo los vencimientos que el informe de
 * Caja y vencimientos tiene que proyectar— no entraba en la columna.
 *
 * Se ensancha a decimal(14,2). Ensanchar no toca ningún valor existente, así que es compatible
 * hacia atrás con la SPA y el API viejos (la tienda no usa esta tabla). Mismo patrón de
 * `->change()` con doctrine/dbal que 2026_07_30_160000_extend_cost_decimals_on_articles_table.
 * Sin foreign keys, como todo el repo.
 */
class WidenExpenseAmountOnPendingsTable extends Migration
{
    /**
     * Ensancha el monto estimado de las tareas a decimal(14,2).
     *
     * @return void
     */
    public function up()
    {
        Schema::table('pendings', function (Blueprint $table) {
            $table->decimal('expense_amount', 14, 2)->nullable()->change();
        });
    }

    /**
     * Vuelve la columna a decimal(8,2).
     *
     * ⚠️ Puede truncar o fallar: una tarea guardada con un monto de $ 1.000.000 o más no entra en
     * la columna vieja y, según el modo de MySQL, el valor se recorta o el ALTER aborta.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('pendings', function (Blueprint $table) {
            $table->decimal('expense_amount', 8, 2)->nullable()->change();
        });
    }
}
