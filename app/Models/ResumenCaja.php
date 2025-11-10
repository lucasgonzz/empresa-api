<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResumenCaja extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        
    }

    function address() {
        return $this->belongsTo(Address::class);
    }

    function employee() {
        return $this->belongsTo(User::class, 'employee_id');
    }

    function turno_caja() {
        return $this->belongsTo(TurnoCaja::class);
    }

    function cajas() {
        return $this->belongsToMany(Caja::class, 'caja_caja_resumen')->withPivot('saldo_apertura', 'saldo_cierre', 'total_ingresos', 'total_egresos');
    }
}
