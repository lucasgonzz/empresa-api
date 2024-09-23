<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CurrentAcountPaymentMethod extends Model
{
    protected $guarded = [];

    public function sales(){
        return $this->belongsToMany(Sale::class);
    }

    function scopeWithAll($q) {
        // $q->with('caja_por_defecto');
    }

    // function cajas_por_defecto() {
    //     return $this->hasMany(DefaultPaymentMethodCaja::class);
    // }
}
