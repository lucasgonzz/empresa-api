<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AfipInformation extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        
    }

    protected $dates = ['inicio_actividades'];

    public function iva_condition() {
        return $this->belongsTo('App\Models\IvaCondition');
    }
}
