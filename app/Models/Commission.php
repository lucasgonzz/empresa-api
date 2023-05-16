<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Commission extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('for_all_sellers', 'for_only_sellers', 'except_sellers');
    }
    
    public function for_all_sellers() {
        return $this->belongsToMany('App\Models\Seller', 'commission_for_all_seller')->withPivot('percentage');
    }
    
    public function for_only_sellers() {
        return $this->belongsToMany('App\Models\Seller', 'commission_for_only_seller');
    }
    
    public function except_sellers() {
        return $this->belongsToMany('App\Models\Seller', 'commission_except_seller');
    }
}
