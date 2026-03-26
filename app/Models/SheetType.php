<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SheetType extends Model
{
    protected $guarded = [];

    /**
     * Scope base requerido por fullModel del proyecto.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query;
    }

    /**
     * Relación con perfiles PDF que usan este tipo de hoja.
     */
    public function pdf_column_profiles()
    {
        return $this->hasMany(PdfColumnProfile::class);
    }
}
