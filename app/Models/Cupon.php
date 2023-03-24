<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cupon extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {

    }

    function buyer() {
        return $this->belongsTo('App\Models\Buyer');
    }

    function orders() {
        return $this->belongsToMany('App\Models\Order');
    }
}
