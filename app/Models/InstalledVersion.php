<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una versión del sistema instalada en un comercio, con la fecha real de instalación.
 * Ver InstalledVersionHelper para las reglas de registro.
 */
class InstalledVersion extends Model
{
    protected $guarded = [];

    protected $casts = [
        'installed_at' => 'datetime',
    ];

    /**
     * Sin relaciones que cargar; se declara igual por convención del proyecto.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    public function scopeWithAll($query) {}
}
