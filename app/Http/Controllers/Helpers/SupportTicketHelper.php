<?php

namespace App\Http\Controllers\Helpers;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SupportTicketHelper
{
    /**
     * Obtiene o crea el ticket abierto vigente para un usuario.
     *
     * @param int $user_id
     * @return SupportTicket
     */
    public static function get_or_create_open_ticket(int $user_id): SupportTicket
    {
        return DB::transaction(function () use ($user_id) {
            // Dos primeros envíos de la misma persona deben resolver el mismo hilo abierto.
            User::whereKey($user_id)->lockForUpdate()->firstOrFail();
            $ticket = SupportTicket::where('user_id', $user_id)
                ->where('status', 'open')
                ->where('origen', SupportTicket::ORIGEN_SISTEMA)
                ->orderBy('id', 'desc')
                ->lockForUpdate()
                ->first();

            return $ticket ?? SupportTicket::create([
                'user_id' => $user_id,
                'status' => 'open',
                'opened_at' => now(),
            ]);
        });
    }
}

