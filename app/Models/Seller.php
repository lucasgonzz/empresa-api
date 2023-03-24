<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Seller extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        
    }

    public function sellers() {
    	return $this->hasMany('App\Models\Seller');
    }

    public function seller() {
    	return $this->belongsTo('App\Models\Seller');
    }

    public function clients() {
    	return $this->hasMany('App\Models\Client');
    }
}
