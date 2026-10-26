<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentPlanCuota extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('client', 'sale.current_acount.credit_account');
    }

    function sale() {
        return $this->belongsTo(Sale::class);
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
        // El estado canónico es el que actualiza el cobro; status existe en esquemas históricos.
        return $this->estado !== null ? $this->estado === 'pendiente' : $this->status === 'pending';
    }
}
