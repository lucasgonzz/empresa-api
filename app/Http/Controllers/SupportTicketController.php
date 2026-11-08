<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\SupportTicketHelper;
use App\Models\SupportTicket;
use App\Services\SupportAgentesSync;
use Illuminate\Http\Request;

class SupportTicketController extends Controller
{
    /**
     * Lista tickets del usuario autenticado dueño de la sesión.
     */
    public function index(Request $request, SupportAgentesSync $sync)
    {
        // Obtiene user_id real del usuario logueado (no owner).
        $user_id = $this->userId(false);

        $persona = SupportAgentesSync::persona_propia($request);
        $incluye_whatsapp = $request->boolean('agentes') && SupportAgentesSync::puede_usar_soporte($persona);
        $autorizados_por_admin = $incluye_whatsapp ? $sync->actualizar_lista($persona) : [];

        $models = SupportTicket::where('user_id', $user_id)
            ->where(function ($query) use ($incluye_whatsapp, $autorizados_por_admin) {
                $query->where('origen', SupportTicket::ORIGEN_SISTEMA);
                if ($incluye_whatsapp) {
                    $query->orWhereNotNull('sistema_exclusivo_at')->orWhereIn('id', $autorizados_por_admin);
                }
            })
            ->withAll()
            ->withUnreadMessagesCount()
            ->when($incluye_whatsapp, function ($query) { $query->orderByDesc('opened_at'); })
            ->orderByDesc('id')
            ->get();

        return response()->json(['models' => $models], 200);
    }

    /**
     * Obtiene o crea el ticket abierto vigente para una nueva conversación desde empresa-spa.
     * Reutiliza el ticket abierto existente si ya hay uno (evita hilos duplicados activos).
     *
     * @return \Illuminate\Http\JsonResponse Respuesta JSON con `model` del ticket y relaciones estándar.
     */
    public function store()
    {
        // Usuario autenticado dueño del ticket (no owner delegado).
        $user_id = $this->userId(false);
        // Alta lógica centralizada: crea uno nuevo sólo si no hay ningún ticket abierto.
        $ticket = SupportTicketHelper::get_or_create_open_ticket($user_id);

        // Misma forma que index/show para contadores y mensajes embebidos en listados.
        $model = SupportTicket::where('id', $ticket->id)
            ->where('user_id', $user_id)
            ->withAll()
            ->withUnreadMessagesCount()
            ->first();

        return response()->json(['model' => $model], 201);
    }

    /**
     * Muestra un ticket puntual del usuario autenticado.
     */
    public function show(Request $request, SupportAgentesSync $sync, $id)
    {
        // Obtiene user_id real del usuario logueado (no owner).
        $user_id = $this->userId(false);

        // Asegura aislamiento de datos por usuario.
        $ticket = SupportTicket::where('id', $id)->where('user_id', $user_id)->firstOrFail();
        if ($ticket->origen === SupportTicket::ORIGEN_WHATSAPP) {
            $persona = SupportAgentesSync::persona_propia($request);
            abort_unless(SupportAgentesSync::puede_usar_soporte($persona), 404);
            $autorizado = $sync->actualizar_conversacion($persona, $ticket);
            abort_unless($autorizado || $ticket->sistema_exclusivo_at !== null, 404);
        }

        $model = SupportTicket::whereKey($ticket->id)->withAll()->withUnreadMessagesCount()->firstOrFail();

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
        if ($ticket->origen === SupportTicket::ORIGEN_WHATSAPP) {
            abort_unless(SupportAgentesSync::puede_usar_soporte(SupportAgentesSync::persona_propia($request)), 404);
            abort_if($ticket->sistema_exclusivo_at === null, 409, 'Este ticket se está atendiendo por WhatsApp.');
        }

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

