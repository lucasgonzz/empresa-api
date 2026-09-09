<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAplicarRecargosDirectoAItemsToBudgets extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('budgets', function (Blueprint $table) {
            /*
                Nullable y SIN default a proposito: un presupuesto viejo queda en `null`, que es
                falsy, y entonces `BudgetHelper::getTotal()` le sigue re-aplicando los recargos
                exactamente como hasta hoy. Un default de 0 haria lo mismo, pero `null` distingue
                "nunca se decidio" de "se decidio que no", igual que en `sales`.

                Misma forma que `2026_02_20_124235_add_apl_sur_to_sales.php`, que es la que agrego
                esta columna del lado de las ventas.
            */
            $table->boolean('aplicar_recargos_directo_a_items')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('budgets', function (Blueprint $table) {
            //
        });
    }
}
