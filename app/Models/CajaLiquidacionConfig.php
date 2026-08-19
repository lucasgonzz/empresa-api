<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Override de configuración de liquidación/comisión de una `Caja`, particular para un método
 * de pago (`current_acount_payment_method_id`) dentro de esa caja (Grupo 223 · Prompt 01).
 *
 * Todas las columnas de configuración son nullable a propósito: `null` significa "heredá el
 * valor de la caja", no "cero"/"desactivado". La resolución de esa herencia vive en el
 * prompt 02 de este mismo grupo (lógica de cálculo), este modelo es solo el esquema.
 */
class CajaLiquidacionConfig extends Model
{
    protected $guarded = [];

    /**
     * Casts de columnas de configuración.
     * `comision_iva_incluido` se castea a boolean pero puede seguir siendo null (override
     * "sin definir" que hereda de la caja) — Eloquent respeta null en cast boolean.
     */
    protected $casts = [
        'comision_porcentaje'    => 'float',
        'comision_iva_alicuota'  => 'float',
        'comision_iva_incluido'  => 'boolean',
    ];

    /**
     * Eager load estándar para respuestas API (fullModel).
     *
     * @param \Illuminate\Database\Eloquent\Builder $q
     * @return void
     */
    function scopeWithAll($q) {
        $q->with('caja', 'payment_method', 'expense_concept');
    }

    /**
     * Caja dueña de este override.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    function caja() {
        return $this->belongsTo(Caja::class);
    }

    /**
     * Método de pago sobre el que aplica este override.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    function payment_method() {
        return $this->belongsTo(CurrentAcountPaymentMethod::class, 'current_acount_payment_method_id');
    }

    /**
     * Concepto de gasto configurado para el gasto automático de comisión (override de la caja).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    function expense_concept() {
        return $this->belongsTo(ExpenseConcept::class);
    }
}
