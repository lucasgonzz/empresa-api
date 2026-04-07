<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdfColumnProfile extends Model
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
        /**
         * Necesario para fullModel() y respuestas API: sin esto el JSON no trae pivots y el front queda desactualizado.
         */
        return $query->with([
            'sheet_type',
            'pdf_column_options' => function ($relation) {
                $relation->orderByPivot('order', 'asc');
            },
        ]);
    }

    protected $casts = [
        'columns' => 'array',
        'is_default' => 'boolean',
        'is_afip_ticket' => 'boolean',
        'show_totals_on_each_page' => 'boolean',
        /**
         * Flag de visibilidad del total general en el pie del PDF.
         */
        'show_total_in_footer' => 'boolean',
        'margin_mm' => 'integer',
    ];

    /**
     * Relación principal: perfil con múltiples opciones configurables.
     */
    public function pdf_column_options()
    {
        return $this->belongsToMany(PdfColumnOption::class, 'pdf_column_option_profile')
            ->withPivot(['visible', 'order', 'width', 'wrap_content'])
            ->withTimestamps();
    }

    /**
     * Tipo de hoja asociado al perfil para determinar dimensiones de impresión.
     */
    public function sheet_type()
    {
        return $this->belongsTo(SheetType::class);
    }
}

