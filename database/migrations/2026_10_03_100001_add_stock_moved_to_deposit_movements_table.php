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
 *    el estado "Recibido" era la única forma de llegar al traslado).
 * A esos se les pone `stock_moved_at = COALESCE(recibido_at, updated_at)`: la fecha real del
 * traslado si se conoce, y si no la última vez que se tocó el movimiento (la mejor aproximación).
 * El usuario queda NULL (desconocido).
 *
 * El backfill va con query builder y no con modelos, para no depender de cómo esté el modelo el día
 * que se corra. Solo toca filas con `stock_moved_at` NULL, así que es seguro de re-ejecutar.
 *
 * `recibido_at` queda como columna vieja: no se escribe más ni se borra (compatibilidad con
 * versiones anteriores del SPA y con lo que ya está en la base).
 *
 * Sin foreign keys físicas (regla del repo).
 */
class AddStockMovedToDepositMovementsTable extends Migration
{
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
                'stock_moved_at' => DB::raw('COALESCE(recibido_at, updated_at, created_at, CURRENT_TIMESTAMP)'),
            ]);
    }

    /**
     * Quita las dos columnas si existen.
     *
     * ⚠️ Revertirla pierde el registro de quién y cuándo movió el stock, y con el código viejo el
     * traslado vuelve a depender del estado "Recibido".
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
