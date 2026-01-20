<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotaCreditoDescription extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        
    }

    function iva() {
        return $this->belongsTo(Iva::class);
    }
}
