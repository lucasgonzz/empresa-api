<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una descripción que la IA no pudo confirmar, esperando que alguien la apruebe (misión
 * alertas-descripciones, 14/10/2026). Una fila por artículo y por propuesta, con sus 1 a 3 secciones
 * adentro (`sections`).
 *
 * 🔴 Mientras está `a_revisar` no es una descripción del artículo: no la ve la tienda ni la ficha del
 * ERP. Al aprobarla, AiDescriptionReviewHelper crea las filas de `descriptions` y la propuesta queda
 * `aprobada`; al descartarla queda `descartada`, y eso es lo que le impide al lote automático volver
 * a proponer lo mismo. El porqué completo está en la migración `create_article_description_proposals_table`.
 *
 * Una sola `a_revisar` por artículo: lo garantizan el lote (no le propone nada a un artículo que ya
 * tiene una) y AiDescriptionReviewHelper::guardar_resultado() (una nueva reemplaza a la anterior).
 */
class ArticleDescriptionProposal extends Model
{
    const STATUS_A_REVISAR  = 'a_revisar';
    const STATUS_APROBADA   = 'aprobada';
    const STATUS_DESCARTADA = 'descartada';

    protected $guarded = [];

    /**
     * - sections: [{title, content}] (asignar siempre un array, nunca un string ya serializado).
     * - ai_sources: [{source, url, title}], mismo formato que `descriptions.ai_sources`.
     */
    protected $casts = [
        'sections'    => 'array',
        'ai_sources'  => 'array',
        'resolved_at' => 'datetime',
    ];

    /**
     * Convención del workspace: todo modelo nuevo lo declara aunque quede vacío. El payload que ve la
     * SPA lo arma AiDescriptionReviewHelper.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    public function scopeWithAll($query)
    {
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function article()
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * Las que esperan revisión.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeARevisar($query)
    {
        return $query->where('status', self::STATUS_A_REVISAR);
    }
}
