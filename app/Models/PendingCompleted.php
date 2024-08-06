<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PendingCompleted extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        
    }

    function pending() {
        return $this->belongsTo(Pending::class);
    }
}
