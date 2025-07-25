<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cheque extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('client', 'provider', 'cobrado_por', 'rechazado_por');
    }

    function current_acount() {
        return $this->belongsTo(CurrentAcount::class);
    }

    function cobrado_por() {
        return $this->belongsTo(User::class, 'cobrado_por_id');
    }

    function rechazado_por() {
        return $this->belongsTo(User::class, 'rechazado_por_id');
    }

    function client() {
        return $this->belongsTo(Client::class);
    }

    function provider() {
        return $this->belongsTo(Provider::class);
    }

    function endosado_a_provider() {
        return $this->belongsTo(Provider::class, 'endosado_a_provider_id');
    }

    function caja() {
        return $this->belongsTo(Caja::class);
    }
}
