<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tipo de hoja de un diseño de PDF: una hoja (A4, con alto) o un rollo de comandera (ticket, sin
 * alto: `height` NULL).
 *
 * Desde la misión diseno-ticket-comandera (9/10/2026) la tabla tiene `user_id`:
 * - NULL: del sistema (A4, Ticket 55 mm, Ticket 80 mm, sembrados por SheetTypeSeeder), los ven
 *   todos los negocios;
 * - con valor: un ancho de comandera que agregó ese dueño ("Ticket 72 mm"), lo ve solo él.
 *
 * 🔴 "Es ticket" lo dice `height === null` y nada más (decisión D2 del plan): no hay columna
 * aparte. Un perfil de PDF con un tipo de hoja así se imprime directo en la comandera (ESC/POS),
 * nunca como PDF (D4).
 */
class SheetType extends Model
{
    protected $guarded = [];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
        'user_id' => 'integer',
    ];

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
     * Los tipos de hoja que puede usar un dueño: los del sistema (user_id NULL) y los suyos.
     *
     * Va agrupado en un where() propio para que el OR no se coma otras condiciones de la consulta.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int                                   $owner_id
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDelSistemaODelDueno($query, $owner_id)
    {
        return $query->where(function ($sub) use ($owner_id) {
            $sub->whereNull('sheet_types.user_id')
                ->orWhere('sheet_types.user_id', (int) $owner_id);
        });
    }

    /**
     * Solo los rollos de comandera (alto NULL).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDeTicket($query)
    {
        return $query->whereNull('sheet_types.height');
    }

    /**
     * ¿Es un rollo de comandera? (alto NULL, decisión D2).
     *
     * @return bool
     */
    public function es_ticket()
    {
        return is_null($this->height);
    }

    /**
     * Relación con perfiles PDF que usan este tipo de hoja.
     */
    public function pdf_column_profiles()
    {
        return $this->hasMany(PdfColumnProfile::class);
    }
}
