<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArticleDiscount extends Model
{
    protected $guarded = [];

    // Tipos válidos de 'tipo' para descuentos de artículo (Capa 1 — costo de adquisición).
    // Prompt 260: se agregan para distinguir la naturaleza contable del descuento.
    const TIPO_BONIFICACION_PROVEEDOR = 'bonificacion_proveedor';
    const TIPO_OTRO = 'otro';

    function scopeWithAll($q) {

    }

    function article() {
        return $this->belongsTo('App\Models\Article');
    }
}
