<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TableColumnPreference extends Model
{
    protected $guarded = [];

    protected $casts = [
        'columns' => 'array',
        // Configuración a nivel de modelo/preference_type (ej: modo de búsqueda del buscador general).
        'settings' => 'array',
    ];
}

