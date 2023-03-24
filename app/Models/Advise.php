<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Advise extends Model
{
    protected $guarded = [];

    public function buyer() {
        return $this->belongsTo('App\Models\Buyer');
    }

    public function user() {
        return $this->belongsTo('App\Models\User');
    }

    public function article() {
        return $this->belongsTo('App\Models\Article');
    }
}
