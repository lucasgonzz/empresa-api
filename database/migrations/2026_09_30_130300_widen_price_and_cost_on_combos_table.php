<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ensancha `combos.price` y `combos.cost` de DECIMAL(10,2) a DECIMAL(22,2)
 * (misión combos-calculados, 30/9/2026).
 *
 * POR QUÉ. `combos.price` nació con `decimal(10,2)`: tope 99.999.999,99. Antes de esta misión lo
 * escribía una persona a mano y nadie se acercaba. Ahora, en un combo calculado, `combos.price`
 * guarda el precio de la lista por defecto, que sale de `combo_price_type.price` (DECIMAL(22,2)) y
 * de la suma de precios de artículos (`articles.final_price` es DECIMAL(22,x)): un combo con un
 * precio de 100.000.000 o más calcula bien y revienta al guardar (`Out of range`), y
 * `ComboCalculadoHelper::guardar()` deja de poder recalcular ese combo. `combos.cost` tiene el mismo
 * ancho y la misma suerte (el costo se suma de los `costo_real` de los componentes).
 *
 * 22 dígitos con 2 decimales es lo que ya usan `combo_price_type.price` y el resto de las tablas de
 * precios. Esto ENSANCHA: no cambia ningún dato. No toca `combo_sale` (su `price` y su `cost` ya
 * son DECIMAL(30,2)).
 *
 * Idempotente: pregunta el ancho actual a information_schema y solo hace el ALTER si la columna
 * todavía no tiene 22 dígitos. Sin foreign keys. Usa `->change()` (doctrine/dbal ya está instalado),
 * igual que `2026_07_30_160000_extend_cost_decimals_on_articles_table`.
 */
class WidenPriceAndCostOnCombosTable extends Migration
{
    /** Ancho nuevo de las dos columnas. */
    const PRECISION_NUEVA = 22;

    /** Ancho original, al que vuelve `down()` si se puede sin perder datos. */
    const PRECISION_ORIGINAL = 10;

    /** Las dos columnas que se ensanchan. */
    const COLUMNAS = ['price', 'cost'];

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('combos')) {
            return;
        }

        $angostas = [];

        foreach (self::COLUMNAS as $columna) {

            if (Schema::hasColumn('combos', $columna) && $this->precision_actual($columna) < self::PRECISION_NUEVA) {
                $angostas[] = $columna;
            }
        }

        if (count($angostas) === 0) {
            return;
        }

        Schema::table('combos', function (Blueprint $table) use ($angostas) {

            foreach ($angostas as $columna) {
                $table->decimal($columna, self::PRECISION_NUEVA, 2)->nullable()->change();
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * Solo vuelve a DECIMAL(10,2) la columna que lo soporta: si algún combo ya guarda un valor que
     * no entra en el ancho original, angostarla sería truncar o fallar en MySQL estricto, así que
     * queda ancha (un rollback no puede costar datos).
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('combos')) {
            return;
        }

        $limite = pow(10, self::PRECISION_ORIGINAL - 2);

        $angostables = [];

        foreach (self::COLUMNAS as $columna) {

            if (!Schema::hasColumn('combos', $columna) || $this->precision_actual($columna) <= self::PRECISION_ORIGINAL) {
                continue;
            }

            $fuera_de_rango = DB::table('combos')->where($columna, '>=', $limite)->orWhere($columna, '<=', -$limite)->exists();

            if (!$fuera_de_rango) {
                $angostables[] = $columna;
            }
        }

        if (count($angostables) === 0) {
            return;
        }

        Schema::table('combos', function (Blueprint $table) use ($angostables) {

            foreach ($angostables as $columna) {
                $table->decimal($columna, self::PRECISION_ORIGINAL, 2)->nullable()->change();
            }
        });
    }

    /**
     * Los dígitos totales (la PRECISION del DECIMAL) que tiene hoy la columna.
     *
     * @param  string  $columna
     * @return int
     */
    private function precision_actual($columna)
    {
        $fila = DB::selectOne(
            'SELECT NUMERIC_PRECISION AS precision_actual FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['combos', $columna]
        );

        return is_null($fila) ? 0 : (int) $fila->precision_actual;
    }
}
