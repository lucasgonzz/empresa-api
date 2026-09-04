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

    /**
     * Valores de `origen`: QUIÉN creó el descuento (misión descuentos-proveedor-propagar, 4/9/2026).
     *
     * 🔴 No confundir con `tipo`, que dice qué ES el descuento (su naturaleza contable). `origen`
     * dice de dónde SALIÓ, y es lo único que permite saber si se puede rehacer: los cuatro caminos
     * escriben filas con la misma forma, así que sin esto hay que adivinar mirando el dato.
     *
     * Quién puede rehacer qué:
     *   - FICHA_PROVEEDOR: lo copió el sistema de la ficha del proveedor. Es lo único que una
     *     propagación puede rehacer, porque es lo único que la ficha puede reponer.
     *   - COMPRA / IMPORT: trae la bonificación negociada de esa compra o de esa planilla. La ficha
     *     no tiene con qué reponerlo: se conserva siempre.
     *   - MANUAL: lo cargó una persona desde la ficha del artículo. No se toca nunca.
     *
     * `null` es "origen desconocido" (filas anteriores a la columna que no entraron al backfill) y
     * se trata como no-rehacible: sin saber quién la puso, no se pisa.
     */
    const ORIGEN_FICHA_PROVEEDOR = 'ficha_proveedor';
    const ORIGEN_COMPRA = 'compra';
    const ORIGEN_IMPORT = 'import';
    const ORIGEN_MANUAL = 'manual';

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
