<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Memoria del mostrador de un dueño (misión modulo-ia-mostrador).
 *
 * `texto` es la síntesis que redacta la skill /mostrador de las conversaciones que
 * el dueño tuvo sobre sus informes (qué pregunta, qué le importa, qué pidió) y
 * `hasta_ai_message_id` el último ai_messages.id que ya entró en esa síntesis: el
 * endpoint de contexto devuelve solo los mensajes posteriores. Una fila por dueño.
 */
class MostradorMemoria extends Model
{
    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'texto',
        'hasta_ai_message_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'user_id'             => 'integer',
        'hasta_ai_message_id' => 'integer',
    ];

    /**
     * Scope requerido por Controller::fullModel(). No tiene relaciones que cargar.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query;
    }
}
