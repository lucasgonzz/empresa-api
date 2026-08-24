<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un tramo por cantidad de una oferta activa: de `min` a `max` unidades, este
 * porcentaje. `max` NULL = sin techo (es siempre el último tramo), la misma
 * convención de category_price_type_ranges, que es la forma que la tienda YA
 * sabe leer. No cambiarla por un número grande: rompe el lado de allá.
 */
class ClientOfferRange extends Model
{
    protected $guarded = [];

    /**
     * La oferta a la que pertenece el tramo.
     */
    function client_offer() {
        return $this->belongsTo(ClientOffer::class);
    }
}
