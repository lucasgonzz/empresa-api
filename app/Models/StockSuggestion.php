<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Sugerencia de traslados de stock entre sucursales.
 *
 * Estados (status):
 * - 'pendiente': la corrida está en curso (chunks sin terminar).
 * - 'terminado': todas las líneas calculadas y priorizadas; usable en la vista.
 * - 'error': la corrida falló (ver error_mensaje). Sin este estado, una corrida
 *   caída quedaba 'pendiente' para siempre.
 *
 * origen_generacion:
 * - 'manual': la pidió el usuario desde la pantalla.
 * - 'automatica': la creó el comando sugerencias:generar según la periodicidad
 *   configurada en users.sugerencias_periodicidad.
 *
 * resumen_ia_estado: null (no se pidió — p.ej. sin ANTHROPIC_API_KEY),
 * 'pendiente', 'listo' o 'error' (ver resumen_ia_error). Una falla del resumen
 * nunca cambia el status de la sugerencia.
 */
class StockSuggestion extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        // withCount y no with: el listado necesita la columna "líneas" (una
        // subquery), no las miles de filas de cada corrida.
        $q->withCount('articles');
        return $q;
    }

    public function articles()
    {
        return $this->hasMany(StockSuggestionArticle::class);
    }
}
