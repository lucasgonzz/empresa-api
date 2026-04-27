<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTypingState extends Model
{
    /**
     * Campos asignables para upsert de typing.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Cast de fecha de última escritura.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'last_typing_at' => 'datetime',
    ];

    /**
     * Scope estándar para fullModel.
     */
    public function scopeWithAll($query)
    {
        $query->with('ticket');
    }

    /**
     * Ticket asociado al estado de escritura.
     */
    public function ticket()
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }
}

