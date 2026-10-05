<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A qué categoría y subcategoría cae un artículo en un sistema propuesto (misión
 * categorizacion-tres-modelos, 5/10/2026). Una fila por (propuesta, artículo).
 *
 * Las solapas de la revisión en Alertas → Catálogo → Categorías agrupan estos estados:
 *   - A revisar:     a_revisar.
 *   - Asignados:     aplicada, aprobada.
 *   - Sin categoría: sin_asignar, rechazada.
 * El detalle de cada columna y de cada estado está en la migración `create_category_proposal_items_table`.
 */
class CategoryProposalItem extends Model
{
    // Lo que dice la skill de cada artículo.
    const CONFIANZA_SEGURA  = 'segura';
    const CONFIANZA_DUDOSA  = 'dudosa';
    const CONFIANZA_NINGUNA = 'ninguna';

    const CONFIANZAS = [
        self::CONFIANZA_SEGURA,
        self::CONFIANZA_DUDOSA,
        self::CONFIANZA_NINGUNA,
    ];

    // Dónde está el ítem en el ciclo.
    const ESTADO_PROPUESTA   = 'propuesta';
    const ESTADO_APLICADA    = 'aplicada';
    const ESTADO_A_REVISAR   = 'a_revisar';
    const ESTADO_SIN_ASIGNAR = 'sin_asignar';
    const ESTADO_APROBADA    = 'aprobada';
    const ESTADO_RECHAZADA   = 'rechazada';

    /** Estados que cuentan como "asignados" (el artículo ya tiene su categoría). */
    const ESTADOS_ASIGNADOS = [self::ESTADO_APLICADA, self::ESTADO_APROBADA];

    /** Estados que cuentan como "a revisar". */
    const ESTADOS_A_REVISAR = [self::ESTADO_A_REVISAR];

    /** Estados que cuentan como "sin categoría". */
    const ESTADOS_SIN_CATEGORIA = [self::ESTADO_SIN_ASIGNAR, self::ESTADO_RECHAZADA];

    protected $guarded = [];

    protected $dates = ['revisado_at'];

    /**
     * Convención del workspace: todo modelo nuevo lo declara aunque quede vacío. El payload de un
     * ítem lo arma CategoryProposalLecturaHelper (nombre del artículo, sugerencia, estado actual).
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
     * El artículo.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function article()
    {
        return $this->belongsTo(Article::class, 'article_id');
    }

    /**
     * El nodo-categoría donde cae.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function node()
    {
        return $this->belongsTo(CategoryProposalNode::class, 'node_id');
    }

    /**
     * El nodo-subcategoría donde cae (si tiene).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function sub_node()
    {
        return $this->belongsTo(CategoryProposalNode::class, 'sub_node_id');
    }
}
