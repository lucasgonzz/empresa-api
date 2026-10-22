<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lista de débitos a los que se dirige un pago de cuenta corriente (misión
 * pago-cc-multiples-movimientos, 22/10/2026).
 *
 * Hasta acá un pago podía dirigirse a UN débito (`to_pay_id`). La pantalla ahora deja elegir varios
 * movimientos y registrar un único pago para ellos; esa elección se guarda acá, como JSON
 * (`[12, 15, 18]`), porque la imputación se REHACE leyendo cada pago (check_saldos_y_pagos, "Chequear
 * saldos", un pago con fecha pasada): si la lista no quedara en la base, el primer recálculo la
 * convertiría en FIFO y el pago se movería a otros movimientos sin que nadie lo vea.
 *
 * `to_pay_id` sigue guardando el primero para los consumidores que solo lo conocen a él. Columna
 * nullable sin default: los pagos existentes y los de un solo movimiento quedan en NULL y se
 * comportan exactamente como siempre. Sin relleno, sin foreign keys (convención del repo).
 * Idempotente con `hasColumn`.
 */
class AddToPayIdsToCurrentAcountsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('current_acounts', 'to_pay_ids')) {
            Schema::table('current_acounts', function (Blueprint $table) {
                $table->text('to_pay_ids')->nullable();
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
        if (Schema::hasColumn('current_acounts', 'to_pay_ids')) {
            Schema::table('current_acounts', function (Blueprint $table) {
                $table->dropColumn('to_pay_ids');
            });
        }
    }
}
