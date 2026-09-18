<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Indices que le faltan al recalculo de comisiones y al guardado de venta (medicion en produccion,
 * Fenix, 18/9/2026 — ver informe `20260918-recalcular-saldos-comisiones.md`).
 *
 * Fenix reporto que tarda mucho en guardar ventas y en cargar pagos en cuenta corriente. La causa
 * central es `ComisionesHelper::recalcular_saldos()` (arreglada aparte, en la misma mision), pero
 * `seller_commissions` no tenia NINGUN indice mas alla de la PRIMARY KEY: ni `seller_id`, ni
 * `sale_id`, ni `status`. Cada `SELECT ... WHERE seller_id = ? AND status = 'active'` (el filtro
 * de recalcular_saldos) y cada `SELECT ... WHERE sale_id = ?` (el de
 * SellerCommissionHelper::checkCommissionStatus) escaneaba las 103.572 filas de Fenix entera cada
 * vez.
 *
 * Tambien sin indice, vistas en el mismo barrido de information_schema: `stock_movements.sale_id`
 * (854K filas en Fenix) y `sales.client_id` (317K filas) — no son la causa central de este
 * sintoma puntual, pero son del mismo tipo de hueco y valen la migracion.
 *
 * ---
 *
 * IDEMPOTENCIA: mismo criterio que 2026_09_04_120000_add_sale_id_indexes_to_venta_tables.php.
 *
 *   (a) por NOMBRE EXACTO -> evita "1061 Duplicate key name" si alguno ya existe a mano en algun
 *                            cliente puntual.
 *   (b) por PREFIJO DE COLUMNAS EN ORDEN -> evita crear un indice REDUNDANTE en un cliente que ya
 *                            tenga esas columnas indexadas bajo otro nombre.
 *
 * down() es un NO-OP a proposito (ver su propio docblock).
 *
 * ALGORITHM=INPLACE, LOCK=NONE explicito, con caida a Schema::table()->index() si el motor
 * devuelve 1846. Mismo criterio que las migraciones de indices anteriores del repo.
 */
class AddIndexesComisionesPerformance extends Migration
{
    /**
     * tabla => [ nombre del indice => columnas en orden ].
     */
    const INDICES = [
        'seller_commissions' => [
            'seller_commissions_seller_id_status_idx' => ['seller_id', 'status'],
            'seller_commissions_sale_id_idx'           => ['sale_id'],
        ],
        'stock_movements' => [
            'stock_movements_sale_id_idx' => ['sale_id'],
        ],
        'sales' => [
            'sales_client_id_idx' => ['client_id'],
        ],
    ];

    /**
     * Crea los indices que falten. Cada uno pasa por cuatro guardas antes de crearse: la tabla
     * existe, las columnas existen, no hay un indice con ese nombre, y no hay ya un indice que
     * cubra ese prefijo de columnas. Si alguna no se cumple, ese indice puntual se saltea (con log
     * si es un caso raro de esquema) y la migracion sigue con el resto.
     *
     * @return void
     */
    public function up()
    {
        foreach (self::INDICES as $tabla => $indices) {

            if (!Schema::hasTable($tabla)) {
                Log::warning('AddIndexesComisionesPerformance: la tabla no existe, se saltea', ['tabla' => $tabla]);
                continue;
            }

            foreach ($indices as $nombre => $columnas) {

                if (!$this->columnas_existen($tabla, $columnas)) {
                    Log::warning('AddIndexesComisionesPerformance: falta alguna columna, se saltea el indice', [
                        'tabla'    => $tabla,
                        'indice'   => $nombre,
                        'columnas' => $columnas,
                    ]);
                    continue;
                }

                // Guarda (a): ya existe con ESE nombre.
                if ($this->ya_existe_el_indice($tabla, $nombre)) {
                    continue;
                }

                // Guarda (b): ya existe OTRO indice que cubre ese prefijo de columnas en orden.
                if ($this->ya_hay_indice_que_cubre($tabla, $columnas)) {
                    continue;
                }

                $this->crear_indice($tabla, $nombre, $columnas);
            }
        }
    }

    /**
     * NO DROPEA NADA. Es un no-op documentado, a proposito.
     *
     * up() no puede distinguir "este indice lo cree yo" de "este indice ya estaba, con este mismo
     * nombre, antes de correr la migracion". Mismo criterio que
     * 2026_09_04_120000_add_sale_id_indexes_to_venta_tables.php::down() y por el mismo motivo. Si
     * alguna vez hace falta revertir esto en un cliente puntual, se hace a mano, mirando primero
     * si ese indice ya estaba ANTES de esta migracion o lo creo ella.
     *
     * @return void
     */
    public function down()
    {
        Log::warning('AddIndexesComisionesPerformance::down() es un no-op a proposito: no se puede '
            . 'distinguir un indice creado por up() de uno que ya existia con ese nombre. Revertir '
            . 'esta migracion, si hace falta, se hace a mano por cliente.');
    }

    /**
     * @param  string $tabla
     * @param  string $nombre
     * @param  array  $columnas
     * @return void
     */
    private function crear_indice($tabla, $nombre, $columnas)
    {
        $sql = 'ALTER TABLE `' . $tabla . '` '
             . 'ADD INDEX `' . $nombre . '` (`' . implode('`, `', $columnas) . '`), '
             . 'ALGORITHM=INPLACE, LOCK=NONE';

        try {
            DB::statement($sql);
            return;
        } catch (\Throwable $e) {
            Log::warning('AddIndexesComisionesPerformance: ALGORITHM=INPLACE, LOCK=NONE no se pudo usar, se cae a Schema::table()', [
                'tabla'  => $tabla,
                'indice' => $nombre,
                'error'  => $e->getMessage(),
            ]);
        }

        Schema::table($tabla, function (Blueprint $table) use ($nombre, $columnas) {
            $table->index($columnas, $nombre);
        });
    }

    /**
     * @param  string $tabla
     * @param  array  $columnas
     * @return bool
     */
    private function columnas_existen($tabla, $columnas)
    {
        foreach ($columnas as $columna) {
            if (!Schema::hasColumn($tabla, $columna)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Guarda (a). Devuelve true si la tabla tiene un indice con ESE NOMBRE.
     *
     * @param  string $tabla
     * @param  string $nombre
     * @return bool
     */
    private function ya_existe_el_indice($tabla, $nombre)
    {
        $filas = DB::select(
            'SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$tabla, $nombre]
        );

        return count($filas) >= 1;
    }

    /**
     * Guarda (b). Devuelve true si YA existe algun indice de la tabla cuyas primeras columnas son,
     * en orden y una por una, las que se quieren indexar.
     *
     * @param  string $tabla
     * @param  array  $columnas
     * @return bool
     */
    private function ya_hay_indice_que_cubre($tabla, $columnas)
    {
        $filas = DB::select(
            'SELECT INDEX_NAME as indice, SEQ_IN_INDEX as seq, COLUMN_NAME as columna
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND SUB_PART IS NULL
              ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$tabla]
        );

        $por_indice = [];

        foreach ($filas as $fila) {
            $por_indice[$fila->indice][(int) $fila->seq] = $fila->columna;
        }

        foreach ($por_indice as $columnas_del_indice) {

            $cubre = true;

            foreach ($columnas as $posicion => $columna) {

                $seq = $posicion + 1;

                if (!isset($columnas_del_indice[$seq]) || $columnas_del_indice[$seq] !== $columna) {
                    $cubre = false;
                    break;
                }
            }

            if ($cubre) {
                return true;
            }
        }

        return false;
    }
}
