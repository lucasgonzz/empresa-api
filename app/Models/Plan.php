<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $guarded = [];

    function permissions() {
        return $this->belongsToMany('App\Models\PermissionBeta');
    }

    function plan_features() {
        return $this->belongsToMany('App\Models\PlanFeature')->withPivot('value');
    }
}
