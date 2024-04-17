<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaleModification extends Model
{

    protected $guarded = [];

    function scopeWithAll($query) {
        $query->with('articulos_antes_de_actualizar', 'articulos_despues_de_actualizar');
    }

    function sale() {
        return $this->belongsTo(Sale::class);
    }

    function articulos_antes_de_actualizar() {
        return $this->belongsToMany(Article::class, 'article_sale_modification_antes_de_actualizar')->withPivot('amount');
    }

    function articulos_despues_de_actualizar() {
        return $this->belongsToMany(Article::class, 'article_sale_modification_despues_de_actualizar')->withPivot('amount');
    }
}
