<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `budget_combo` — un combo adentro de un presupuesto
 * (misión combos-y-rangos-de-precio, 16/9/2026).
 *
 * Un presupuesto ya sabe llevar artículos (`article_budget`), servicios (`budget_service`) y
 * promociones de vinoteca (`budget_promocion_vinoteca`). Los combos eran el único tipo de ítem de
 * VENDER que NO tenía dónde guardarse: `vender_presupuestos.js` arma el payload con esas tres
 * claves y ninguna más, así que un combo cargado en el remito desaparecía en silencio al guardar
 * como presupuesto — sin error, sin aviso, y con el total del request sin cerrar contra
 * `BudgetHelper::getTotal()`.
 *
 * 🔴 Esta tabla es PREREQUISITO de la detección automática de combos, no un agregado. La detección
 * reemplaza artículos sueltos por un combo; si eso pasara con "guardar como presupuesto" activo y
 * el presupuesto no supiera guardar combos, la detección le estaría borrando líneas al vendedor.
 * Lucas pidió explícitamente que la detección funcione también en presupuestos, y la única forma
 * honesta de hacerlo es que el presupuesto sepa guardarlos.
 *
 * `amount` va decimal(10,2) y no integer, siguiendo a `article_budget`: el pivote de venta
 * (`combo_sale.amount`) lo declaró integer pero `SaleHelper::attachCombos` le manda `(float)`, así
 * que el entero ahí es una promesa que el código no cumple. Acá no se repite.
 */
class CreateBudgetComboTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('budget_combo')) {
            Schema::create('budget_combo', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->id();
                $table->integer('combo_id')->unsigned();
                $table->integer('budget_id')->unsigned();
                $table->decimal('amount', 10,2)->nullable();
                $table->decimal('price', 20,2)->nullable();
                $table->timestamps();
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
        Schema::dropIfExists('budget_combo');
    }
}
