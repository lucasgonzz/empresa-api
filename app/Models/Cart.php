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
     * `envio_direccion` (misión formas-envio-direcciones-maps, 19/10/2026) sigue el mismo camino:
     * la dirección de entrega elegida en el checkout (copia, con las claves del contrato; ver el
     * cast de `Order`), que `tienda-api` pasa del carrito al pedido.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'envio_cotizacion' => 'array',
        'envio_opcion'     => 'array',
        'envio_destino'    => 'array',
        'envio_direccion'  => 'array',
    ];

    function articles() {
    	return $this->belongsToMany('App\Models\Article')->withTrashed()->withPivot('price', 'amount', 'variant_id', 'id')->using(ArticleCart::class);
    }

    function buyer() {
        return $this->belongsTo(Buyer::class);
    }
}
