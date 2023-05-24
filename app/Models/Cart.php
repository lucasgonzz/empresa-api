<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{

	protected $guarded = [];

    function articles() {
    	return $this->belongsToMany('App\Models\Article')->withTrashed()->withPivot('price', 'amount', 'variant_id');
    }
}
