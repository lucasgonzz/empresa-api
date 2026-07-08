<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArticleDiscount extends Model
{
    // $guarded vacío = todas las columnas son mass-assignable, incluida `provider_id`
    // (Prompt 305). OJO: no declarar un $fillable explícito acá — en Eloquent, agregar un
    // $fillable no vacío junto con $guarded = [] rompe el mass-assignment del resto de las
    // columnas (percentage, amount, tipo, article_id, etc.), porque deja de aplicar el
    // fallback "guarded vacío = todo permitido".
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

    /**
     * Proveedor que originó este descuento (Prompt 305).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     *
     * Nota: si `provider_id` es null, el descuento es "manual del usuario" y nunca debe
     * ser barrido/reemplazado automáticamente por la lógica de cambio de proveedor (306+).
     */
    function provider() {
        return $this->belongsTo('App\Models\Provider');
    }
}
