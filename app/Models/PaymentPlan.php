<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentPlan extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        // $q->with('sale.current_acount.credit_account');
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function cuotas()
    {
        return $this->hasMany(PaymentPlanCuota::class);
    }
}
