<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Call extends Model
{
    function buyer() {
        return $this->belongsTo('App\Models\Buyer');
    }
}
