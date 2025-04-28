<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Seller extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->withCount('seller_commissions')->with('categories');
    }

    function categories() {
        return $this->belongsToMany(Category::class)->withPivot('percentage');
    }

    public function sellers() {
    	return $this->hasMany('App\Models\Seller');
    }

    public function seller() {
    	return $this->belongsTo('App\Models\Seller');
    }

    public function clients() {
        return $this->hasMany('App\Models\Client');
    }

    public function seller_commissions() {
        return $this->hasMany('App\Models\SellerCommission');
    }
}
