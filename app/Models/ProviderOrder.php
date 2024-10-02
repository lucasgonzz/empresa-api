<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderOrder extends Model
{
    protected $guarded = [];

    function scopeWithAll($query) {
        $query->with('articles.addresses', 'provider', 'provider_order_afip_tickets', 'provider_order_status', 'provider_order_extra_costs');
    }

    function provider_order_afip_tickets() {
        return $this->hasMany('App\Models\ProviderOrderAfipTicket');
    }

    function current_acount() {
        return $this->hasOne('App\Models\CurrentAcount');
    }

    function provider() {
        return $this->belongsTo('App\Models\Provider');
    }

    function provider_order_status() {
        return $this->belongsTo('App\Models\ProviderOrderStatus');
    }

    function articles() {
        return $this->belongsToMany('App\Models\Article')->withTrashed()->withPivot('amount', 'cost', 'notes', 'received', 'iva_id', 'received_cost', 'update_cost', 'cost_in_dollars', 'add_to_articles', 'update_provider', 'address_id', 'price', 'discount');
    }

    function provider_order_extra_costs() {
        return $this->hasMany(ProviderOrderExtraCost::class);
    }
}
