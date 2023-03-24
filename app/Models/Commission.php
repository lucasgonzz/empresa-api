<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Commission extends Model
{
    protected $guarded = [];

    function commissioner() {
    	return $this->belongsTo('App\Models\Commissioner');
    }
}
