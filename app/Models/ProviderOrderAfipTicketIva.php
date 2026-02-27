<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProviderOrderAfipTicketIva extends Model
{
    protected $guarded = [];

    function iva()
    {
        return $this->belongsTo(Iva::class);
    }

    function provider_order_afip_ticket()
    {
        return $this->belongsTo(ProviderOrderAfipTicket::class);
    }

    function scopeWithAll($q) {
        
    }
}
