<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Caja extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('current_acount_payment_methods', 'users');
    }

    function current_acount_payment_methods() {
        return $this->belongsToMany(CurrentAcountPaymentMethod::class);
    }

    function users() {
        return $this->belongsToMany(User::class);
    }
}
