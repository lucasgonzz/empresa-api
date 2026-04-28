<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use Illuminate\Http\Request;

class SupportTicketController extends Controller
{
    /**
     * Lista tickets del usuario autenticado dueño de la sesión.
     */
    public function index()
    {
        // Obtiene user_id real del usuario logueado (no owner).
        $user_id = $this->userId(false);

        // Trae historial completo ordenado por último movimiento.
        $models = SupportTicket::where('user_id', $user_id)
            ->withAll()
            ->withUnreadMessagesCount()
            ->orderBy('id', 'desc')
            ->get();

        return response()->json(['models' => $models], 200);
    }

    /**
     * Muestra un ticket puntual del usuario autenticado.
     */
    public function show($id)
    {
        // Obtiene user_id real del usuario logueado (no owner).
        $user_id = $this->userId(false);

        // Asegura aislamiento de datos por usuario.
        $model = SupportTicket::where('id', $id)
            ->where('user_id', $user_id)
            ->withAll()
            ->withUnreadMessagesCount()
            ->firstOrFail();

        return response()->json(['model' => $model], 200);
    }

    /**
     * Permite cerrar ticket desde empresa-spa sólo cuando sea necesario.
     */
    public function update(Request $request, $id)
    {
        // Obtiene user_id real del usuario logueado (no owner).
        $user_id = $this->userId(false);
        // Busca ticket del mismo usuario para evitar edición cruzada.
        $ticket = SupportTicket::where('id', $id)->where('user_id', $user_id)->firstOrFail();

        // Actualiza estado si se solicitó explícitamente desde UI.
        if (!empty($request->status)) {
            $ticket->status = $request->status;
        }
        // Actualiza nombre manual del ticket si se envía desde UI.
        if ($request->has('name')) {
            $ticket->name = $request->name;
        }
        // Mantiene coherencia de fecha de cierre según estado.
        if ($ticket->status === 'closed' && is_null($ticket->closed_at)) {
            $ticket->closed_at = now();
        }
        if ($ticket->status === 'open') {
            $ticket->closed_at = null;
        }

        $ticket->save();

        $ticket->loadCount([
            'messages as unread_messages_count' => function ($sub) {
                $sub->where('sender_type', 'admin')->whereNull('read_at');
            },
        ]);
        $ticket->load('messages.attachments');

        return response()->json(['model' => $ticket], 200);
    }
}

