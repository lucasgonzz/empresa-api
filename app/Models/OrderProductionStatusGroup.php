<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderProductionStatusGroup extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('order_production_statuses');
    }

    public function order_production_statuses()
    {
        return $this->hasMany(OrderProductionStatus::class);
    }
}
