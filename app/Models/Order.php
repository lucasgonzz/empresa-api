<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $guarded = [];

    /**
     * Los tres json del envío por correo (misión zipnova-envios, 14/9/2026): la cotización que hizo
     * el servidor de la tienda, la opción que eligió el comprador y el destinatario con su dirección.
     * Los escribe `tienda-api` al crear el pedido (copiados del carrito); el ERP los lee para
     * generar el envío en Zipnova y para mostrarlos en el modal del pedido. `envio_precio` y
     * `envio_proveedor` son columnas planas y no necesitan cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'envio_cotizacion' => 'array',
        'envio_opcion'     => 'array',
        'envio_destino'    => 'array',
    ];

    /**
     * `envio` va en el withAll porque el listado de pedidos y el modal lo muestran en cada fila
     * (estado del envío, número de seguimiento). Es un hasOne al más nuevo, así que cuesta una
     * sola consulta para todo el listado.
     */
    function scopeWithAll($query) {
        $query->with('order_status', 'articles.images', 'articles.colors', 'articles.sizes', 'cupon', 'buyer', 'payment_method.payment_method_type', 'delivery_zone', 'payment_card_info', 'promocion_vinotecas.images', 'envio');
    }

    function promocion_vinotecas() {
        return $this->belongsToMany(PromocionVinoteca::class)->withTrashed()->withPivot('cost', 'price', 'amount', 'notes');
    }

    function articles() {
        return $this->belongsToMany('App\Models\Article')->withTrashed()->withPivot('cost', 'price', 'amount', 'variant_id', 'color_id', 'size_id', 'with_dolar', 'address_id', 'notes');
    }

    function cart() {
        return $this->hasOne(Cart::class);
    }

    function payment_card_info() {
        return $this->belongsTo('App\Models\PaymentCardInfo');
    }

    function order_status() {
        return $this->belongsTo('App\Models\OrderStatus');
    }

    function cupon() {
        return $this->belongsTo('App\Models\Cupon');
    }

    function buyer() {
        return $this->belongsTo('App\Models\Buyer');
    }

    function payment_method() {
        return $this->belongsTo('App\Models\PaymentMethod');
    }

    function delivery_zone() {
        return $this->belongsTo('App\Models\DeliveryZone');
    }

    function user() {
        return $this->belongsTo('App\Models\User');
    }

    function payment() {
        return $this->hasOne('App\Models\Payment');
    }

    /**
     * El envío generado en Zipnova para este pedido: el MÁS NUEVO, porque un pedido puede tener
     * un envío cancelado (o uno que nunca se pudo crear) y después otro que sí viajó. `latestOfMany()`
     * (Laravel >= 8.42, acá 8.83) resuelve el eager load en una sola consulta, igual que
     * `Buyer::last_message()`.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    function envio() {
        return $this->hasOne(Envio::class)->latestOfMany();
    }
}
