<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Impuesto sobre ventas (Capa 2 del motor de precios — Prompt 260).
 *
 * Ej: IIBB, tasas municipales. NO es costo: se traslada al precio final con fórmula de
 * división (esa lógica se implementa en el prompt 261, acá solo se define el esquema).
 * Cuando `apply_to_all` es false, el impuesto aplica solo a los artículos vinculados vía
 * la tabla pivot `article_sale_tax`.
 */
class SaleTax extends Model
{
    protected $guarded = [];

    /**
     * Scope estándar requerido por Controller::fullModel(). Carga las relaciones necesarias
     * para exponer el modelo completo en las respuestas de la API.
     */
    function scopeWithAll($q) {
        $q->with('articles');
    }

    /**
     * Dueño (owner) del impuesto.
     */
    function user() {
        return $this->belongsTo('App\Models\User');
    }

    /**
     * Artículos a los que aplica este impuesto cuando apply_to_all = false.
     */
    function articles() {
        return $this->belongsToMany('App\Models\Article', 'article_sale_tax');
    }
}
