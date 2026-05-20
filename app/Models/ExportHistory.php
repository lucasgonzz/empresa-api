<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExportHistory extends Model
{
    /**
     * Campos asignables en masa para registros de exportación.
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * Relaciones habituales para respuestas API con fullModel/listados.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query->with('employee', 'user');
    }

    /**
     * Empleado que solicitó la exportación.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    /**
     * Usuario owner (tenant) al que pertenece la exportación.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
