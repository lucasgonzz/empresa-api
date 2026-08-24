<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DefaultPaymentMethodCaja extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {

    }

    /**
     * Moneda para la que aplica esta caja por defecto del método de pago (Grupo 223 · Prompt 01).
     * Necesario porque, con ventas en dólares, la caja por defecto de un método de pago se
     * resuelve también por moneda, no solo por método de pago.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    function moneda() {
        return $this->belongsTo(Moneda::class);
    }
}
