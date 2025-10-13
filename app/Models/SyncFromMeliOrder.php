<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SyncFromMeliOrder extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('meli_orders');
    }

    function meli_orders() {
        return $this->belongsToMany(MeliOrder::class)->withPivot('status', 'error_code');
    }
}
