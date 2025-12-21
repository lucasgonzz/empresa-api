<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AfipTicket extends Model
{
    protected $guarded = [];
    // protected $dates = ['cae_expired_at'];

    function sale() {
        return $this->belongsTo(Sale::class);
    }

    function afip_information() {
        return $this->belongsTo(AfipInformation::class);
    }

    function afip_tipo_comprobante() {
        return $this->belongsTo(AfipTipoComprobante::class);
    }

    function afip_errors() {
        return $this->hasMany(AfipError::class);
    }

    function afip_observations() {
        return $this->hasMany(AfipObservation::class);
    }
}
