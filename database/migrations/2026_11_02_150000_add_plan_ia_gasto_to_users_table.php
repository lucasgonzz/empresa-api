<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El tope mensual del plan de IA medido en PLATA y la tabla de precios con la que se mide, empujados
 * por el admin junto con el resto del plan (misión plan-ia-por-gasto, 2/11/2026).
 *
 * Por qué: el tope en tokens trata igual a dos cosas que no cuestan lo mismo. En Ferre Total, 11,3
 * millones de tokens de caché de DeepSeek Flash salieron 7 centavos y 217 mil de salida de Sonnet 5,
 * US$ 2,17. Ver TopeDeTokensHelper.
 *
 * 🔴 LAS DOS SON NULLABLE Y SIN DEFAULT A PROPÓSITO. Null en `plan_ia_tope_gasto_usd_mensual` = el
 * admin no mandó un tope de gasto, y el negocio sigue exactamente como hoy, con su tope de tokens: es
 * la guarda de compatibilidad de las dos direcciones del contrato, la misma de los otros topes.
 *
 * 🔴 EL DUEÑO NUNCA VE ESTOS VALORES. Las dos columnas están en el `$hidden` de User: ni el tope en
 * dólares ni los precios salen por ningún serializador a ningún SPA.
 *
 * `plan_ia_precios` es TEXT con JSON ({modelo: {input, output, cache_write, cache_read}}, dólares por
 * millón): la tabla la maneja el admin (config/ia_precios.php) y acá se guarda la última que mandó.
 *
 * Sin foreign keys, con guard por columna para que sea segura de re-ejecutar.
 */
class AddPlanIaGastoToUsersTable extends Migration
{
    /**
     * Agrega las dos columnas, cada una con su propio guard hasColumn.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'plan_ia_tope_gasto_usd_mensual')) {
                /* Tope de gasto del mes calendario, en dólares. Null o 0 = sin tope de gasto. */
                $table->decimal('plan_ia_tope_gasto_usd_mensual', 10, 2)->nullable();
            }

            if (!Schema::hasColumn('users', 'plan_ia_precios')) {
                /* Tabla de precios que mandó el admin, en JSON, en dólares por millón de tokens. */
                $table->text('plan_ia_precios')->nullable();
            }
        });
    }

    /**
     * Elimina las columnas si existen.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'plan_ia_tope_gasto_usd_mensual')) {
                $table->dropColumn('plan_ia_tope_gasto_usd_mensual');
            }

            if (Schema::hasColumn('users', 'plan_ia_precios')) {
                $table->dropColumn('plan_ia_precios');
            }
        });
    }
}
