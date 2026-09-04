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

    /**
     * Descuentos configurados para este metodo de pago.
     *
     * Es CONFIGURACION, no historial: se va con el metodo cuando se lo borra (ver
     * CurrentAcountPaymentMethodController::destroy). La distincion importa porque este metodo
     * tambien lo referencian ventas, gastos y cuentas corrientes, que son hechos ya ocurridos y
     * NO se tocan.
     */
    function discounts() {
        return $this->hasMany(CurrentAcountPaymentMethodDiscount::class);
    }
    // function cajas_por_defecto() {
    //     return $this->hasMany(DefaultPaymentMethodCaja::class);
    // }
}
