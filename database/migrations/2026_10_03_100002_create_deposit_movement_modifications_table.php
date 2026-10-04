<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: crea `deposit_movement_modifications` (misión movimientos-deposito-auditoria,
 * 3/10/2026).
 *
 * Una fila por cada vez que alguien cambia los ARTÍCULOS de un movimiento de depósito ya creado
 * (agrega, quita o cambia una cantidad). Guarda quién y cuándo; la foto de los artículos antes y
 * después va en las dos tablas pivot hermanas (`article_deposit_movement_modification_antes` y
 * `..._despues`). Es el mismo patrón que `sale_modifications` en las ventas.
 *
 * Crear el movimiento NO es una modificación: la cuenta arranca en 0. Cambiar solo el estado, los
 * depósitos o las notas tampoco: eso no toca los artículos.
 *
 * Sin foreign keys físicas (regla del repo) y con nombres de índice cortos.
 */
class CreateDepositMovementModificationsTable extends Migration
{
    /**
     * Crea la tabla, con guard hasTable para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('deposit_movement_modifications')) {
            return;
        }

        Schema::create('deposit_movement_modifications', function (Blueprint $table) {
            $table->id();

            /* El movimiento modificado. El historial se pide siempre por este id. */
            $table->integer('deposit_movement_id');
            $table->index('deposit_movement_id', 'dmm_dm_idx');

            /* Quién hizo el cambio (el usuario autenticado: empleado o dueño). */
            $table->integer('user_id')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Borra la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('deposit_movement_modifications');
    }
}
