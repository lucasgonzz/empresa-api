<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AfipTicket extends Model
{
    protected $guarded = [];
    // protected $dates = ['cae_expired_at'];

    function sale() {
        return $this->belongsTo(Sale::class);
    }
}
