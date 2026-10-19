<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Dirección de entrega guardada por un comprador de la tienda online (misión
 * formas-envio-direcciones-maps, 19/10/2026), tabla `buyer_addresses`.
 *
 * El comprador la busca en la tienda con el autocompletado de Google Places y puede tener varias:
 * al finalizar un pedido con envío elige una o agrega otra. La crean, editan y borran los
 * endpoints de `tienda-api` (`/api/buyer/addresses`); el ERP no las escribe.
 *
 * Lo que usa el ERP no es esta fila sino la COPIA que quedó en el pedido
 * (`orders.envio_direccion`): el comprador puede editar o borrar la dirección después de comprar y
 * el pedido no puede cambiar. Este modelo existe en `empresa-api` porque la tabla es suya (la
 * migración vive acá y `tienda-api` comparte la base) y para leer las direcciones de un comprador
 * desde el ERP con `Buyer::buyer_addresses()`.
 *
 * No es `Address`: esa es la tabla de sucursales y depósitos del ERP.
 *
 * Columnas: `user_id` (el comercio), `buyer_id`, `street`, `street_number`, `apartment` (piso y
 * depto), `city` (localidad), `province`, `zip_code`, `reference` (entre calles, timbre),
 * `formatted_address` (la línea que devolvió Google), `lat`, `lng`, `place_id` e `is_default` (la
 * que se preselecciona en el checkout).
 */
class BuyerAddress extends Model
{
    protected $guarded = [];

    /**
     * `lat` y `lng` son `decimal(10,7)`, que MySQL devuelve como string ("-31.4100000"): el cast a
     * float los deja como número para el mapa. `is_default` viaja como booleano y no como 0/1.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'lat'        => 'float',
        'lng'        => 'float',
        'is_default' => 'boolean',
    ];

    /**
     * Sin relaciones que cargar: la dirección es una fila plana.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    function scopeWithAll($query) {

    }

    /**
     * El comprador dueño de la dirección.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    function buyer() {
        return $this->belongsTo(Buyer::class);
    }
}
