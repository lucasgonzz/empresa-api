<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SupportMessage extends Model
{
    /**
     * Campos asignables masivamente para velocidad en create().
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Casts de fechas de estado y sincronización.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'synced_to_admin_at' => 'datetime',
    ];

    /**
     * Genera UUID automáticamente para correlación entre APIs.
     */
    protected static function booted()
    {
        static::creating(function ($model) {
            // UUID único para idempotencia de sincronización.
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Scope estándar del proyecto para respuestas fullModel.
     */
    public function scopeWithAll($query)
    {
        $query->with('ticket', 'attachments', 'sender_user');
    }

    /**
     * Relación al ticket contenedor.
     */
    public function ticket()
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    /**
     * Usuario local emisor cuando sender_type=user.
     */
    public function sender_user()
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    /**
     * Adjuntos multimedia del mensaje.
     */
    public function attachments()
    {
        return $this->hasMany(SupportMessageAttachment::class);
    }
}

