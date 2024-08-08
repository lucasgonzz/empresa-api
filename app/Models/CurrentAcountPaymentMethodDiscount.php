<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CurrentAcountPaymentMethodDiscount extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('current_acount_payment_method');
    }

    function current_acount_payment_method() {
        return $this->belongsTo(CurrentAcountPaymentMethod::class);
    }
}
