<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArticlesPreImport extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        // 'provider' agregado (grupo 332, 4/8/2026): el front lee la relacion embebida en vez
        // de buscar en el store, que no descarga el catalogo completo de proveedores.
        $q->with('articles', 'provider');
    }

    function articles() {
        return $this->belongsToMany(Article::class)->select('name', 'provider_code')->withPivot('costo_actual', 'costo_nuevo', 'actualizado');
    }

    function provider() {
        return $this->belongsTo(Provider::class);
    }

}
