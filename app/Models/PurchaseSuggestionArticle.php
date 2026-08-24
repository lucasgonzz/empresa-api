<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Línea de una corrida de sugerencia de compra: un artículo a reponer, con
 * la cantidad calculada y a qué proveedor conviene comprarle (ver el
 * docblock de la migración para el detalle de cada columna).
 */
class PurchaseSuggestionArticle extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with(['article', 'provider', 'provider_titular']);
        return $q;
    }

    function article() {
        return $this->belongsTo(Article::class);
    }

    /**
     * Proveedor ELEGIDO para esta línea (oferta vigente más barata en la
     * misma moneda que el titular, o el titular si no hay mejor opción).
     * Null cuando no hay titular ni ninguna oferta vigente: la línea igual
     * se muestra, agrupada como "sin proveedor asignado".
     */
    function provider() {
        return $this->belongsTo(Provider::class);
    }

    /**
     * Proveedor que ya tenía asignado el artículo (articles.provider_id) al
     * momento de la corrida. Se guarda aparte de `provider` para poder
     * mostrar "se cambiaría de proveedor" sin ir a buscarlo a `articles`.
     */
    function provider_titular() {
        return $this->belongsTo(Provider::class, 'provider_id_titular');
    }
}
