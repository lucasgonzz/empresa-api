<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ArticleSurchage extends Model
{
    protected $guarded = [];

    // Tipos válidos de 'tipo' para recargos de artículo (Capa 1 — costo de adquisición).
    // Prompt 260: se agregan para distinguir la naturaleza contable del recargo.
    const TIPO_TRANSPORTE = 'transporte';
    const TIPO_SEGURO = 'seguro';
    const TIPO_ARANCEL_IMPORTACION = 'arancel_importacion';
    const TIPO_OTRO = 'otro';

    function scopeWithAll($q) {

    }

    function article() {
        return $this->belongsTo('App\Models\Article');
    }
}
