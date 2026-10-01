<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El reparto de métodos de pago de un presupuesto "de contado" (misión
 * presupuesto-contado-o-cuenta-corriente, 1/10/2026).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  QUÉ GUARDA LA COLUMNA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  El reparto TAL CUAL lo arma la SPA en el modal de Vender (las mismas filas que viajan en
 *  `selected_payment_methods` de una venta: método, monto, caja, cuota, cotización, descuento y
 *  recargo por método, datos del cheque), serializado como JSON. Se guarda en el presupuesto para
 *  poder aplicarlo a la venta que nace al CONFIRMAR, que puede ser días después y desde el listado,
 *  donde no viaja ningún dato de cobro.
 *
 *  Es la contraparte de `budgets.omitir_en_cuenta_corriente` (que ya existe desde marzo de 2026):
 *  un presupuesto es "de contado" solo si esa columna está en 1 Y esta tiene al menos una fila.
 *  Cualquier otra combinación es cuenta corriente, como siempre. La regla vive en un solo lugar:
 *  `BudgetCobroHelper::es_de_contado()`.
 *
 *  El ajuste por método de pago (descuento por transferencia, recargo por cuotas) NO tiene columna
 *  propia: se deriva de las filas (`BudgetCobroHelper::ajuste_por_metodos_de_pago()`), así no puede
 *  desfasarse de ellas.
 *
 * ⚠️ ADITIVA Y NULLABLE, SIN DEFAULT: un presupuesto viejo queda en NULL, que significa "no hay
 * reparto" = cuenta corriente. `empresa` y `tienda` comparten la base física del cliente y la
 * `tienda` no lee esta tabla de presupuestos.
 *
 * 🔴 LA DIRECCIÓN CONTRARIA NO ES COMPATIBLE: un deploy de empresa sube los archivos ANTES de
 * migrar, y `Budget` declara `$guarded = []`. Sin guarda, en esa ventana el alta y la edición de
 * TODO presupuesto de TODOS los clientes morirían con `Unknown column`. Por eso los puntos de
 * escritura pasan por `CobroPresupuestoEsquemaHelper`. Mismo molde que `forzar_total_monto`.
 *
 * Sin foreign keys, sin índices: se lee siempre por la fila del presupuesto.
 */
class AddSelectedPaymentMethodsToBudgetsTable extends Migration
{
    /**
     * longText y no text: un reparto con varios métodos y datos de cheque (banco, notas) puede
     * pasar los 64 KB de un `text` en un caso extremo, y el costo de longText es cero en MySQL.
     *
     * Con guard `hasColumn` para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('budgets', 'selected_payment_methods')) {

            Schema::table('budgets', function (Blueprint $table) {
                $table->longText('selected_payment_methods')->nullable();
            });
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('budgets', 'selected_payment_methods')) {

            Schema::table('budgets', function (Blueprint $table) {
                $table->dropColumn('selected_payment_methods');
            });
        }
    }
}
