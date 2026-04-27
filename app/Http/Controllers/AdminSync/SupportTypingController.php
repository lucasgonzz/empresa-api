<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\SupportTypingState;
use Illuminate\Http\Request;

class SupportTypingController extends Controller
{
    /**
     * Recibe typing state desde admin-api.
     */
    public function store(Request $request)
    {
        // Ticket correlacionado por UUID compartido.
        $ticket = SupportTicket::where('uuid', $request->input('ticket_uuid'))->first();
        if (is_null($ticket)) {
            return response()->json(['error' => 'ticket not found'], 404);
        }

        // Persiste snapshot de "escribiendo" para actor remoto.
        $typing_state = SupportTypingState::firstOrNew([
            'support_ticket_id' => $ticket->id,
            'actor_type' => $request->input('actor_type', 'admin'),
            'actor_id' => $request->input('actor_id'),
        ]);
        $typing_state->last_typing_at = now();
        $typing_state->save();

        return response()->json(['ok' => true], 200);
    }
}

