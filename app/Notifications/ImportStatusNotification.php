<?php

namespace App\Notifications;

use App\Models\ImportStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ImportStatusNotification extends Notification
{
    // use Queueable;

    public $import_status;
    public $owner_id;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($import_status_id, $owner_id)
    {
        $this->import_status = ImportStatus::where('id', $import_status_id)
                                            ->withAll()
                                            ->first();
        $this->owner_id = $owner_id;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['broadcast'];
    }

    public function broadcastOn() {
        return 'import_status.'.$this->owner_id;
    }


    public function toBroadcast($notifiable) {
        return new BroadcastMessage([
            'import_status'              => $this->import_status,
        ]);
    }
    
    // 🔥 Esta es la parte clave
    public function shouldBroadcastNow()
    {
        return true;
    }

    /**
     * Fuerza el canal broadcast a ejecutarse en conexión sync (no queda encolado).
     */
    public function viaConnections()
    {
        return [
            'broadcast' => 'sync',
        ];
    }

    /**
     * Opcional: evita asignar una queue al broadcast.
     */
    public function viaQueues()
    {
        return [
            'broadcast' => null,
        ];
    }
}
