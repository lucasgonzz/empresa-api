<?php

namespace App\Http\Controllers\AdminSync;

use App\Events\SupportMessageRead;
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

        // Archivos binarios del multipart (prioridad sobre metadata remota).
        $attachments_files = $request->file('attachments_files', []);
        $has_uploaded_files = is_array($attachments_files) && count($attachments_files) > 0;

        if ($has_uploaded_files) {
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
        } else {
            // Sin binarios: metadata JSON (multipart) o array (body JSON).
            $attachments = $this->parse_attachments_input($request->input('attachments', []));
            foreach ($attachments as $attachment) {
                if (! is_array($attachment)) {
                    continue;
                }
                SupportMessageAttachment::create([
                    'support_message_id' => $message->id,
                    'disk' => $attachment['disk'] ?? 'public',
                    'path' => $attachment['path'] ?? '',
                    'mime' => $attachment['mime'] ?? null,
                    'size' => $attachment['size'] ?? null,
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

        // Aviso en tiempo real al usuario (canal support.user.*) de que su mensaje fue visto en admin.
        // El evento resuelve el user_id vía sender_user_id o, en su defecto, ticket.user_id.
        $updated = SupportMessage::where('id', $message->id)->withAll()->first();
        if (!is_null($updated) && $updated->sender_type === 'user') {
            event(new SupportMessageRead($updated->id));
        }

        return response()->json(['ok' => true], 200);
    }

    /**
     * Normaliza el campo attachments del request (array JSON o string JSON desde multipart).
     *
     * @param mixed $raw Valor crudo del request.
     * @return array<int, array<string, mixed>>
     */
    private function parse_attachments_input($raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            return [];
        }
        if (is_array($raw)) {
            return $raw;
        }

        return [];
    }
}

