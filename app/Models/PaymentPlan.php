<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentPlan extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        
    }

    public function sale()
    {
        // Ajustá si tu modelo de venta es distinto
        return $this->belongsTo(Sale::class);
    }

    // Renombramos la relación a "cuotas" (más claro en español)
    public function cuotas()
    {
        return $this->hasMany(PaymentPlanCuota::class);
    }
}
