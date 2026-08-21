<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una página del escaneo de una factura de compra: la foto ya redimensionada y sus metadatos.
 *
 * El binario NO está acá: vive en el disco `local` (privado), en
 * storage/app/provider_order_scans/{user_id}/{uuid}/{orden}.webp. Esta fila guarda la ruta y
 * lo que el modal de revisión necesita mostrar (qué página es, cómo se llamaba el archivo que
 * subió el usuario, cuánto pesa).
 *
 * `provider_order_id` y `user_id` están desnormalizados a propósito: el endpoint que sirve el
 * binario chequea tenencia con un solo where, sin join. Una factura tiene CUIT, razón social y
 * precios; no puede salir por una URL pública ni depender de un join que alguien se olvide de
 * poner.
 */
class ProviderOrderScanImage extends Model
{
    /* Sin restricciones de asignación masiva: se inserta/actualiza vía array. */
    protected $guarded = [];

    /**
     * Scope requerido por Controller::fullModel(). Esta tabla no tiene relaciones propias que
     * valga la pena traer siempre (la única sería volver al escaneo padre, que es de donde se
     * llega acá), así que devuelve el query sin tocar.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    function scopeWithAll($query)
    {
        return $query;
    }

    /**
     * El escaneo del que esta foto es una página.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    function provider_order_scan()
    {
        return $this->belongsTo(ProviderOrderScan::class);
    }
}
