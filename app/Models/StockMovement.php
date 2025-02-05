<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('provider', 'from_address', 'to_address', 'article_variant');
    }

    function article() {
        return $this->belongsTo(Article::class);
    }

    function concepto() {
        return $this->belongsTo(ConceptoStockMovement::class, 'concepto_stock_movement_id');
    }

    function article_variant() {
        return $this->belongsTo(ArticleVariant::class);
    }

    function sale() {
        return $this->belongsTo(Sale::class);
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
