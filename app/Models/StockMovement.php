<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $guarded = [];

    /**
     * `stock_por_deposito` es la foto de todos los depósitos del artículo antes y después del
     * movimiento, guardada como JSON en una columna `text` (ver SetStockPorDeposito). Con el cast
     * se asigna como array y el endpoint del historial la devuelve como objeto.
     */
    protected $casts = [
        'stock_por_deposito' => 'array',
    ];

    function scopeWithAll($q) {
        $q->with('provider', 'from_address', 'to_address', 'article_variant');
    }

    function article() {
        return $this->belongsTo(Article::class);
    }

    function concepto_movement() {
        return $this->belongsTo(ConceptoStockMovement::class, 'concepto_stock_movement_id');
    }

    function article_variant() {
        return $this->belongsTo(ArticleVariant::class);
    }

    function sale() {
        return $this->belongsTo(Sale::class)->withTrashed();
    }

    function provider() {
        return $this->belongsTo(Provider::class);
    }

    function from_address() {
        return $this->belongsTo(Address::class, 'from_address_id');
    }

    function to_address() {
        return $this->belongsTo(Address::class, 'to_address_id');
    }
}
