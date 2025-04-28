<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $guarded = [];

    function scopeWithAll($query) {
        $query->with('order_status', 'articles.images', 'articles.colors', 'articles.sizes', 'cupon', 'buyer', 'payment_method.payment_method_type', 'delivery_zone', 'payment_card_info', 'promocion_vinotecas.images');
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
}
