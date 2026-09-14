<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{

	protected $guarded = [];

    /**
     * Los tres json del envío por correo (misión zipnova-envios, 14/9/2026). Los escribe
     * `tienda-api` durante el checkout y los copia al pedido al confirmar la compra; el ERP no los
     * toca, pero los declara para leer el carrito con la misma forma en los dos proyectos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'envio_cotizacion' => 'array',
        'envio_opcion'     => 'array',
        'envio_destino'    => 'array',
    ];

    function articles() {
    	return $this->belongsToMany('App\Models\Article')->withTrashed()->withPivot('price', 'amount', 'variant_id', 'id')->using(ArticleCart::class);
    }

    function buyer() {
        return $this->belongsTo(Buyer::class);
    }
}
