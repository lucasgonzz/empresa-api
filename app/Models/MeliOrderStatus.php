<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Estado interno de un pedido importado desde Mercado Libre (pendiente / confirmado → venta).
 */
class MeliOrderStatus extends Model
{
    protected $guarded = [];

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    public function scopeWithAll($query)
    {
    }
}
