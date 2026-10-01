<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `iva_en_articulos_sin_iva` a `sales` y `budgets` (mision iva-a-articulos-sin-iva-en-vender,
 * 1/10/2026).
 *
 * Es el check de Vender "Sumar IVA a los articulos sin IVA", al lado de "Precios con IVA"
 * (`iva_aplicado`): 1 = a los articulos con `aplicar_iva` apagado se les sumo el IVA de su alicuota
 * en el precio del renglon; 0 = quedaron igual al listado. La SPA lo lee de vuelta al editar la
 * venta o el presupuesto para saber de que estado parten los precios guardados.
 *
 * Default 0: todo comprobante anterior a esta mision se guardo sin ese ajuste, asi que el default
 * describe exactamente lo que ya hay en la base.
 *
 * Con guarda `hasColumn` en up y down: la migracion se puede repetir sin romper. Mientras la columna
 * no exista (ventana del deploy entre subir archivos y migrar), los puntos de escritura la omiten
 * por `IvaEnArticulosSinIvaEsquemaHelper`.
 */
class AddIvaEnArticulosSinIvaToSalesAndBudgets extends Migration
{
    /**
     * Las dos tablas que llevan el flag.
     *
     * @var array
     */
    private $tablas = ['sales', 'budgets'];

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        foreach ($this->tablas as $tabla) {

            if (Schema::hasColumn($tabla, 'iva_en_articulos_sin_iva')) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $table) {
                // Check "Sumar IVA a los articulos sin IVA" de Vender, al lado de iva_aplicado.
                $table->boolean('iva_en_articulos_sin_iva')->default(0)->after('iva_aplicado');
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
        foreach ($this->tablas as $tabla) {

            if (!Schema::hasColumn($tabla, 'iva_en_articulos_sin_iva')) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $table) {
                $table->dropColumn('iva_en_articulos_sin_iva');
            });
        }
    }
}
