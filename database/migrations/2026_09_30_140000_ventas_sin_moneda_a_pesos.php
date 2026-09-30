<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las ventas sin moneda (`sales.moneda_id` NULL o 0) pasan a pesos (`1`).
 *
 * Decisión de Lucas (30/9/2026): una venta sin moneda se trata SIEMPRE como pesos. El código ya lo
 * hace en los lugares que la leen (`Sale::EXPRESION_EN_PESOS`, `getCost()`, `ArticlePurchaseHelper`,
 * ARCA, los exports, `Total.vue`), y `Sale` ya no deja crear una sin moneda. Esta migración deja el
 * DATO consistente con esa regla, para que cualquier consulta nueva que compare `moneda_id = 1`
 * (un reporte, una exportación, el asistente) no vuelva a dejar afuera a las ventas viejas.
 *
 * `sales.moneda_id` es nullable sin default desde `2025_08_29_162530_add_moneda_id_to_sales_table`,
 * así que toda venta anterior a esa columna (y las que entraron por un camino que no la mandaba)
 * quedó en NULL. En 2R, por ejemplo, hay una.
 *
 * Es un UPDATE acotado a las filas sin moneda, sin reescribir la tabla, y compatible en las dos
 * direcciones: un deploy de empresa SUBE LOS ARCHIVOS ANTES DE MIGRAR y el código nuevo lee NULL
 * como pesos igual que esta migración.
 *
 * Con guarda `Schema::hasColumn`. No tiene `down()` útil: no hay forma de saber qué ventas estaban en
 * NULL, y volver a NULL no tendría ningún efecto.
 */
class VentasSinMonedaAPesos extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('sales', 'moneda_id')) {
            DB::statement('UPDATE `sales` SET `moneda_id` = 1 WHERE `moneda_id` IS NULL OR `moneda_id` = 0');
        }
    }

    /**
     * No deshace nada: ver el comentario de la clase.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
