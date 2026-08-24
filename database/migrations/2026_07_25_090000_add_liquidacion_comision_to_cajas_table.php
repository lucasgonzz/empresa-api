<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración Grupo 223 · Prompt 01 (tesorería — liquidación y comisiones, Capa 1).
 *
 * Agrega a `cajas` la configuración de liquidez y comisión que se aplica **por defecto**
 * a nivel caja (con posibilidad de override por método de pago en `caja_liquidacion_configs`,
 * ver migración hermana de este mismo prompt).
 *
 * Todas las columnas son nullable / con default neutro a propósito: una caja existente,
 * sin tocar, tiene que seguir comportándose exactamente como hoy (liquidación inmediata,
 * sin comisión). `dias_liquidacion = null` representa "efectivo" (liquidación inmediata),
 * no "cero días" — esa distinción es importante y se mantiene también en el override.
 *
 * Sin foreign keys (regla del workspace): `expense_concept_id` se resuelve por Eloquent,
 * no por constraint del motor.
 */
class AddLiquidacionComisionToCajasTable extends Migration
{
    /**
     * Ejecuta la migración: suma las columnas de liquidación/comisión a `cajas`.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('cajas', function (Blueprint $table) {
            // Días que tarda en liquidarse la plata cobrada en esta caja (ej: MP acreditado en 1 o 2 días).
            // null = liquidación inmediata (comportamiento actual, caso "efectivo").
            $table->unsignedSmallInteger('dias_liquidacion')->nullable()->after('current_apertura_caja_id');

            // Porcentaje que se queda la entidad de cobro (ej: 3.5 = 3.5%). null = sin comisión.
            $table->decimal('comision_porcentaje', 8, 4)->nullable()->after('dias_liquidacion');

            // Concepto de gasto que se usa para el gasto automático que genera la comisión.
            // Sin FK (regla del workspace): se resuelve en Eloquent contra expense_concepts.
            $table->unsignedBigInteger('expense_concept_id')->nullable()->after('comision_porcentaje');
            $table->index('expense_concept_id', 'cja_expense_concept_idx');

            // Alícuota de IVA que corresponde a la comisión (default 21%, editable).
            $table->decimal('comision_iva_alicuota', 5, 2)->nullable()->default(21)->after('expense_concept_id');

            // Indica si `comision_porcentaje` ya trae el IVA adentro (true) o es neto (false, default).
            $table->boolean('comision_iva_incluido')->default(0)->after('comision_iva_alicuota');
        });
    }

    /**
     * Revierte la migración: elimina las columnas agregadas a `cajas`.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('cajas', function (Blueprint $table) {
            $table->dropIndex('cja_expense_concept_idx');
            $table->dropColumn([
                'dias_liquidacion',
                'comision_porcentaje',
                'expense_concept_id',
                'comision_iva_alicuota',
                'comision_iva_incluido',
            ]);
        });
    }
}
