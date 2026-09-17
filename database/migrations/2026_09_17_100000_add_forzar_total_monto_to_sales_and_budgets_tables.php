<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El monto del total forzado, en la venta y en el presupuesto (mision forzar-total-por-monto,
 * 17/9/2026).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  LA SEMANTICA DE LA COLUMNA, QUE ES LO UNICO QUE HAY QUE SABER PARA LEERLA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Es un monto CON SIGNO que se le suma al total para llegar al total que el usuario escribio
 *  a mano en VENDER:
 *
 *      NEGATIVO = descuento   (la venta daba 4.012, el vendedor cobro 4.000 -> se guarda -12.00)
 *      POSITIVO = recargo     (la venta daba 4.012, el vendedor cobro 4.020 -> se guarda   +8.00)
 *      NULL     = no se forzo nada, la venta es exactamente la suma de sus renglones
 *
 *  Se guarda el MONTO y no el total tipeado a proposito: asi se comporta igual que cualquier
 *  otro descuento de venta si despues cambia algo (se agrega un articulo, cambia un precio), en
 *  vez de quedar clavado en un numero que ya no se corresponde con nada.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 POR QUE NO SE REUSA `sales.descuento`
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Porque `sales.descuento` es un PORCENTAJE, confirmado por Lucas el 24/8/2026, y asi lo leen
 *  `SaleHelper::getTotalSale()`, `AfipItemCalculator::get_article_price_with_discounts()` y los
 *  dos PDF. Hay ventas historicas con un porcentaje guardado ahi. Redefinirlo como monto
 *  corromperia en silencio la lectura de todo ese historico: la misma columna significaria una
 *  cosa antes de la fecha del despliegue y otra despues, sin nada en la fila que lo distinga.
 *
 *  Es exactamente el mismo razonamiento por el que el canje de puntos se llevo sus dos columnas
 *  propias (`2026_08_22_100500_add_puntos_to_sales_table`) en vez de meterse en esta.
 *
 * ⚠️ ADITIVA Y NULLABLE A PROPOSITO: `empresa` y `tienda` comparten la base fisica del cliente y
 * nunca llegan juntas a produccion. Una `tienda` vieja contra una base con esta columna no se
 * entera (su unico contacto con la tabla es el `belongsTo(Sale::class)` de `app/CurrentAcount.php`),
 * y un `empresa` viejo contra una base con la columna tampoco: la ignora y sigue leyendo `total`,
 * que NO cambia de significado — sigue siendo el total final de la venta.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 LA DIRECCION CONTRARIA NO ES COMPATIBLE, Y HAY QUE DECIRLO
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  🔴 UN DEPLOY DE EMPRESA SUBE LOS ARCHIVOS **ANTES** DE MIGRAR. `DeploymentService::execute_steps()`
 *  de `admin-api` corre `upload_api` -> `sync_env_keys` -> `run_migrations`, en ese orden. Entre el
 *  primero y el tercero hay una ventana real en la que el cliente tiene este codigo y no tiene esta
 *  columna.
 *
 *  Y ahi el daño no se limita a las ventas forzadas: `Sale` y `Budget` declaran `$guarded = []`,
 *  asi que Eloquent mete `forzar_total_monto` en el INSERT **aunque el valor sea null**. Sin guarda,
 *  en esa ventana se cae el alta de TODA venta y de TODO presupuesto, de todos los clientes, con
 *  `SQLSTATE[42S22]: Unknown column`.
 *
 *  Por eso los cuatro puntos de escritura pasan por `ForzarTotalEsquemaHelper`, que omite la clave
 *  mientras la columna no este. Es el mismo molde —y la misma ventana— que la guarda de
 *  `budget_combo` (commit 5c0da889, 16/9/2026), cuyo mensaje ya dice textualmente que "un deploy de
 *  empresa sube los archivos ANTES de migrar".
 *
 *  ⚠️ Una version anterior de este mismo comentario afirmaba lo contrario —que el deploy migraba
 *  antes de servir la version nueva— y de ahi concluia que la guarda no hacia falta. Era falso, y
 *  es exactamente lo que hizo que la guarda no se escribiera: un comentario que da una garantia que
 *  no existe es peor que no tener comentario.
 *
 *  Y el gap entre los archivos de migracion y la tabla `migrations` tampoco es teorico en este
 *  parque: hay clientes migrados con ese desfasaje.
 */
class AddForzarTotalMontoToSalesAndBudgetsTables extends Migration
{
    /**
     * Agrega las dos columnas, con guard `hasColumn` para que sea segura de re-ejecutar.
     *
     * decimal(22,2): el mismo largo que `sales.total` y `sales.sub_total`, que son los dos numeros
     * contra los que este monto se lee. Dos decimales, no cuatro: es plata, no un porcentaje.
     *
     * ⚠️ `budgets.total` es decimal(30,2), no 22: la columna del presupuesto se deja igual que la
     * de la venta a proposito, porque el monto viaja de una a la otra en `BudgetHelper::saveSale()`
     * y tiene que entrar sin truncarse en el destino. Los 22 digitos son el limite real.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('sales') && !Schema::hasColumn('sales', 'forzar_total_monto')) {

            Schema::table('sales', function (Blueprint $table) {

                /* Monto con signo del total forzado. Ver el bloque de arriba. null = no se forzo. */
                $table->decimal('forzar_total_monto', 22, 2)->nullable();
            });
        }

        if (Schema::hasTable('budgets') && !Schema::hasColumn('budgets', 'forzar_total_monto')) {

            Schema::table('budgets', function (Blueprint $table) {

                /*
                 * El presupuesto lleva la misma columna porque el forzado tiene que SOBREVIVIR a la
                 * conversion en venta: `BudgetHelper::saveSale()` arma la venta a partir del
                 * presupuesto, y sin esta columna el monto se perderia justo en el paso donde el
                 * numero pasa a ser plata de verdad.
                 */
                $table->decimal('forzar_total_monto', 22, 2)->nullable();
            });
        }
    }

    /**
     * Saca las dos columnas.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasTable('sales') && Schema::hasColumn('sales', 'forzar_total_monto')) {

            Schema::table('sales', function (Blueprint $table) {
                $table->dropColumn('forzar_total_monto');
            });
        }

        if (Schema::hasTable('budgets') && Schema::hasColumn('budgets', 'forzar_total_monto')) {

            Schema::table('budgets', function (Blueprint $table) {
                $table->dropColumn('forzar_total_monto');
            });
        }
    }
}
