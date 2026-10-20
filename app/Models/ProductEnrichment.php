<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductEnrichment extends Model
{
    protected $guarded = [];
    protected $casts = ['product' => 'array', 'classification' => 'array', 'cache_hit' => 'boolean'];

    public function scopeWithAll($query)
    {
    }

    public function payload()
    {
        return $this->only(['uuid', 'status', 'bar_code', 'message', 'product', 'classification', 'cache_hit']);
    }
}
