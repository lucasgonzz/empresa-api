<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PermissionBeta extends Model
{
    protected $guarded = [];

    function plans() {
        return $this->belongsToMany('App\Models\Plan');
    }

    function extencion() {
        return $this->belongsTo('App\Models\Extencion');
    }
}
