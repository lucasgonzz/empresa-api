<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentPlanCuota extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('client');
    }

    function client() {
        return $this->belongsTo(Client::class);
    }

    public function payment_plan()
    {
        return $this->belongsTo(PaymentPlan::class);
    }

    public function is_pending(): bool
    {
        return $this->status === 'pending';
    }
}
