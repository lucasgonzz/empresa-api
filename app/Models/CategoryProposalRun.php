<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una corrida de la skill /categorizar sobre el catálogo de un dueño (misión
 * categorizacion-tres-modelos, 5/10/2026): agrupa los sistemas de categorías propuestos y lleva el
 * ciclo de vida preparando → lista → elegida (o descartada). Nada toca `categories` ni `articles`
 * hasta que el dueño elige. El detalle de cada columna está en la migración
 * `create_category_proposal_runs_table`.
 *
 * A lo sumo UNA corrida no descartada por dueño.
 */
class CategoryProposalRun extends Model
{
    const ESTADO_PREPARANDO = 'preparando';
    const ESTADO_LISTA      = 'lista';
    const ESTADO_APLICANDO  = 'aplicando';
    const ESTADO_ELEGIDA    = 'elegida';
    const ESTADO_DESCARTADA = 'descartada';

    /** Estados en los que la corrida sigue "viva" para el dueño (no descartada). */
    const ESTADOS_VIGENTES = [
        self::ESTADO_PREPARANDO,
        self::ESTADO_LISTA,
        self::ESTADO_APLICANDO,
        self::ESTADO_ELEGIDA,
    ];

    /** Quién originó la corrida. Hoy solo la skill. */
    const ORIGEN_SKILL = 'skill';

    protected $guarded = [];

    protected $casts = [
        'elegida_con_acceso_maestro' => 'boolean',
        'eliminar_categorias_vacias' => 'boolean',
        'categorias_eliminadas'      => 'array',
        'resultado'                  => 'array',
    ];

    protected $dates = ['elegida_at', 'revision_iniciada_at', 'visto_at', 'descartada_at'];

    /**
     * Convención del workspace: todo modelo nuevo lo declara aunque quede vacío. Los contadores y los
     * árboles de una corrida los arma CategoryProposalLecturaHelper con consultas agrupadas (un
     * `withAll()` acá arrastraría todas las propuestas, nodos e ítems).
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    public function scopeWithAll($query)
    {
    }

    /**
     * Las corridas que no están descartadas.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeVigentes($query)
    {
        return $query->whereIn('estado', self::ESTADOS_VIGENTES);
    }

    /**
     * Las propuestas (tarjetas) de la corrida.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function proposals()
    {
        return $this->hasMany(CategoryProposal::class, 'run_id');
    }

    /**
     * La propuesta que eligió el dueño (si ya eligió).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function propuesta_elegida()
    {
        return $this->belongsTo(CategoryProposal::class, 'propuesta_elegida_id');
    }
}
