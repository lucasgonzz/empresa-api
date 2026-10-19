<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La opcion "aplicar los descuentos de esta venta directamente a los precios de los articulos"
 * (mision descuentos-aplicar-al-precio-del-articulo, 8/10/2026), en `sales` y en `budgets`.
 *
 * Es el espejo exacto de `aplicar_recargos_directo_a_items`, que nacio en
 * `2026_02_20_124235_add_apl_sur_to_sales` (ventas) y `2026_09_09_120000_add_aplicar_recargos_
 * directo_a_items_to_budgets` (presupuestos). Prendida, el descuento viaja ADENTRO del `price` de
 * cada renglon y ni el total de la venta, ni el del presupuesto, ni la factura, ni los puntos, ni
 * los PDF lo vuelven a restar al pie. La API la PERSISTE y la PROPAGA (confirmar un presupuesto,
 * duplicarlo); no calcula precios con ella: los calcula la SPA.
 *
 * 🔴 NULLABLE Y SIN DEFAULT, igual que la de recargos: `null` es falsy, asi que todo comprobante
 * viejo sigue restando sus descuentos al pie exactamente como hasta hoy, y distingue "nunca se
 * decidio" de "se decidio que no".
 *
 * 🔴 EL DEPLOY SUBE LOS ARCHIVOS ANTES DE MIGRAR. `Sale` y `Budget` declaran `$guarded = []`, asi
 * que Eloquent mete la clave en el INSERT y la marca sucia en el UPDATE; mientras esta migracion
 * no corrio, eso tumba el alta y la edicion de TODA venta y TODO presupuesto. Los puntos de
 * escritura pasan por `DescuentosEnPreciosEsquemaHelper::quitar_flag_si_no_hay_columna()` y
 * `::asignar_flag_en_update()`.
 *
 * Cada tabla va con `hasTable` + `!hasColumn` y el `down()` es simetrico. La columna va al final,
 * sin `after()`: `sales` es de las tablas mas grandes y es la unica forma de ALTER barata.
 */
class AddAplicarDescuentosDirectoAItemsToSalesAndBudgets extends Migration
{
    /** Nombre de la columna. Tiene que coincidir con `DescuentosEnPreciosEsquemaHelper::COLUMNA_FLAG`. */
    const COLUMNA = 'aplicar_descuentos_directo_a_items';

    /**
     * Las dos tablas que llevan la opcion.
     *
     * @var array<int,string>
     */
    protected $tablas = ['sales', 'budgets'];

    /**
     * Agrega la columna en cada tabla que no la tenga todavia.
     *
     * @return void
     */
    public function up()
    {
        foreach ($this->tablas as $tabla) {

            if (!Schema::hasTable($tabla) || Schema::hasColumn($tabla, self::COLUMNA)) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $table) {
                $table->boolean(self::COLUMNA)->nullable();
            });
        }
    }

    /**
     * Saca la columna de cada tabla donde este. Simetrico a `up()`.
     *
     * @return void
     */
    public function down()
    {
        foreach ($this->tablas as $tabla) {

            if (!Schema::hasTable($tabla) || !Schema::hasColumn($tabla, self::COLUMNA)) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $table) {
                $table->dropColumn(self::COLUMNA);
            });
        }
    }
}
