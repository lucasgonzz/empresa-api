<?php

namespace App\Http\Controllers\AdminSync;

use App\Events\SupportMessageReceived;
use App\Http\Controllers\Controller;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportTicket;
use Illuminate\Http\Request;

class SupportMessageController extends Controller
{
    /**
     * Recibe mensaje desde admin-api y lo persiste en empresa-api.
     */
    public function store(Request $request)
    {
        // UUID del ticket remoto enviado por admin-api.
        $ticket_uuid = $request->input('ticket_uuid');
        // Tipo de mensaje (text/audio/image).
        $kind = $request->input('kind', 'text');

        // Busca ticket por UUID para asegurar correlación correcta.
        $ticket = SupportTicket::where('uuid', $ticket_uuid)->first();
        if (is_null($ticket)) {
            return response()->json(['error' => 'ticket not found'], 422);
        }

        // Idempotencia: si el UUID ya existe, devuelve el mensaje existente.
        $existing = SupportMessage::where('uuid', $request->input('message_uuid'))->withAll()->first();
        if (!is_null($existing)) {
            return response()->json(['model' => $existing], 200);
        }

        // Crea mensaje recibido desde admin y lo marca como entregado.
        $message = SupportMessage::create([
            'uuid' => $request->input('message_uuid'),
            'support_ticket_id' => $ticket->id,
            'sender_type' => 'admin',
            'sender_admin_uuid' => $request->input('sender_admin_uuid'),
            'kind' => $kind,
            'body' => $request->input('body'),
            'delivered_at' => now(),
            'synced_to_admin_at' => now(),
        ]);

        // Replica metadata de adjuntos recibidos en payload JSON.
        $attachments = $request->input('attachments', []);
        if (is_array($attachments)) {
            foreach ($attachments as $attachment) {
                SupportMessageAttachment::create([
                    'support_message_id' => $message->id,
                    'disk' => $attachment['disk'] ?? 'public',
                    'path' => $attachment['path'] ?? '',
                    'mime' => $attachment['mime'] ?? null,
                    'size' => $attachment['size'] ?? null,
                ]);
            }
        }

        // Guarda archivos adjuntos reales transferidos por multipart.
        $attachments_files = $request->file('attachments_files', []);
        if (is_array($attachments_files)) {
            foreach ($attachments_files as $uploaded_file) {
                $stored_path = $uploaded_file->store('support_messages/' . $ticket->id, 'public');
                SupportMessageAttachment::create([
                    'support_message_id' => $message->id,
                    'disk' => 'public',
                    'path' => $stored_path,
                    'mime' => $uploaded_file->getMimeType(),
                    'size' => $uploaded_file->getSize(),
                ]);
            }
        }

        // Recarga con relaciones para frontend y broadcast.
        $message = SupportMessage::where('id', $message->id)->withAll()->first();
        // Emite evento realtime para usuario dueño del ticket.
        event(new SupportMessageReceived($message->id, (int) $ticket->user_id));

        return response()->json(['model' => $message], 201);
    }

    /**
     * Marca lectura de mensaje reportada por admin-api.
     */
    public function mark_read(Request $request)
    {
        $message = SupportMessage::where('uuid', $request->input('message_uuid'))->first();
        if (is_null($message)) {
            return response()->json(['error' => 'message not found'], 404);
        }
        $message->read_at = $request->input('read_at') ?: now();
        $message->save();

        return response()->json(['ok' => true], 200);
    }
}

