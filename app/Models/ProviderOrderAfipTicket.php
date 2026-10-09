<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ProviderOrderAfipTicket extends Model
{
    protected $guarded = [];

    function scopeWithAll($query) {
        $query->with('provider_order_afip_ticket_ivas');
    }

    /**
     * Solo las facturas cuya compra todavía existe (misión `factura-compra-tres-defectos`,
     * 9/10/2026).
     *
     * 🔴 Va en TODA consulta que sume facturas de compra por dueño (Posición Fiscal, sus
     * detalles, el rendimiento del mes de `PerformanceHelper`). Esas consultas leían por `user_id` + `issued_at` y nada más, y hay
     * dos clases de factura que no deberían sumar y sumaban:
     *
     *   · la de una compra BORRADA: `ProviderOrder` es borrado duro y no hay clave foránea, así
     *     que hasta esta misión el destroy de la compra dejaba sus facturas vivas (lo nuevo ya no
     *     las deja, pero las viejas siguen en las bases de los clientes hasta que se corra
     *     `facturas-de-compra:huerfanas --aplicar`);
     *   · la que se cargó adentro de una compra NUEVA que después no se guardó: nace con
     *     `temporal_id` y `provider_order_id` NULL, y la ata a la compra `updateRelationsCreated`
     *     recién al guardarla. Tiene `user_id` e `issued_at`, así que también sumaba.
     *
     * Las dos quedan afuera con la misma condición: un NULL nunca matchea el `=` del subquery.
     *
     * Es un `whereExists` explícito contra la clave primaria de `provider_orders` y no un
     * `whereHas('provider_order')`: el `whereHas` arma un EXISTS parecido, pero esconde en la
     * relación qué columnas se cruzan y arrastraría cualquier scope global que mañana se le
     * agregue al modelo de la compra. En una consulta de reporte se prefiere el SQL a la vista.
     * Las columnas van calificadas con el nombre de la tabla porque la consulta de afuera filtra
     * por columnas sueltas (`user_id`, `issued_at`) que también existen en `provider_orders`.
     */
    function scopeDeCompraExistente($query) {
        $query->whereExists(function ($q) {
            $q->select(DB::raw(1))
              ->from('provider_orders')
              ->whereColumn('provider_orders.id', 'provider_order_afip_tickets.provider_order_id');
        });
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
