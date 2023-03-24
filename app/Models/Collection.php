<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Collection extends Model
{
    protected $fillable = [
    	'admin_id', 'commerce_id', 'collected_months', 
    	'collected_per_month', 'delivered'
    ];

    public function commerce() {
        return $this->belongsTo('App\Models\User', 'commerce_id');
    }
}
