<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class SupportTourOffered implements ShouldBroadcastNow
{
    use Dispatchable;

    private $recipient_id;

    public function __construct(int $recipient_id)
    {
        $this->recipient_id = $recipient_id;
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('support-tour.user.' . $this->recipient_id);
    }

    public function broadcastAs(): string
    {
        return 'SupportTourOffered';
    }

    public function broadcastWith(): array
    {
        // Los datos del tour se recuperan por la API de la persona autenticada.
        return [];
    }
}
