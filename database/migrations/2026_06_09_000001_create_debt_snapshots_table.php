<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración para la tabla de snapshots de deuda diarios.
 * Almacena el saldo total de clientes y proveedores al final de cada día,
 * separado por moneda (ARS y USD), para permitir análisis histórico de deuda.
 */
class CreateDebtSnapshotsTable extends Migration
{
    /**
     * Crea la tabla debt_snapshots con índice único por usuario y fecha
     * para evitar duplicados al ejecutar el comando diario.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('debt_snapshots', function (Blueprint $table) {

            /* Clave primaria autoincremental */
            $table->id();

            /* ID del usuario dueño del negocio (owner_id null en users) */
            $table->integer('user_id');

            /* Fecha a la que corresponde el snapshot (el día capturado) */
            $table->date('date');

            /* Suma de saldos de credit_accounts de clientes en ARS */
            $table->decimal('deuda_clientes', 22, 2)->nullable();

            /* Suma de saldos de credit_accounts de clientes en USD */
            $table->decimal('deuda_clientes_usd', 22, 2)->nullable();

            /* Suma de saldos de credit_accounts de proveedores en ARS */
            $table->decimal('deuda_proveedores', 22, 2)->nullable();

            /* Suma de saldos de credit_accounts de proveedores en USD */
            $table->decimal('deuda_proveedores_usd', 22, 2)->nullable();

            $table->timestamps();

            /* Índice único compuesto: un snapshot por usuario por día */
            $table->unique(['user_id', 'date'], 'debt_snapshots_user_date_unique');
        });
    }

    /**
     * Elimina la tabla debt_snapshots.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('debt_snapshots');
    }
}
