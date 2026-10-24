<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Índices de `stock_movements` que necesita la red de la principal (misión sucursal-siempre,
 * 23/10/2026, tercera ronda del chequeo del motor).
 *
 * `SucursalVigenteHelper::el_comprobante_movio_stock()` busca, por cada movimiento sin sucursal sobre
 * un artículo que reparte, los movimientos ORIGINALES de ese comprobante y ese artículo
 * (`WHERE provider_order_id|nota_credito_id|order_id = ? AND article_id = ?`), y, si el original no
 * tiene `stock_anterior`, el movimiento anterior del artículo
 * (`WHERE article_id = ? AND id < ? ORDER BY id DESC LIMIT 1`). En producción solo `sale_id` está
 * indexado (2026_09_18_120000_add_indexes_comisiones_performance): sin estos índices cada renglón de
 * una compra o de una nota de crédito era un full scan de `stock_movements` (cientos de miles de
 * filas en los clientes grandes; verificado en copias locales de bases reales).
 *
 *  - `(article_id)`: InnoDB guarda la PRIMARY KEY (`id`) al final de todo índice secundario, así que
 *    `article_id = ? AND id < ? ORDER BY id DESC` se resuelve con este índice solo, sin ordenar.
 *  - `(provider_order_id)`, `(nota_credito_id)`, `(order_id)`: los comprobantes que no son ventas.
 *
 * IDEMPOTENCIA, como 2026_09_18_120000: cada índice se crea solo si no hay uno con ese nombre y si no
 * hay YA otro índice que EMPIECE por esa columna (Feitoamao, por ejemplo, tiene a mano un
 * `idx_article_id_created_at`: no se le agrega otro de `article_id`). ALGORITHM=INPLACE, LOCK=NONE,
 * con caída a Schema::table() si el motor no lo acepta. Sin claves foráneas.
 *
 * down() es un NO-OP a propósito: no se puede distinguir un índice creado acá de uno que ya estaba.
 */
class AddIndexesStockMovementsComprobantes extends Migration
{
    const TABLA = 'stock_movements';

    /**
     * nombre del índice => columna.
     */
    const INDICES = [
        'sm_article_id_idx'        => 'article_id',
        'sm_provider_order_id_idx' => 'provider_order_id',
        'sm_nota_credito_id_idx'   => 'nota_credito_id',
        'sm_order_id_idx'          => 'order_id',
    ];

    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable(self::TABLA)) {
            return;
        }

        foreach (self::INDICES as $nombre => $columna) {

            if (!Schema::hasColumn(self::TABLA, $columna)) {
                Log::warning('AddIndexesStockMovementsComprobantes: falta la columna, se saltea el índice', ['columna' => $columna]);
                continue;
            }

            if ($this->ya_existe_el_indice($nombre) || $this->ya_hay_indice_que_empieza_por($columna)) {
                continue;
            }

            $this->crear_indice($nombre, $columna);
        }
    }

    /**
     * NO DROPEA NADA (ver el docblock de la clase).
     *
     * @return void
     */
    public function down()
    {
        Log::warning('AddIndexesStockMovementsComprobantes::down() es un no-op a propósito: revertir, si hace falta, se hace a mano por cliente.');
    }

    /**
     * @param  string  $nombre
     * @param  string  $columna
     * @return void
     */
    private function crear_indice($nombre, $columna)
    {
        try {
            DB::statement('ALTER TABLE `'.self::TABLA.'` ADD INDEX `'.$nombre.'` (`'.$columna.'`), ALGORITHM=INPLACE, LOCK=NONE');

            return;
        } catch (\Throwable $e) {
            Log::warning('AddIndexesStockMovementsComprobantes: ALGORITHM=INPLACE, LOCK=NONE no se pudo usar, se cae a Schema::table()', [
                'indice' => $nombre,
                'error'  => $e->getMessage(),
            ]);
        }

        Schema::table(self::TABLA, function (Blueprint $table) use ($nombre, $columna) {
            $table->index([$columna], $nombre);
        });
    }

    /**
     * @param  string  $nombre
     * @return bool
     */
    private function ya_existe_el_indice($nombre)
    {
        $filas = DB::select(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [self::TABLA, $nombre]
        );

        return count($filas) >= 1;
    }

    /**
     * ¿Hay algún índice cuya PRIMERA columna sea esta (y no sea un prefijo de largo)?
     *
     * @param  string  $columna
     * @return bool
     */
    private function ya_hay_indice_que_empieza_por($columna)
    {
        $filas = DB::select(
            'SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND SEQ_IN_INDEX = 1 AND COLUMN_NAME = ? AND SUB_PART IS NULL
              LIMIT 1',
            [self::TABLA, $columna]
        );

        return count($filas) >= 1;
    }
}
