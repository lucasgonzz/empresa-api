<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('payment_method_type');        
    }

    function payment_method_type() {
        return $this->belongsTo('App\Models\PaymentMethodType');
    }

    function credential() {
        return $this->hasOne('App\Models\Credential');
    }
}
