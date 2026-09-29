<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un "Diseño de etiquetas de góndola" de un negocio (misión disenos-etiquetas-gondola, 29/9/2026).
 *
 * - `user_id` es siempre el DUEÑO (`$this->userId()`).
 * - `price_type_id`: la lista para la que el sistema generó el diseño (seeder / alta de lista), o
 *   null si lo creó o duplicó el usuario.
 * - `diseno`: el JSON normalizado por `ArticleTicketDesignHelper::normalizar_diseno()`. Con el cast
 *   `array` viaja al SPA como objeto.
 */
class ArticleTicketDesign extends Model
{
    /**
     * @var array
     */
    protected $fillable = [
        'user_id',
        'name',
        'price_type_id',
        'position',
        'diseno',
    ];

    /**
     * @var array
     */
    protected $casts = [
        'diseno'        => 'array',
        'position'      => 'integer',
        'price_type_id' => 'integer',
    ];

    /**
     * Scope que piden `fullModel()` y los index de los ABM. Vacío a propósito: el diseño no tiene
     * relaciones que cargar.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    function scopeWithAll($query)
    {
    }
}
