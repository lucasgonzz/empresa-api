<?php

namespace App\Http\Controllers\AdminSync;

use App\Events\SupportTicketUpdated;
use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Services\SupportAgentesSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupportTicketController extends Controller
{
    /**
     * Crea ticket espejo cuando el ticket se abre desde admin-api.
     */
    public function store(Request $request)
    {
        // Identificador de usuario remoto dentro de empresa-api.
        $user_id = (int) $request->input('client_user_id');
        // UUID compartido para correlación bi-direccional.
        $ticket_uuid = $request->input('ticket_uuid');

        [$ticket, $creado] = DB::transaction(function () use ($request, $ticket_uuid, $user_id) {
            $existing = SupportTicket::where('uuid', $ticket_uuid)->lockForUpdate()->first();
            if ($existing) {
                SupportAgentesSync::validar_callback_admin($request, $existing);
                return [$existing->load('messages.attachments'), false];
            }

            $ticket = SupportTicket::create([
                'uuid' => $ticket_uuid,
                'user_id' => $user_id,
                'name' => $request->input('name'),
                'status' => $request->input('status', 'open'),
                'opened_at' => now(),
            ]);

            return [$ticket->load('messages.attachments'), true];
        });

        if ($creado) {
            event(new SupportTicketUpdated((int) $ticket->user_id, (int) $ticket->id));
        }

        return response()->json(['model' => $ticket], $creado ? 201 : 200);
    }

    /**
     * Actualiza datos de ticket desde admin-api (estado/nombre).
     */
    public function update(Request $request, $ticket_uuid)
    {
        $ticket = DB::transaction(function () use ($request, $ticket_uuid) {
            $ticket = SupportTicket::where('uuid', $ticket_uuid)->lockForUpdate()->first();
            if (!$ticket) {
                return null;
            }
            SupportAgentesSync::validar_callback_admin($request, $ticket);

            if ($request->has('name')) {
                $ticket->name = $request->input('name');
            }
            if ($request->has('status')) {
                $ticket->status = $request->input('status');
            }
            if ($ticket->status === 'closed') {
                $ticket->closed_at = now();
            }
            if ($ticket->status === 'open') {
                $ticket->closed_at = null;
            }
            $ticket->save();

            return $ticket->load('messages.attachments');
        });
        if (is_null($ticket)) {
            return response()->json(['error' => 'ticket not found'], 404);
        }

        event(new SupportTicketUpdated((int) $ticket->user_id, (int) $ticket->id));

        return response()->json(['model' => $ticket], 200);
    }
}

