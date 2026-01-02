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

    function nota_credito() {
        return $this->belongsTo(CurrentAcount::class, 'nota_credito_id');
    }

    function nota_credito_afip() {
        return $this->hasMany(AfipTicket::class, 'sale_afip_ticket_id');
    }

    function sale_afip() {
        return $this->belongsTo(AfipTicket::class, 'sale_afip_ticket_id');
    }

    function sale_nota_credito() {
        return $this->belongsTo(Sale::class, 'sale_nota_credito_id');
    }
}
