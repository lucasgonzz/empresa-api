<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionBatchMovement extends Model
{
    protected $guarded = [];

    function scopeWithAll($query)
    {
        $query->with(
            'production_batch',
            'production_batch_movement_type',
            'provider',
            'from_order_production_status',
            'to_order_production_status',
            'address',
            'employee',
            'inputs.article'
        );
    }

    public function production_batch()
    {
        return $this->belongsTo(ProductionBatch::class);
    }

    public function production_batch_movement_type()
    {
        return $this->belongsTo(ProductionBatchMovementType::class);
    }

    public function from_order_production_status()
    {
        return $this->belongsTo(OrderProductionStatus::class, 'from_order_production_status_id');
    }

    public function to_order_production_status()
    {
        return $this->belongsTo(OrderProductionStatus::class, 'to_order_production_status_id');
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function address()
    {
        return $this->belongsTo(Address::class);
    }

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function inputs()
    {
        return $this->hasMany(ProductionBatchMovementInput::class, 'production_batch_movement_id');
    }
}