<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MovimientoCaja extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        
    }

    function caja() {
        return $this->belongsTo(Caja::class);
    }

    function apertura_caja() {
        return $this->belongsTo(AperturaCaja::class);
    }

}
