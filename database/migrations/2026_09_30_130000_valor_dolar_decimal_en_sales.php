<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `sales.valor_dolar` pasa de INT a DECIMAL(20,2) NULL, igual que `budgets.valor_dolar`
 * (misión corregir-ventas-en-dolares, 30/9/2026).
 *
 * La columna nació ENTERA (`2025_08_29_162530_add_moneda_id_to_sales_table`:
 * `$table->integer('valor_dolar')`) mientras que la de presupuestos (`2025_09_16_123114`) ya era
 * decimal. Una cotización con centavos (`users.dollar` y el blue traen decimales: 1234,56) se
 * guardaba redondeada a 1235, y esa es la cotización que después leen:
 *
 *   - ARCA: `AfipFexHelper` y `AfipNotaCreditoHelper` la mandan como `Moneda_cotiz`, y
 *     `AfipWsfeHelper` / `AfipImportesCalculator` / `AfipItemCalculator` convierten con ella el
 *     total y los renglones de una venta en dólares.
 *   - Los costos: `SaleHelper::getCost()` calcula el costo del renglón en memoria con el valor
 *     exacto que mandó la SPA (10 USD × 1234,56 = 12345,60) y la venta guardaba 1235, así que el
 *     costo guardado no era "costo en dólares × sales.valor_dolar" y cualquier recálculo o
 *     auditoría posterior (`set_costo_ventas`, `ConsolidarFacturacionHelper`) daba otro número.
 *
 * ALTER crudo y no `->change()`, por el mismo criterio que
 * `2026_09_05_100000_ampliar_payment_id_de_carts_y_orders` y las que cita: `change()` exige
 * doctrine/dbal, que reconstruye la columna a partir de lo declarado, y el DDL explícito es el
 * mismo en MySQL 5.7 y 8. Un cambio de tipo reescribe la tabla entera (copia) y `sales` es de las
 * más grandes (decenas de miles de filas en los clientes de más volumen); no se midió cuánto tarda
 * en producción, pero en esa escala es del orden de segundos.
 *
 * Es compatible en las dos direcciones. Un deploy de empresa SUBE LOS ARCHIVOS ANTES DE MIGRAR
 * (`DeploymentService::execute_steps()`: upload_api → sync_env_keys → run_migrations): en esa
 * ventana el código nuevo escribe un número (`CotizacionDeVentaHelper::valor_dolar_para_guardar()`,
 * a 2 decimales) en una columna que todavía es INT, y MySQL lo redondea igual que siempre; no hay
 * ninguna clave nueva que la base vieja no conozca. Y los valores que ya estaban guardados como
 * enteros (1200) pasan a 1200.00 sin perder nada.
 *
 * Lo que devuelve Eloquent para una columna DECIMAL es un STRING ("1234.56"), no un número: todos
 * los lectores de `sales.valor_dolar` lo castean a `(float)` antes de operar (los que no lo hacían
 * se corrigieron en esta misma misión, y los que tenían un `if ($sale->valor_dolar)` pasaron a
 * `(float) > 0` porque el string '0.00' es truthy).
 *
 * Los ceros que ya hubiera se pasan a NULL (ver `up()`).
 *
 * Con guarda `Schema::hasColumn`: una base donde la columna no exista (o una instalación de cero
 * que ya la cree decimal) no falla.
 */
class ValorDolarDecimalEnSales extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('sales', 'valor_dolar')) {
            DB::statement('ALTER TABLE `sales` MODIFY `valor_dolar` DECIMAL(20,2) NULL DEFAULT NULL');

            /*
             * Los ceros pasan a NULL. Para todo el que lee la columna 0 y NULL significan lo mismo ("sin
             * cotizacion"), y la SPA podia postear 0 cuando el operador vaciaba el input de la cotizacion.
             * Con el INT un 0 era falso en un `if ($sale->valor_dolar)`; con el DECIMAL el string '0.00' es
             * TRUTHY en PHP y esos lectores multiplicarian por cero. Los lectores ya se corrigieron a
             * `(float) > 0`; esto deja ademas el dato limpio y consistente con lo que guarda el alta nueva
             * (`valor_dolar_para_guardar()` guarda NULL cuando la cotizacion no sirve).
             */
            DB::statement('UPDATE `sales` SET `valor_dolar` = NULL WHERE `valor_dolar` = 0');
        }
    }

    /**
     * Salida de emergencia, no un camino normal: el pipeline de upgrade no hace rollback.
     *
     * Volver a INT redondea las cotizaciones con centavos (1234,56 → 1235), que es exactamente el
     * defecto que esta migración corrige; por eso el rollback pierde información y no es
     * recomendable sobre una base con ventas ya guardadas con decimales.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('sales', 'valor_dolar')) {
            DB::statement('ALTER TABLE `sales` MODIFY `valor_dolar` INT NULL DEFAULT NULL');
        }
    }
}
