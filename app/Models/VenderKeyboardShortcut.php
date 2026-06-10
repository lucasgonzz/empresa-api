<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Atajos de teclado configurables del módulo Vender por usuario autenticado.
 */
class VenderKeyboardShortcut extends Model
{
    protected $guarded = [];

    protected $casts = [
        'shortcuts' => 'array',
        'print_options' => 'array',
    ];

    /**
     * Scope estándar del proyecto para respuestas fullModel.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query;
    }
}
