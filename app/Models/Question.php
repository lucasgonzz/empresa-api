<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    protected $guarded = [];

    public function article() {
        return $this->belongsTo('App\Models\Article');
    	// return $this->belongsTo('App\Models\Article')->withPivot('variant_id');
    }

    public function buyer() {
        return $this->belongsTo('App\Models\Buyer');
    }

    public function user() {
        return $this->belongsTo('App\Models\User');
    }

    public function answer() {
    	return $this->hasOne('App\Models\Answer');
    }
}
