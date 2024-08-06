<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pending extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        
    }

    function unidad_frecuencia() {
        return $this->belongsTo(UnidadFrecuencia::class);
    }
}
