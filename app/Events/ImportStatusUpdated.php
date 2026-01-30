<?php

namespace App\Events;

use App\Models\ImportStatus;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ImportStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public $import_status;
    public $owner_id;

    public function __construct(int $import_status_id, int $owner_id)
    {
        $this->import_status = ImportStatus::where('id', $import_status_id)
            ->withAll()
            ->first();

        $this->owner_id = $owner_id;
    }

    /**
     * Canal EXACTO al que se emite
     */
    public function broadcastOn()
    {
        Log::info('Broadcast ImportStatusUpdated', [
            'channel' => 'import_status.'.$this->owner_id,
        ]);

        return new Channel('import_status.'.$this->owner_id);
    }

    /**
     * Nombre del evento (esto es CLAVE para Vue)
     */
    public function broadcastAs()
    {
        return 'ImportStatusUpdated';
    }

    /**
     * Payload que recibe el frontend
     */
    public function broadcastWith()
    {
        return [
            'import_status' => $this->import_status,
        ];
    }
}
