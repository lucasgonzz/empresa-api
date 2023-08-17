<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductionMovement extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('article', 'employee', 'order_production_status');
    }

    function article() {
        return $this->belongsTo('App\Models\Article');
    }

    function employee() {
        return $this->belongsTo('App\Models\User');
    }

    function order_production_status() {
        return $this->belongsTo('App\Models\OrderProductionStatus');
    }

    function address() {
        return $this->belongsTo('App\Models\Address');
    }
}
