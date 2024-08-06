<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;

class CurrentAcountCurrentAcountPaymentMethod extends Pivot
{

	public function current_acount() {
        return $this->belongsTo(CurrentAcount::class, 'current_acount_id');
    }
}
