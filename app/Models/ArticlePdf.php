<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plantilla de usuario para imprimir ofertas de artículos en PDF (layout media página A4).
 */
class ArticlePdf extends Model
{
    protected $guarded = [];

    /**
     * Relaciones y datos extra para respuestas `fullModel` (puede ampliarse).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    public function scopeWithAll($query)
    {
    }
}
