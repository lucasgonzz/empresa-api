<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use Illuminate\Http\Request;

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

        $existing = SupportTicket::where('uuid', $ticket_uuid)->first();
        if (!is_null($existing)) {
            return response()->json(['model' => $existing->load('messages.attachments')], 200);
        }

        $ticket = SupportTicket::create([
            'uuid' => $ticket_uuid,
            'user_id' => $user_id,
            'name' => $request->input('name'),
            'status' => $request->input('status', 'open'),
            'opened_at' => now(),
        ]);

        return response()->json(['model' => $ticket->load('messages.attachments')], 201);
    }

    /**
     * Actualiza datos de ticket desde admin-api (estado/nombre).
     */
    public function update(Request $request, $ticket_uuid)
    {
        // Busca ticket de empresa por UUID compartido con admin-api.
        $ticket = SupportTicket::where('uuid', $ticket_uuid)->first();
        if (is_null($ticket)) {
            return response()->json(['error' => 'ticket not found'], 404);
        }

        // Nombre de ticket gestionado desde el centro de soporte.
        if ($request->has('name')) {
            $ticket->name = $request->input('name');
        }
        // Estado de ciclo de vida del ticket.
        if ($request->has('status')) {
            $ticket->status = $request->input('status');
        }
        // Fecha de cierre coherente con estado solicitado.
        if ($ticket->status === 'closed') {
            $ticket->closed_at = now();
        }
        if ($ticket->status === 'open') {
            $ticket->closed_at = null;
        }

        $ticket->save();

        return response()->json(['model' => $ticket->load('messages.attachments')], 200);
    }
}

