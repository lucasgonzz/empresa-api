<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class View extends Model
{
    protected $guarded = [];

    function buyer() {
    	return $this->belongsTo('App\Models\Buyer');
    }
}
