<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo de marcas del sistema.
 *
 * @property string|null $image_url URL de la imagen asociada a la marca.
 */
class Brand extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        
    }
}
