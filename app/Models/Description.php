<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Description extends Model
{
    protected $guarded = [];

    /**
     * Casts de atributos.
     * - ai_generated: booleano (0/1 en BD).
     * - ai_sources: array PHP <-> JSON guardado en la columna `text` (asignar
     *   siempre un array, nunca un string ya serializado).
     * - ai_reviewed_at: instancia Carbon.
     */
    protected $casts = [
        'ai_generated'   => 'boolean',
        'ai_sources'     => 'array',
        'ai_reviewed_at' => 'datetime',
    ];

    function scopeWithAll($q) {

    }

    function article() {
        return $this->belongsTo(Article::class);
    }

    /**
     * Descripciones generadas por IA que todavia no fueron revisadas por un humano.
     *
     * @param \Illuminate\Database\Eloquent\Builder $q
     * @return \Illuminate\Database\Eloquent\Builder
     */
    function scopePendingAiReview($q) {
        return $q->where('ai_generated', true)
                 ->where('ai_confidence', 'low')
                 ->whereNull('ai_reviewed_at');
    }

    /**
     * Descripciones escritas por una persona (nunca se pisan automaticamente).
     *
     * @param \Illuminate\Database\Eloquent\Builder $q
     * @return \Illuminate\Database\Eloquent\Builder
     */
    function scopeHuman($q) {
        return $q->where('ai_generated', false);
    }
}
