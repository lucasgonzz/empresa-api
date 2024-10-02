<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AperturaCaja extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('movimientos_caja');
    }

    function caja() {
        return $this->belongsTo(Caja::class);
    }

    function movimientos_caja() {
        return $this->hasMany(MovimientoCaja::class);
    }
}
