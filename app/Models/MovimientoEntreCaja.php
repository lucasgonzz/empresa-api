<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MovimientoEntreCaja extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        
    }

    function from_caja() {
        return $this->belongsTo(Caja::class, 'from_caja_id');
    }

    function to_caja() {
        return $this->belongsTo(Caja::class, 'to_caja_id');
    }
}
