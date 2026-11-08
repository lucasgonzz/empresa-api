<?php

namespace App\Http\Controllers;

use App\Events\SupportMessageReceived;
use App\Http\Controllers\Helpers\SupportSyncHelper;
use App\Http\Controllers\Helpers\SupportTicketHelper;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportTicket;
use App\Models\SupportTypingState;
use App\Models\User;
use App\Services\SupportAgentesSync;
use App\Services\SupportTourService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupportMessageController extends Controller
{
    public function store(Request $request, SupportAgentesSync $sync)
    {
        $user_id = $this->userId(false);
        $ticket_id = $request->input('support_ticket_id');
        $persona = SupportAgentesSync::persona_propia($request);
        $soporte_web = SupportAgentesSync::puede_usar_soporte($persona);
        $agentes = $soporte_web && (int) $request->input('agentes_version') === SupportAgentesSync::VERSION;
        $uuid = $agentes ? ($request->validate(['mensaje_uuid' => 'nullable|uuid'])['mensaje_uuid'] ?? null) : null;
        if ($uuid && ($existente = $this->mensaje_reintentado($uuid, $user_id, $ticket_id))) {
            return $this->responder_reintento($existente);
        }

        $ticket = $ticket_id === null
            ? null
            : SupportTicket::whereKey($ticket_id)->where('user_id', $user_id)->firstOrFail();
        if ($ticket && $ticket->origen === SupportTicket::ORIGEN_WHATSAPP) {
            abort_unless($soporte_web, 404);
            if ($ticket->sistema_exclusivo_at === null) {
                if (!$request->boolean('traspasar_a_sistema')) {
                    return response()->json([
                        'code' => 'confirmar_traspaso_a_sistema',
                        'message' => 'Confirmá que querés continuar esta conversación solo desde el sistema.',
                    ], 409);
                }
                abort_unless($sync->actualizar_conversacion($persona, $ticket), 404);
                $ticket->refresh();
            }
            $agentes = true;
        }

        $body = $request->input('body');
        $body = is_string($body) ? trim($body) : $body;
        if ($agentes && ($body === '' || $body === null) && !$request->hasFile('attachment')) {
            return response()->json(['message' => 'Escribí un mensaje o adjuntá un archivo.'], 422);
        }

        $modo_firmado = $agentes && SupportAgentesSync::identidad($persona) !== null
            && trim((string) config('services.admin_api.api_key')) !== '';
        if ($ticket && $ticket->origen === SupportTicket::ORIGEN_WHATSAPP) {
            abort_unless($modo_firmado, 503, 'Todavía no podemos trasladar este ticket al sistema.');
        }

        $resultado = DB::transaction(function () use ($request, $user_id, $ticket_id, $ticket, $body, $soporte_web, $agentes, $modo_firmado, $uuid) {
            if ($uuid) {
                // El mismo reintento no abre un segundo ticket si se perdió la respuesta del POST.
                User::whereKey($user_id)->lockForUpdate()->firstOrFail();
                $existente = $this->mensaje_reintentado($uuid, $user_id, $ticket_id);
                if ($existente) {
                    return ['model' => $existente, 'repetido' => true];
                }
            }

            $ticket = $ticket ?? ($agentes && $request->boolean('nueva_conversacion')
                ? SupportTicket::create(['user_id' => $user_id, 'status' => 'open'])
                : SupportTicketHelper::get_or_create_open_ticket($user_id));
            $ticket = SupportTicket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if ($ticket->status !== 'open') {
                return ['error' => 'ticket closed'];
            }
            if ($agentes && !$ticket->desde_agentes) {
                $ticket->desde_agentes = true;
                $ticket->save();
            }

            $datos = [
                'uuid' => $uuid,
                'support_ticket_id' => $ticket->id,
                'sender_type' => 'user',
                'sender_user_id' => $user_id,
                'kind' => $request->input('kind', 'text'),
                'body' => $body,
                'delivered_at' => now(),
            ];
            if ($modo_firmado) {
                $datos['agentes_version'] = SupportAgentesSync::VERSION;
            }
            if ($agentes) {
                $datos['remote_delivery_status'] = 'not_received';
            }
            if ($soporte_web) {
                $datos['robotito_support_version'] = SupportTourService::VERSION_SOPORTE_WEB;
            }

            $message = SupportMessage::create($datos);
            if ($request->hasFile('attachment')) {
                $archivo = $request->file('attachment');
                SupportMessageAttachment::create([
                    'support_message_id' => $message->id,
                    'disk' => 'public',
                    'path' => $archivo->store('support_messages/' . $ticket->id, 'public'),
                    'mime' => $archivo->getMimeType(),
                    'size' => $archivo->getSize(),
                ]);
            }

            return ['model' => $message, 'repetido' => false];
        });

        if (isset($resultado['error'])) {
            return response()->json($resultado, 422);
        }
        $message = $resultado['model'];
        if ($resultado['repetido']) {
            return $this->responder_reintento($message);
        }

        if (!SupportSyncHelper::sync_message_to_admin($message)) {
            SupportMessage::whereKey($message->id)->whereNull('synced_to_admin_at')
                ->update(['remote_delivery_status' => 'not_received']);
        }
        $message = SupportMessage::whereKey($message->id)->withAll()->first();
        event(new SupportMessageReceived($message->id, $user_id));

        return response()->json(['model' => $message], 201);
    }

    private function mensaje_reintentado(string $uuid, int $user_id, $ticket_id): ?SupportMessage
    {
        $existente = SupportMessage::where('uuid', $uuid)->withAll()->first();
        if ($existente) {
            abort_unless($existente->ticket && $existente->sender_type === 'user' && (int) $existente->ticket->user_id === $user_id
                && ($ticket_id === null || (int) $existente->ticket->id === (int) $ticket_id), 409);
        }

        return $existente;
    }

    private function responder_reintento(SupportMessage $message)
    {
        if ($message->synced_to_admin_at === null) {
            SupportSyncHelper::sync_message_to_admin($message);
            $message->refresh();
        }

        return response()->json(['model' => $message], 200);
    }

    /**
     * Marca como leído un mensaje recibido desde admin.
     */
    public function mark_read(Request $request, $id)
    {
        // Obtiene user_id real del usuario logueado (no owner).
        $user_id = $this->userId(false);
        // Mensaje sólo válido si pertenece a ticket del usuario actual.
        $message = SupportMessage::where('id', $id)
            ->whereHas('ticket', function ($query) use ($user_id) {
                $query->where('user_id', $user_id);
            })
            ->firstOrFail();
        $this->validar_historial_whatsapp($request, $message->ticket);

        $message->read_at = now();
        $message->save();
        // Sincroniza lectura al admin-api para estados de doble tilde.
        if ($message->ticket->origen !== SupportTicket::ORIGEN_WHATSAPP || $message->ticket->sistema_exclusivo_at !== null) {
            SupportSyncHelper::sync_read_to_admin($message);
        }

        return response()->json(['ok' => true], 200);
    }

    /**
     * Reintenta enviar a admin-api un mensaje del usuario ya persistido que falló en sincronización.
     *
     * @param int|string $id Id del mensaje local.
     * @return \Illuminate\Http\JsonResponse
     */
    public function retry_remote_sync(Request $request, $id)
    {
        $user_id = $this->userId(false);
        $message = SupportMessage::where('id', $id)
            ->where('sender_type', 'user')
            ->whereHas('ticket', function ($query) use ($user_id) {
                $query->where('user_id', $user_id);
            })
            ->firstOrFail();
        $this->validar_historial_whatsapp($request, $message->ticket);

        SupportSyncHelper::sync_message_to_admin($message);

        $model = $this->fullModel('SupportMessage', $message->id);

        return response()->json(['model' => $model], 200);
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
        $this->validar_historial_whatsapp($request, $ticket);

        // Upsert simple del estado de escritura por actor.
        $typing_state = SupportTypingState::firstOrNew([
            'support_ticket_id' => $ticket->id,
            'actor_type' => 'user',
            'actor_id' => $user_id,
        ]);
        $typing_state->last_typing_at = now();
        $typing_state->save();

        // Sincroniza typing al admin-api para mostrar indicador remoto.
        if ($ticket->origen !== SupportTicket::ORIGEN_WHATSAPP || $ticket->sistema_exclusivo_at !== null) {
            SupportSyncHelper::sync_typing_to_admin($ticket->uuid, $user_id);
        }

        return response()->json(['ok' => true], 200);
    }

    private function validar_historial_whatsapp(Request $request, SupportTicket $ticket): void
    {
        if ($ticket->origen === SupportTicket::ORIGEN_WHATSAPP) {
            abort_unless(SupportAgentesSync::puede_usar_soporte(SupportAgentesSync::persona_propia($request)), 404);
        }
    }
}

