<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `expense_category_id` a `expenses` (grupo 277, prompt 01): la categoria de un gasto se
 * deriva del concepto (`expense_concepts.expense_category_id`, desde la migracion
 * `2025_09_22_132125`), pero hoy solo se puede obtener saltando de `expenses` a
 * `expense_concepts` -- eso alcanza para mostrarla, pero no para filtrar/ordenar el listado por
 * categoria, porque `SearchController::search()` arma su `where` sobre una columna real de la
 * tabla del modelo. La columna nueva es derivada y la mantiene el modelo `Expense` (hook
 * `saving`), nunca la carga el usuario.
 */
class AddExpenseCategoryIdToExpensesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // hasColumn no es paranoia decorativa: esta migracion corre contra las bases de 40 y pico
        // de clientes en momentos distintos, y una segunda corrida por cualquier motivo no puede
        // tirarla abajo. Sin foreign key (regla del workspace) y nullable sin default: un concepto
        // puede no tener categoria, y en ese caso el gasto tampoco la tiene.
        if (!Schema::hasColumn('expenses', 'expense_category_id')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->integer('expense_category_id')->nullable();
            });
        }

        /**
         * Backfill: todos los gastos ya cargados toman la categoria de su concepto. Es un UPDATE
         * con JOIN y no un recorrido en PHP porque puede haber decenas de miles de gastos por base
         * y esto corre dentro de la ventana de actualizacion del cliente. No hace falta filtrar
         * por user_id: el JOIN es por id de concepto, y un gasto siempre apunta a un concepto del
         * mismo usuario.
         */
        DB::statement(
            "UPDATE `expenses`
             INNER JOIN `expense_concepts` ON `expense_concepts`.`id` = `expenses`.`expense_concept_id`
             SET `expenses`.`expense_category_id` = `expense_concepts`.`expense_category_id`"
        );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('expenses', 'expense_category_id')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->dropColumn('expense_category_id');
            });
        }
    }
}
