<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderOrderAfipTicket extends Model
{
    protected $guarded = [];

    function scopeWithAll($query) {
        $query->with('provider_order_afip_ticket_ivas');
    }

    function provider_order() {
        return $this->belongsTo(ProviderOrder::class);
    }

    function provider() {
        return $this->belongsTo(Provider::class);
    }

    function provider_order_afip_ticket_ivas() {
        return $this->hasMany(ProviderOrderAfipTicketIva::class);
    }
}
