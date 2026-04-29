<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Pedido importado desde Tienda Nube; puede asociarse a un Address (depósito) antes de confirmar y generar la Sale.
 */
class TiendaNubeOrder extends Model
{

    protected $guarded = [];

    /**
     * Carga artículos con imágenes y la dirección / depósito vinculado.
     *
     * @param \Illuminate\Database\Eloquent\Builder $q
     * @return void
     */
    function scopeWithAll($q) {
        $q->with('articles.images', 'address');
    }

    public function articles()
    {
        return $this->belongsToMany(Article::class)->withPivot('amount', 'price');
    }

    /**
     * Dirección o depósito desde el cual se descontará stock al confirmar el pedido (se copia a la venta generada).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function address()
    {
        return $this->belongsTo(Address::class);
    }

}
