<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderOrder extends Model
{
    protected $guarded = [];

    protected $appends = ['iva_breakdown'];

    function scopeWithAll($query) {
        $query->with('articles.addresses', 'articles.images', 'provider', 'provider_order_afip_tickets.provider_order_afip_ticket_ivas', 'provider_order_status', 'provider_order_extra_costs', 'provider_order_discounts');
    }

    function provider_order_discounts() {
        return $this->hasMany(ProviderOrderDiscount::class);
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

    public function getIvaBreakdownAttribute()
    {
        // Usamos tu método existente
        $breakdown = $this->get_iva_breakdown(); // [iva_id => ['neto'=>x, 'iva'=>y]]

        if (empty($breakdown)) {
            return [];
        }

        // Traemos las alícuotas para poder devolver algo útil al front
        $iva_ids = array_keys($breakdown);

        // Ajustá el namespace/modelo si tu Iva está en otro path
        $ivas = \App\Models\Iva::whereIn('id', $iva_ids)->get()->keyBy('id');

        $result = [];

        foreach ($breakdown as $iva_id => $values) {
            $iva = $ivas->get($iva_id);

            $result[] = [
                'iva_id'       => (int) $iva_id,
                'percentage'   => $iva ? (float) ($iva->percentage ?? 0) : null, // ajustá si tu campo no se llama percentage
                'neto'         => round((float) $values['neto'], 2),
                'iva_importe'  => round((float) $values['iva'], 2),
            ];
        }

        // opcional: ordenar por porcentaje
        usort($result, function ($a, $b) {
            return ($a['percentage'] ?? 0) <=> ($b['percentage'] ?? 0);
        });

        return $result;
    }

    function get_iva_breakdown()
    {
        // devuelve: [iva_id => ['neto' => xx, 'iva' => yy]]
        $result = [];

        $this->loadMissing('provider_order_afip_tickets.provider_order_afip_ticket_ivas');

        foreach ($this->provider_order_afip_tickets as $ticket) {
            foreach ($ticket->provider_order_afip_ticket_ivas as $line) {
                $iva_id = $line->iva_id;

                if (!isset($result[$iva_id])) {
                    $result[$iva_id] = ['neto' => 0, 'iva' => 0];
                }

                $result[$iva_id]['neto'] += (float)$line->neto;
                $result[$iva_id]['iva']  += (float)$line->iva_importe;
            }
        }

        return $result;
    }
}
