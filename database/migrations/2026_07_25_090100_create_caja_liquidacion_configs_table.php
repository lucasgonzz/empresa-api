<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración Grupo 223 · Prompt 01 (tesorería — liquidación y comisiones, Capa 1).
 *
 * Crea `caja_liquidacion_configs`: override de la configuración de liquidación/comisión de
 * `cajas`, particular para un método de pago (`current_acount_payment_method_id`) dentro de
 * una caja. Motivo de negocio: Mercado Pago liquida distinto QR, débito y cuotas; los bancos
 * difieren por instrumento.
 *
 * REGLA DE RESOLUCIÓN (importante, no confundir null con 0):
 * Todas las columnas de configuración de esta tabla son nullable a propósito.
 * `null` en cualquiera de ellas significa "heredá el valor por defecto de la caja"
 * (`cajas.dias_liquidacion`, `cajas.comision_porcentaje`, etc.). Un valor explícito
 * (incluido `0` para número, o `false` para el booleano) es un override real y gana sobre
 * el valor de la caja. La lógica que resuelve esta herencia se implementa en el prompt 02
 * de este mismo grupo — acá solo se define el esquema.
 *
 * Sin foreign keys (regla del workspace): `caja_id`, `current_acount_payment_method_id` y
 * `expense_concept_id` se resuelven en Eloquent, no por constraint del motor.
 */
class CreateCajaLiquidacionConfigsTable extends Migration
{
    /**
     * Ejecuta la migración: crea `caja_liquidacion_configs`.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('caja_liquidacion_configs', function (Blueprint $table) {
            $table->id();

            // Caja a la que pertenece este override. Sin FK, index simple para el filtro por caja.
            $table->unsignedBigInteger('caja_id');
            $table->index('caja_id', 'clc_caja_idx');

            // Método de pago sobre el que aplica el override dentro de esa caja. Sin FK.
            $table->unsignedBigInteger('current_acount_payment_method_id');
            $table->index('current_acount_payment_method_id', 'clc_payment_method_idx');

            // Override de días de liquidación. null = heredar de la caja.
            $table->unsignedSmallInteger('dias_liquidacion')->nullable();

            // Override de porcentaje de comisión. null = heredar de la caja.
            $table->decimal('comision_porcentaje', 8, 4)->nullable();

            // Override de concepto de gasto para la comisión automática. null = heredar de la caja.
            $table->unsignedBigInteger('expense_concept_id')->nullable();
            $table->index('expense_concept_id', 'clc_expense_concept_idx');

            // Override de alícuota de IVA de la comisión. null = heredar de la caja.
            $table->decimal('comision_iva_alicuota', 5, 2)->nullable();

            // Override de si el porcentaje ya trae IVA incluido. Booleano NULLABLE a propósito:
            // null = heredar de la caja, no confundir con `false` (que sería un override explícito
            // a "neto"). Se guarda como tinyInteger nullable porque boolean()->nullable() en MySQL
            // vía Laravel es equivalente (tinyint nullable) y deja la intención más clara en el diff.
            $table->boolean('comision_iva_incluido')->nullable();

            // Dueño del registro (owner), para el scope estándar del sistema. Sin FK, index simple.
            $table->unsignedBigInteger('user_id');
            $table->index('user_id', 'clc_user_idx');

            $table->timestamps();

            // Un solo override por combinación caja + método de pago. Compuesto por dos columnas
            // numéricas (no string), así que no viola la regla de no indexar `unique` compuestos
            // de varias columnas string.
            $table->unique(['caja_id', 'current_acount_payment_method_id'], 'clc_caja_payment_method_unq');
        });
    }

    /**
     * Revierte la migración: elimina `caja_liquidacion_configs`.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('caja_liquidacion_configs');
    }
}
