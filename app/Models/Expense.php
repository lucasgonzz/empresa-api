<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    protected $guarded = [
        'current_acount_payment_method_id', // Removing this from guarded as it will be handled by the many-to-many relationship
    ];

    function scopeWithAll($q) {
        $q->with('payment_methods');
    }

    function expense_concept() {
        return $this->belongsTo(ExpenseConcept::class);
    }

    function payment_methods() {
        return $this->belongsToMany(CurrentAcountPaymentMethod::class, 'expense_current_acount_payment_method')->withPivot('amount', 'caja_id')->withTimestamps();
    }
}
