<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdfColumnOption extends Model
{
    protected $guarded = [];

    /**
     * Eager loading estándar vía fullModel(); ampliar con ->with() cuando haga falta.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query;
    }

    /**
     * Relación inversa: una opción puede pertenecer a muchos perfiles.
     */
    public function pdf_column_profiles()
    {
        return $this->belongsToMany(PdfColumnProfile::class, 'pdf_column_option_profile')
            ->withPivot(['visible', 'order', 'width', 'wrap_content'])
            ->withTimestamps();
    }
}

