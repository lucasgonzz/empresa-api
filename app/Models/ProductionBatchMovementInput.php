<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionBatchMovementInput extends Model
{
    protected $guarded = [];

    function scopeWithAll($query)
    {
        $query->with('production_batch_movement', 'article', 'address', 'order_production_status');
    }

    public function production_batch_movement()
    {
        return $this->belongsTo(ProductionBatchMovement::class, 'production_batch_movement_id');
    }

    public function article()
    {
        return $this->belongsTo(Article::class);
    }

    public function address()
    {
        return $this->belongsTo(Address::class);
    }

    public function order_production_status()
    {
        return $this->belongsTo(OrderProductionStatus::class);
    }
}