<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una tarjeta de la solapa Categorías: un sistema de categorías propuesto dentro de una corrida
 * (misión categorizacion-tres-modelos, 5/10/2026). Tipos:
 *   nueva    → un árbol nuevo que armó la skill.
 *   mantener → "Mantener las mías": las categorías que el dueño ya tiene, más lo que la skill propone
 *              para los artículos sin categoría.
 */
class CategoryProposal extends Model
{
    const TIPO_NUEVA    = 'nueva';
    const TIPO_MANTENER = 'mantener';

    /** `clave` de la tarjeta "Mantener las mías" (las otras son A, B y C). */
    const CLAVE_MANTENER = 'mantener';

    protected $guarded = [];

    /**
     * Convención del workspace: todo modelo nuevo lo declara aunque quede vacío (los árboles y los
     * conteos los arma CategoryProposalLecturaHelper con consultas agrupadas).
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    public function scopeWithAll($query)
    {
    }

    /**
     * La corrida a la que pertenece.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function run()
    {
        return $this->belongsTo(CategoryProposalRun::class, 'run_id');
    }

    /**
     * Los nodos del árbol (categorías y subcategorías).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function nodes()
    {
        return $this->hasMany(CategoryProposalNode::class, 'proposal_id');
    }

    /**
     * Los ítems: a dónde cae cada artículo en este sistema.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function items()
    {
        return $this->hasMany(CategoryProposalItem::class, 'proposal_id');
    }
}
