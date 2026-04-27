<?php

namespace App\Http\Controllers;

use App\Events\SupportMessageReceived;
use App\Http\Controllers\Helpers\SupportSyncHelper;
use App\Http\Controllers\Helpers\SupportTicketHelper;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportTicket;
use App\Models\SupportTypingState;
use Illuminate\Http\Request;

class SupportMessageController extends Controller
{
    /**
     * Crea mensaje de soporte y abre ticket si aún no existe uno abierto.
     */
    public function store(Request $request)
    {
        // Obtiene user_id real del usuario logueado (no owner).
        $user_id = $this->userId(false);
        // Ticket objetivo enviado por frontend (puede ser null).
        $ticket_id = $request->input('support_ticket_id');
        // Tipo de mensaje (text/audio/image).
        $kind = $request->input('kind', 'text');

        // Resuelve ticket solicitado o crea/reutiliza ticket abierto.
        if (!is_null($ticket_id)) {
            $ticket = SupportTicket::where('id', $ticket_id)
                ->where('user_id', $user_id)
                ->firstOrFail();
        } else {
            $ticket = SupportTicketHelper::get_or_create_open_ticket($user_id);
        }

        // Si el ticket está cerrado, se bloquea envío de nuevos mensajes.
        if ($ticket->status !== 'open') {
            return response()->json(['error' => 'ticket closed'], 422);
        }

        // Normaliza texto del mensaje para no persistir strings vacíos.
        $body = $request->input('body');
        if (is_string($body)) {
            $body = trim($body);
        }

        // Crea mensaje base del usuario.
        $message = SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type' => 'user',
            'sender_user_id' => $user_id,
            'kind' => $kind,
            'body' => $body,
            'delivered_at' => now(),
        ]);

        // Si llega archivo, lo guarda en disco public y crea adjunto.
        if ($request->hasFile('attachment')) {
            // Referencia al archivo subido en request.
            $attachment_file = $request->file('attachment');
            // Directorio lógico de soporte para mantener orden.
            $directory = 'support_messages/' . $ticket->id;
            // Persistencia del binario en disco público.
            $stored_path = $attachment_file->store($directory, 'public');

            SupportMessageAttachment::create([
                'support_message_id' => $message->id,
                'disk' => 'public',
                'path' => $stored_path,
                'mime' => $attachment_file->getMimeType(),
                'size' => $attachment_file->getSize(),
            ]);
        }

        // Recarga mensaje con relaciones antes de emitir evento y responder.
        $message = SupportMessage::where('id', $message->id)->withAll()->first();

        // Emite evento realtime para refrescar chat local en empresa-spa.
        event(new SupportMessageReceived($message->id, $user_id));
        // Dispara sincronización best-effort hacia admin-api.
        SupportSyncHelper::sync_message_to_admin($message);

        return response()->json(['model' => $message], 201);
    }

    /**
     * Marca como leído un mensaje recibido desde admin.
     */
    public function mark_read($id)
    {
        // Obtiene user_id real del usuario logueado (no owner).
        $user_id = $this->userId(false);
        // Mensaje sólo válido si pertenece a ticket del usuario actual.
        $message = SupportMessage::where('id', $id)
            ->whereHas('ticket', function ($query) use ($user_id) {
                $query->where('user_id', $user_id);
            })
            ->firstOrFail();

        $message->read_at = now();
        $message->save();
        // Sincroniza lectura al admin-api para estados de doble tilde.
        SupportSyncHelper::sync_read_to_admin($message);

        return response()->json(['ok' => true], 200);
    }

    /**
     * Registra estado "escribiendo" del usuario en un ticket.
     */
    public function typing(Request $request)
    {
        // Obtiene user_id real del usuario logueado (no owner).
        $user_id = $this->userId(false);
        // Ticket activo sobre el cual se informa typing.
        $ticket = SupportTicket::where('id', $request->input('support_ticket_id'))
            ->where('user_id', $user_id)
            ->firstOrFail();

        // Upsert simple del estado de escritura por actor.
        $typing_state = SupportTypingState::firstOrNew([
            'support_ticket_id' => $ticket->id,
            'actor_type' => 'user',
            'actor_id' => $user_id,
        ]);
        $typing_state->last_typing_at = now();
        $typing_state->save();

        // Sincroniza typing al admin-api para mostrar indicador remoto.
        SupportSyncHelper::sync_typing_to_admin($ticket->uuid, $user_id);

        return response()->json(['ok' => true], 200);
    }
}

