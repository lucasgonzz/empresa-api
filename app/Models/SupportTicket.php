<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SupportTicket extends Model
{
    /**
     * Campos asignables masivamente para alta velocidad en helpers.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Casts de fechas para serialización consistente en API.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /**
     * Genera UUID automáticamente al crear un ticket.
     */
    protected static function booted()
    {
        static::creating(function ($model) {
            // Asegura UUID estable para sincronización con admin-api.
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            // Si no llega fecha de apertura, se setea al alta.
            if (is_null($model->opened_at)) {
                $model->opened_at = now();
            }
        });
    }

    /**
     * Scope estándar del proyecto para expandir relaciones.
     */
    public function scopeWithAll($query)
    {
        $query->with('messages.attachments', 'messages.sender_user');
    }

    /**
     * Expone unread_messages_count: mensajes del operador (admin) aún sin leer (read_at nulo).
     * Vista usuario empresa: "recibidos" = quien responde desde admin-spa.
     */
    public function scopeWithUnreadMessagesCount($query)
    {
        return $query->withCount([
            'messages as unread_messages_count' => function ($sub) {
                $sub->where('sender_type', 'admin')->whereNull('read_at');
            },
        ]);
    }

    /**
     * Relación con el usuario dueño del ticket.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Mensajes de conversación ordenados por creación.
     */
    public function messages()
    {
        return $this->hasMany(SupportMessage::class)->orderBy('id');
    }
}

