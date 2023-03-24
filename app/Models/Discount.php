<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Discount extends Model
{
    use SoftDeletes;
    
    protected $guarded = [];

    function scopeWithAll($q) {
        
    }

    public function client() {
        return $this->belongsTo('App\Models\Client');
    }
}
