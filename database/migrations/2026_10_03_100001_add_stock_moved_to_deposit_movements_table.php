<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: agrega `stock_moved_at` y `stock_moved_user_id` a `deposit_movements` (misión
 * movimientos-deposito-auditoria, 3/10/2026).
 *
 * Hasta acá el stock de un movimiento de depósito se trasladaba SOLO al pasar el movimiento a un
 * estado llamado "Recibido" (`DepositMovementHelper::check_status()`), y `recibido_at` era la marca
 * de que el traslado ya se había hecho. Lucas pidió separar las dos cosas: el estado pasa a ser una
 * etiqueta configurable y el stock se mueve con un botón propio, "Mover stock", que deja registrado
 * QUIÉN lo apretó y CUÁNDO. Estas dos columnas son ese registro:
 *  - `stock_moved_at`: cuándo se trasladó el stock. NULL = todavía no se movió.
 *  - `stock_moved_user_id`: quién apretó el botón (el usuario autenticado, empleado o dueño).
 *    NULL en los movimientos viejos: no hay forma de saber quién los recibió.
 *
 * 🔴 BACKFILL. Los movimientos viejos que YA trasladaron stock tienen que quedar marcados como
 * "stock movido": si no, aparecerían con el botón "Mover stock" y se podría trasladar dos veces la
 * misma mercadería. Un movimiento viejo trasladó stock si:
 *  - tiene `recibido_at` cargado (la marca que dejaba `check_status()` desde el 5/9/2026), o
 *  - su estado se llama "Recibido" (antes del 5/9/2026 `recibido_at` no siempre se escribía, pero
 *    el estado "Recibido" era la única forma de llegar al traslado), o
 *  - tiene filas en `stock_movements` con su `deposit_movement_id` (ajuste del 3/10/2026). Cubre
 *    los de antes del 5/9 que trasladaron y después volvieron a "En proceso": no tienen
 *    `recibido_at` ni el estado, pero el traslado quedó escrito en el libro de stock.
 * A esos se les pone `stock_moved_at = COALESCE(recibido_at, updated_at, created_at, ahora)`: la
 * fecha real del traslado si se conoce, y si no la mejor aproximación. El usuario queda NULL
 * (desconocido).
 *
 * Y a todo movimiento marcado como movido que no tenga `recibido_at` se le copia `stock_moved_at`
 * ahí (ajuste del 3/10/2026, "dos frentes"): `recibido_at` es la marca que mira la versión
 * anterior del sistema, que puede seguir corriendo sobre la misma base en el otro frente del
 * cliente. Sin ella, pasar uno de estos movimientos a "Recibido" desde el frente viejo volvería a
 * trasladar el stock.
 *
 * El backfill va con query builder y no con modelos, para no depender de cómo esté el modelo el día
 * que se corra. Solo toca filas sin marca, así que es seguro de re-ejecutar.
 *
 * Desde el ajuste del 3/10/2026 `recibido_at` es la guarda COMPARTIDA con la versión anterior: la
 * escribe el botón "Mover stock" y no se acepta del request.
 *
 * Sin foreign keys físicas (regla del repo).
 */
class AddStockMovedToDepositMovementsTable extends Migration
{
    /**
     * Fecha que se le pone a un movimiento viejo que ya trasladó stock: la de recepción si se
     * conoce y, si no, la mejor aproximación disponible.
     */
    const FECHA_DEL_TRASLADO = 'COALESCE(recibido_at, updated_at, created_at, CURRENT_TIMESTAMP)';

    /**
     * Cuántos ids de movimiento se actualizan por UPDATE (para no armar un `IN (...)` gigante).
     */
    const IDS_POR_LOTE = 1000;

    /**
     * Agrega las dos columnas (si faltan) y marca como "stock movido" los movimientos viejos que
     * ya trasladaron stock.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('deposit_movements', 'stock_moved_at')) {
            Schema::table('deposit_movements', function (Blueprint $table) {
                /* NULL = el stock de este movimiento todavía no se movió. */
                $table->timestamp('stock_moved_at')->nullable();
            });
        }

        if (! Schema::hasColumn('deposit_movements', 'stock_moved_user_id')) {
            Schema::table('deposit_movements', function (Blueprint $table) {
                /* Quién apretó "Mover stock". NULL en los movimientos viejos (desconocido). */
                $table->integer('stock_moved_user_id')->nullable();
            });
        }

        /*
            Ids de los estados que se llaman "Recibido". Normalmente es uno solo (el fijo, con
            user_id NULL), pero en una base donde el seeder viejo corrió más de una vez puede haber
            varios con el mismo nombre: se toman todos.
        */
        $estados_recibido = DB::table('deposit_movement_statuses')
                                ->where('name', 'Recibido')
                                ->pluck('id')
                                ->all();

        DB::table('deposit_movements')
            ->whereNull('stock_moved_at')
            ->where(function ($query) use ($estados_recibido) {
                $query->whereNotNull('recibido_at');

                if (count($estados_recibido) > 0) {
                    $query->orWhereIn('deposit_movement_status_id', $estados_recibido);
                }
            })
            ->update([
                /*
                    El plan pide COALESCE(recibido_at, updated_at). Se suman created_at y la hora
                    actual como últimos recursos: una fila con updated_at NULL (insertada a mano o
                    por un import viejo) quedaría si no con stock_moved_at NULL, o sea con el botón
                    "Mover stock" prendido sobre un traslado que ya se hizo. Para el caso normal
                    (updated_at siempre cargado por Eloquent) el resultado es el mismo.
                */
                'stock_moved_at' => DB::raw(self::FECHA_DEL_TRASLADO),
            ]);

        $this->marcar_los_que_tienen_traslado_en_el_libro_de_stock();

        /*
            Toda fila marcada como movida queda también con recibido_at, la marca de la versión
            anterior del sistema (ver el PHPDoc de la clase). Se copia stock_moved_at tal cual:
            para las del primer paso ya es COALESCE(recibido_at, ...), así que no cambia ninguna
            fecha que ya existiera.
        */
        DB::table('deposit_movements')
            ->whereNotNull('stock_moved_at')
            ->whereNull('recibido_at')
            ->update([
                'recibido_at' => DB::raw('stock_moved_at'),
            ]);
    }

    /**
     * Marca como movidos los movimientos que tienen filas en `stock_movements` con su
     * `deposit_movement_id`: el traslado quedó escrito en el libro de stock aunque el movimiento
     * haya vuelto a "En proceso" y no tenga `recibido_at`.
     *
     * Una sola pasada sobre `stock_movements` (SELECT DISTINCT de los ids) y después UPDATE por
     * lotes de ids. A propósito NO es un `UPDATE deposit_movements ... WHERE id IN (SELECT ... )`
     * sobre la misma tabla: MySQL 5.7 / MariaDB lo rechazan con el error 1093 cuando la subconsulta
     * toca la tabla que se actualiza, y hay clientes en esos motores. La cantidad de ids es como
     * mucho la de movimientos de depósito, que es chica.
     *
     * @return void
     */
    private function marcar_los_que_tienen_traslado_en_el_libro_de_stock()
    {
        $ids_con_traslado = DB::table('stock_movements')
                                ->whereNotNull('deposit_movement_id')
                                ->where('deposit_movement_id', '<>', 0)
                                ->distinct()
                                ->pluck('deposit_movement_id')
                                ->all();

        foreach (array_chunk($ids_con_traslado, self::IDS_POR_LOTE) as $lote) {
            DB::table('deposit_movements')
                ->whereIn('id', $lote)
                ->whereNull('stock_moved_at')
                ->update([
                    'stock_moved_at' => DB::raw(self::FECHA_DEL_TRASLADO),
                ]);
        }
    }

    /**
     * Quita las dos columnas si existen.
     *
     * ⚠️ Revertirla pierde el registro de quién y cuándo movió el stock, y con el código viejo el
     * traslado vuelve a depender del estado "Recibido".
     *
     * El `recibido_at` que llenó el backfill NO se borra a propósito: es justamente la marca con la
     * que el código viejo sabe que esos movimientos ya trasladaron, y sin ella los volvería a mover.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('deposit_movements', 'stock_moved_user_id')) {
            Schema::table('deposit_movements', function (Blueprint $table) {
                $table->dropColumn('stock_moved_user_id');
            });
        }

        if (Schema::hasColumn('deposit_movements', 'stock_moved_at')) {
            Schema::table('deposit_movements', function (Blueprint $table) {
                $table->dropColumn('stock_moved_at');
            });
        }
    }
}
