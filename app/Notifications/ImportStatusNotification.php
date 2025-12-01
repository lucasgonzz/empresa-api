<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;

class ImportStatusNotification extends Notification
{
    use Queueable;

    public $import_status;
    public $owner_id;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($import_status, $owner_id)
    {
        $this->import_status = $import_status;
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
}
