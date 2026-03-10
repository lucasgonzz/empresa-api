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
        $q->with('type');
    }

    function type() {
        return $this->belongsTo(CAPaymentMethodType::class, 'c_a_payment_method_type_id');
    }
    // function cajas_por_defecto() {
    //     return $this->hasMany(DefaultPaymentMethodCaja::class);
    // }
}
