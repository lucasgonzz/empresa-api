<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeliOrderTag extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        
    }

    public function order()
    {
        return $this->belongsTo(MeliOrder::class, 'meli_order_id');
    }
}
