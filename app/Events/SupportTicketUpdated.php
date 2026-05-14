<?php

namespace App\Events;

use App\Models\SupportTicket;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Notifica al usuario de empresa (canal support.user.{id}) que el ticket cambió desde admin-api
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
     * Canal público por usuario (Echo en empresa-spa).
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
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
