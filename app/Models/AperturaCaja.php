<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AperturaCaja extends Model
{
    protected $guarded = [];

    protected $dates = ['cerrada_at'];

    function scopeWithAll($q) {
        $q->with('movimientos_caja');
    }

    function caja() {
        return $this->belongsTo(Caja::class);
    }

    function movimientos_caja() {
        return $this->hasMany(MovimientoCaja::class);
    }

    function usuario_apertura() {
        return $this->belongsTo(User::class, 'apertura_employee_id');
    }

    function usuario_cierre() {
        return $this->belongsTo(User::class, 'cierre_employee_id');
    }
}
