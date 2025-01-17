<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaleModification extends Model
{

    protected $guarded = [];

    function scopeWithAll($query) {
        $query->with('articulos_antes_de_actualizar', 'articulos_despues_de_actualizar', 'user');
    }

    function sale() {
        return $this->belongsTo(Sale::class);
    }

    function user() {
        return $this->belongsTo(User::class);
    }

    function articulos_antes_de_actualizar() {
        return $this->belongsToMany(Article::class, 'article_sale_modification_antes_de_actualizar')->withPivot('amount', 'checked_amount');
    }

    function articulos_despues_de_actualizar() {
        return $this->belongsToMany(Article::class, 'article_sale_modification_despues_de_actualizar')->withPivot('amount', 'checked_amount');
    }
}
