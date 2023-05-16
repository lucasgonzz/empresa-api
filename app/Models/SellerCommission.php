<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SellerCommission extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('sale');
    }

    function sale() {
        return $this->belongsTo(Sale::class);
    }

    function seller() {
        return $this->belongsTo(Seller::class);
    }

    function pagada_por() {
        return $this->belongsToMany(CurrentAcount::class);
    }
}
