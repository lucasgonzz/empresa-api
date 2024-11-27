<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MovimientoCaja extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('sale');
    }

    function concepto_movimiento_caja() {
        return $this->belongsTo(ConceptoMovimientoCaja::class);
    }

    function caja() {
        return $this->belongsTo(Caja::class);
    }

    function apertura_caja() {
        return $this->belongsTo(AperturaCaja::class);
    }

    function sale() {
        return $this->belongsTo(Sale::class);
    }

}
