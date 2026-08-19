<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración Prompt 260 (Fase 2 — precios/costos, Capa 3).
 *
 * Agrega:
 * - `users.precio_base_incluye_tarjeta`: flag para el comerciante que quiere que el precio de
 *   etiqueta ya incluya la tarjeta más cara y mostrar el efectivo como descuento.
 * - `cuotas.payment_method_id`: nullable, permite reglas de recargo/descuento por cantidad de
 *   cuotas específicas de un método de pago. NULL = regla genérica (comportamiento actual).
 * - `current_acount_payment_method_discounts.cuotas`: nullable, permite que una regla de
 *   descuento/recargo por método aplique solo a una cantidad de cuotas determinada. NULL = aplica
 *   sin importar cuotas (comportamiento actual).
 *
 * Todo aditivo y retrocompatible, sin foreign keys.
 */
class AddPreciosCostosLayer3FlagsColumns extends Migration
{
    /**
     * Ejecuta la migración.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            // Si el precio base (etiqueta) ya incluye el recargo de la tarjeta más cara
            $table->boolean('precio_base_incluye_tarjeta')->default(false)->after('aplicar_iva_al_costo');
        });

        Schema::table('cuotas', function (Blueprint $table) {
            // Método de pago (tarjeta) al que aplica esta regla de cuotas. NULL = regla genérica.
            $table->integer('payment_method_id')->unsigned()->nullable()->after('user_id');
            $table->index('payment_method_id', 'cuotas_payment_method_idx');
        });

        Schema::table('current_acount_payment_method_discounts', function (Blueprint $table) {
            // Cantidad de cuotas a la que aplica esta regla. NULL = aplica sin importar cuotas.
            $table->integer('cuotas')->nullable()->after('discount_percentage');
        });
    }

    /**
     * Revierte la migración.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('precio_base_incluye_tarjeta');
        });

        Schema::table('cuotas', function (Blueprint $table) {
            $table->dropIndex('cuotas_payment_method_idx');
            $table->dropColumn('payment_method_id');
        });

        Schema::table('current_acount_payment_method_discounts', function (Blueprint $table) {
            $table->dropColumn('cuotas');
        });
    }
}
