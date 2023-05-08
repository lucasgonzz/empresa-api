<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Commissioner extends Model
{
	protected $guarded = [];
	
    public function sales() {
        return $this->belongsToMany('App\Models\Sales')->withPivot('percentage');
    }
    
    public function seller() {
        return $this->belongsTo('App\Models\Seller');
    }
}
