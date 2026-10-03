<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: agrega `user_id` a `deposit_movement_statuses` (misión movimientos-deposito-auditoria,
 * 3/10/2026).
 *
 * Hasta acá los estados de los movimientos de depósito eran un catálogo GLOBAL de dos filas ("En
 * proceso" y "Recibido") que sembraba `DepositMovementStatusSeeder`. Lucas pidió que cada comercio
 * pueda crear, editar y borrar los suyos desde ABM > Inventario.
 *
 * Cómo queda:
 *  - `user_id` NULL  = estado FIJO del sistema. Son las filas que ya existen ("En proceso" y
 *    "Recibido"): aparecen siempre, no se renombran ni se borran. En las bases compartidas
 *    (`u767360347_empresa`, 51 comercios adentro) esas dos filas las usan todos a la vez, así que
 *    tocarlas le cambiaría el estado a los movimientos de otro comercio.
 *  - `user_id` = id del dueño = estado PROPIO de ese comercio.
 *
 * Por eso la columna es nullable y la migración NO escribe nada en las filas existentes: quedan
 * en NULL, que es justamente "fijo".
 *
 * Sin foreign key física (regla del repo) y con nombre de índice corto.
 */
class AddUserIdToDepositMovementStatusesTable extends Migration
{
    /**
     * Agrega la columna y su índice si todavía no existen (guard hasColumn para que sea segura de
     * re-ejecutar).
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('deposit_movement_statuses', 'user_id')) {
            Schema::table('deposit_movement_statuses', function (Blueprint $table) {
                /* NULL = estado fijo del sistema; con valor = estado propio de ese dueño. */
                $table->integer('user_id')->nullable()->after('name');

                /* El listado de estados filtra siempre por dueño (fijos + los suyos). */
                $table->index('user_id', 'dms_user_idx');
            });
        }
    }

    /**
     * Quita el índice y la columna si existen.
     *
     * ⚠️ Revertirla deja los estados propios de cada comercio como si fueran globales (los vería
     * todo el mundo). Es el costo de volver atrás; no hay forma de bajarla sin perder ese dato.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('deposit_movement_statuses', 'user_id')) {
            Schema::table('deposit_movement_statuses', function (Blueprint $table) {
                $table->dropIndex('dms_user_idx');
                $table->dropColumn('user_id');
            });
        }
    }
}
