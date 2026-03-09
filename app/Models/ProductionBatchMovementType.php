<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionBatchMovementType extends Model
{
    protected $guarded = [];

    function scopeWithAll($query)
    {
        // por ahora no carga relaciones extra
    }

    public function production_batch_movements()
    {
        return $this->hasMany(ProductionBatchMovement::class);
    }
}