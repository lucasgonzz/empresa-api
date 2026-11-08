<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTour extends Model
{
    public const PASOS_POR_TEMA = [
        'crear_ticket' => 3,
        'buscar_articulo_nombre' => 3,
        'buscar_articulo_codigo' => 1,
        'centro_recursos' => 2,
    ];

    public const MOTIVOS_POSITIVOS = ['claro', 'facil', 'ritmo', 'resolvio'];
    public const MOTIVOS_NEGATIVOS = ['confuso', 'lento', 'rapido', 'rebuscado', 'no_resolvio', 'problema_tecnico'];

    protected $guarded = [];

    protected $casts = [
        'textos' => 'array',
        'total_steps' => 'integer',
        'shown_at' => 'datetime',
        'accepted_at' => 'datetime',
        'finished_at' => 'datetime',
        'steps_shown' => 'array',
        'question_step' => 'integer',
        'question_at' => 'datetime',
        'reformulated_at' => 'datetime',
        'feedback' => 'array',
    ];

    public function question_message()
    {
        return $this->belongsTo(SupportMessage::class, 'question_message_id');
    }

    public function para_navegacion(): array
    {
        return $this->only(['uuid', 'tema', 'titulo', 'textos']);
    }

    public function para_seguimiento(): array
    {
        return $this->para_navegacion() + $this->only([
            'source', 'ticket_uuid', 'origin_ticket_uuid', 'previous_uuid', 'state',
            'total_steps', 'steps_shown', 'question', 'question_step', 'question_ticket_uuid', 'feedback',
        ]) + [
            'offered_at' => $this->created_at->toIso8601String(),
            'shown_at' => optional($this->shown_at)->toIso8601String(),
            'accepted_at' => optional($this->accepted_at)->toIso8601String(),
            'finished_at' => optional($this->finished_at)->toIso8601String(),
            'question_at' => optional($this->question_at)->toIso8601String(),
            'question_delivered_at' => optional(optional($this->question_message)->synced_to_admin_at)->toIso8601String(),
            'reformulated_at' => optional($this->reformulated_at)->toIso8601String(),
        ];
    }
}
