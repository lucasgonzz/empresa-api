<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SuperBudget extends Model
{
    protected $guarded = [];
    protected $dates = ['offer_validity'];

    function scopeWithAll($query) {
        $query->with('super_budget_features.super_budget_feature_items');
    }

    function super_budget_titles() {
        return $this->hasMany('App\Models\SuperBudgetTitle');
    }

    function super_budget_features() {
        return $this->hasMany('App\Models\SuperBudgetFeature');
    }
}
