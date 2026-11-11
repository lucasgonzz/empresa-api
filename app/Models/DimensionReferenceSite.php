<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un sitio de referencia de un comercio para la búsqueda masiva de peso y medidas (misión
 * medidas-sitios-de-referencia, 11/11/2026): un dominio donde el comercio sabe que están cargados los pesos y
 * medidas de sus productos. El motor lo busca primero con `site:`.
 *
 * Lo escribe y lo lee DimensionReferenceSiteHelper (que normaliza el dominio y reemplaza la lista entera).
 */
class DimensionReferenceSite extends Model
{
    protected $guarded = [];

    /**
     * Convención del workspace: todo modelo nuevo lo declara aunque quede vacío.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    public function scopeWithAll($query)
    {
    }
}
