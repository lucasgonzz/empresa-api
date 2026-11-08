<?php

namespace App\Events;

use App\Models\SupportTicket;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Notifica al usuario de empresa que el ticket cambió desde admin-api
 * (cierre, reapertura, nombre). Payload acotado para respetar el límite de Pusher (~10 KB).
 */
class SupportTicketUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * Usuario de empresa-api que debe recibir el evento (mismo criterio que SupportMessageReceived).
     *
     * @var int
     */
    protected $listener_user_id;

    /**
     * Id del SupportTicket local actualizado.
     *
     * @var int
     */
    protected $support_ticket_id;

    /**
     * @param int $listener_user_id user_id del ticket (dueño en empresa-api)
     * @param int $support_ticket_id id persistido en support_tickets
     */
    public function __construct(int $listener_user_id, int $support_ticket_id)
    {
        $this->listener_user_id = $listener_user_id;
        $this->support_ticket_id = $support_ticket_id;
    }

    /**
     * Evita publicar si el registro ya no existe.
     */
    public function broadcastWhen(): bool
    {
        return SupportTicket::query()->where('id', $this->support_ticket_id)->exists();
    }

    /**
     * Canal de la persona que abrió el ticket.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $ticket = SupportTicket::find($this->support_ticket_id);
        if (!$ticket) {
            return [];
        }
        if ($ticket->requiere_canal_privado()) {
            return [new PrivateChannel('agentes.support.user.' . $ticket->user_id)];
        }

        return [
            new Channel('support.user.' . $this->listener_user_id),
        ];
    }

    /**
     * Nombre del evento para Echo (.SupportTicketUpdated).
     */
    public function broadcastAs(): string
    {
        return 'SupportTicketUpdated';
    }

    /**
     * Ticket ligero + contador de no leídos (sin mensajes ni adjuntos).
     *
     * @return array{ticket: array<string, mixed>|null}
     */
    public function broadcastWith(): array
    {
        $ticket = SupportTicket::query()
            ->where('id', $this->support_ticket_id)
            ->select([
                'id',
                'uuid',
                'user_id',
                'name',
                'status',
                'origen',
                'sistema_exclusivo_at',
                'opened_at',
                'closed_at',
                'created_at',
                'updated_at',
            ])
            ->withUnreadMessagesCount()
            ->first();

        return [
            'ticket' => $ticket ? $ticket->toArray() : null,
        ];
    }
}
