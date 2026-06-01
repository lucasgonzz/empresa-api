<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasiveUpdate extends Model
{
    /**
     * Campos asignables para registros de actualización masiva.
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * Casts de columnas JSON y fechas.
     *
     * @var array
     */
    protected $casts = [
        'from_filter' => 'boolean',
        'reverted_at' => 'datetime',
    ];

    /**
     * Relaciones para listados API.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query->with('employee', 'user', 'parent_masive_update');
    }

    /**
     * Usuario owner (tenant) de la operación.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Usuario autenticado que ejecutó la operación.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    /**
     * Actualización original cuando este registro es una reversión.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function parent_masive_update()
    {
        return $this->belongsTo(MasiveUpdate::class, 'parent_masive_update_id');
    }

    /**
     * Reversiones hijas asociadas a esta actualización.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function child_reverts()
    {
        return $this->hasMany(MasiveUpdate::class, 'parent_masive_update_id');
    }

    /**
     * Artículos afectados con detalle de cambios en pivot.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function articles()
    {
        return $this->belongsToMany(Article::class, 'masive_update_article')
            ->withPivot('changes_json')
            ->withTimestamps();
    }
}
