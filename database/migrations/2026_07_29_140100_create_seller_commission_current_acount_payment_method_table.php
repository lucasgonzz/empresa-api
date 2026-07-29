<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grupo 268 · Prompt 01 — pivot de metodos de pago para el pago a un vendedor, espejo exacto de
 * current_acount_current_acount_payment_method (con las columnas que ese pivot acumulo en sus
 * tres migraciones, creadas todas de una vez aca).
 *
 * No replica las columnas de cheque (bank, fecha_emision, fecha_pago, cobrado_at,
 * check_status_id, num, credit_card_id, credit_card_payment_plan_id): el pago a un vendedor con
 * cheque queda fuera del alcance de este grupo, agregarlas ahora seria esquema muerto.
 */
class CreateSellerCommissionCurrentAcountPaymentMethodTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('seller_commission_current_acount_payment_method', function (Blueprint $table) {
            $table->id();
            $table->integer('seller_commission_id')->unsigned();
            $table->integer('current_acount_payment_method_id')->unsigned();
            $table->integer('caja_id')->nullable();
            $table->decimal('amount', 14,2)->nullable();
            $table->decimal('amount_cotizado', 30,2)->nullable();
            $table->decimal('cotizacion', 30,2)->nullable();
            $table->integer('moneda_id')->nullable();
            $table->integer('cuota_id')->nullable();
            $table->integer('user_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('seller_commission_current_acount_payment_method');
    }
}
