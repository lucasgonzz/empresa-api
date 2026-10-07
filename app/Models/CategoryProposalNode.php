<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un nodo del árbol de un sistema propuesto (misión categorizacion-tres-modelos, 5/10/2026): una
 * categoría (`parent_id` NULL) o una subcategoría (`parent_id` = id del nodo-categoría). Solo hay dos
 * niveles. Los `real_*` se llenan al aplicar la elección; ver la migración
 * `create_category_proposal_nodes_table`.
 */
class CategoryProposalNode extends Model
{
    protected $guarded = [];

    protected $casts = [
        'real_creado' => 'boolean',
    ];

    /**
     * Convención del workspace: todo modelo nuevo lo declara aunque quede vacío.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    public function scopeWithAll($query)
    {
    }

    /**
     * La propuesta a la que pertenece.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function proposal()
    {
        return $this->belongsTo(CategoryProposal::class, 'proposal_id');
    }

    /**
     * El nodo-categoría padre (solo las subcategorías lo tienen).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function parent()
    {
        return $this->belongsTo(CategoryProposalNode::class, 'parent_id');
    }

    /**
     * Las subcategorías de este nodo-categoría.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function children()
    {
        return $this->hasMany(CategoryProposalNode::class, 'parent_id');
    }

    /**
     * ¿Es una categoría (nivel superior)?
     *
     * @return bool
     */
    public function es_categoria()
    {
        return is_null($this->parent_id);
    }
}
