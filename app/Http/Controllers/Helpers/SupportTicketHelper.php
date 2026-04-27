<?php

namespace App\Http\Controllers\Helpers;

use App\Models\SupportTicket;

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
        // Busca el último ticket abierto para reutilizar el hilo activo.
        $ticket = SupportTicket::where('user_id', $user_id)
            ->where('status', 'open')
            ->orderBy('id', 'desc')
            ->first();

        // Si no existe ticket abierto, crea uno nuevo en estado open.
        if (is_null($ticket)) {
            $ticket = SupportTicket::create([
                'user_id' => $user_id,
                'status' => 'open',
                'opened_at' => now(),
            ]);
        }

        return $ticket;
    }
}

