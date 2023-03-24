<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SuperBudgetFeature extends Model
{
    protected $guarded = [];

    function super_budget_feature_items() {
        return $this->hasMany('App\Models\SuperBudgetFeatureItem');
    }
}
