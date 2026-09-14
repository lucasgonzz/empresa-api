<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Misión agenda-tareas-calendario (14/9/2026): hasta hoy marcar como hecha una tarea con gasto
 * creaba el Expense y no dejaba rastro de cuál era. Sin ese vínculo la SPA no puede mostrar
 * "Gasto N° X" en Realizadas ni avisar, al deshacer, que el gasto queda cargado.
 *
 * `expense_amount` se guarda además del `expense_id` porque el monto que se pagó puede diferir
 * del estimado en la tarea (`pendings.expense_amount`), y porque si el gasto se borra desde
 * Gastos el `PendingCompleted` sigue diciendo cuánto se registró ese día.
 *
 * Las dos columnas son nullable: una tarea sin gasto, o marcada "hecha sin registrar el gasto",
 * las deja en `null`. Sin foreign keys, como todo el repo.
 */
class AddExpenseIdToPendingCompletedsTable extends Migration
{
    /**
     * Agrega el vínculo al gasto y el monto registrado.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('pending_completeds', function (Blueprint $table) {
            /** Id del Expense que se dio de alta al marcar la tarea como hecha. */
            $table->unsignedBigInteger('expense_id')->nullable()->after('expense_concept_id');
            /** Monto con el que se registró ese gasto. */
            $table->decimal('expense_amount', 12, 2)->nullable()->after('expense_id');
        });
    }

    /**
     * Elimina las columnas agregadas.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('pending_completeds', function (Blueprint $table) {
            $table->dropColumn(['expense_id', 'expense_amount']);
        });
    }
}
