<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class ImplementacionActualizada implements ShouldBroadcastNow
{
    use Dispatchable;

    private int $usuario_id;

    public function __construct(int $usuario_id)
    {
        $this->usuario_id = $usuario_id;
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('implementacion.user.' . $this->usuario_id);
    }

    public function broadcastAs(): string
    {
        return 'ImplementacionActualizada';
    }

    public function broadcastWith(): array
    {
        return [];
    }
}
