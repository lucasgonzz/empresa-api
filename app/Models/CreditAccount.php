<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CreditAccount extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        
    }

    function current_acount() {
        return $this->hasMany(CurrentAcount::class);
    }

    function moneda() {
        return $this->belongsTo(Moneda::class);
    }
    
    public function model()
    {
        return $this->morphTo(__FUNCTION__, 'model_name', 'model_id');
    }
}
