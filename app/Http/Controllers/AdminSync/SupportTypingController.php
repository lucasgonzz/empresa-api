<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\SupportTypingState;
use App\Services\SupportAgentesSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupportTypingController extends Controller
{
    /**
     * Recibe typing state desde admin-api.
     */
    public function store(Request $request)
    {
        return DB::transaction(function () use ($request) {
            $ticket = SupportTicket::where('uuid', $request->input('ticket_uuid'))->lockForUpdate()->first();
            if (!$ticket) {
                return response()->json(['error' => 'ticket not found'], 404);
            }
            SupportAgentesSync::validar_callback_admin($request, $ticket);
            $typing_state = SupportTypingState::firstOrNew([
                'support_ticket_id' => $ticket->id,
                'actor_type' => $request->input('actor_type', 'admin'),
                'actor_id' => $request->input('actor_id'),
            ]);
            $typing_state->last_typing_at = now();
            $typing_state->save();

            return response()->json(['ok' => true], 200);
        });
    }
}

