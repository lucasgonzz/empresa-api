<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;

class GlobalNotification extends Notification
{
    use Queueable;

    
    public $message_text;
    public $color_variant;
    public $functions_to_execute;
    public $owner_id;
    public $is_only_for_auth_user;

    public function __construct($data)
    {
        $this->message_text             = $data['message_text'];
        $this->color_variant            = $data['color_variant'];
        $this->functions_to_execute     = $data['functions_to_execute'];
        $this->info_to_show             = $data['info_to_show'];
        $this->owner_id                 = $data['owner_id'];
        $this->is_only_for_auth_user    = $data['is_only_for_auth_user'];
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
        return 'global_notification.'.$this->owner_id;
    }


    public function toBroadcast($notifiable) {
        return new BroadcastMessage([
            'message_text'              => $this->message_text,
            'color_variant'             => $this->color_variant,
            'functions_to_execute'      => $this->functions_to_execute,
            'info_to_show'              => $this->info_to_show,
            'owner_id'                  => $this->owner_id,
            'is_only_for_auth_user'     => $this->is_only_for_auth_user,
        ]);
    }
}
