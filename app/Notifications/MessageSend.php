<?php

namespace App\Notifications;

use App\Http\Controllers\Helpers\UserHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class MessageSend extends Notification
{
    use Queueable;
    private $message;
    private $for_commerce;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($message, $for_commerce = false, $title = null, $url = null, $send_email = true)
    {
        $this->message = $message;
        $this->for_commerce = $for_commerce;
        $this->title = $title;
        $this->url = $url;
        $this->send_email = $send_email;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        if ($this->for_commerce || !$this->send_email) {
            return ['broadcast'];
        } 
        return ['broadcast', 'mail'];
    }

    public function broadcastOn()
    {
        if (!$this->for_commerce) {
            return 'message.from_commerce.'.$this->message->buyer_id;
        } else {
            return 'message.from_buyer.'.$this->message->user_id;
        }
    }

    public function toBroadcast($notifiable)
    {
        return new BroadcastMessage([
            'message' => $this->message,
        ]);
    }

    public function toMail($notifiable)
    {
        $user = UserHelper::getFullModel();
        Log::info('mail logo_url: '.$user->image_url);
        return (new MailMessage)
                    ->from('contacto@comerciocity.com', 'comerciocity.com')
                    ->subject($this->title)
                    ->markdown('emails.message-send', [
                        'commerce'  => $user,
                        'message'   => $this->message->text,
                        'logo_url'  => 'https://api.comerciocity.com/public/storage/logo.png',
                        // 'logo_url'  => $user->image_url,
                    ]);
        // if (!is_null($this->url)) {
        //     $mail_message->action('Ver producto en la tienda', $this->url);
        // }
        // return (new MailMessage)
        //             // ->theme('custom')
        //             ->greeting('Hola '.$notifiable->name)
        //             ->from(Auth()->user()->email, Auth()->user()->company_name)
        //             ->subject($this->title)
        //             ->line($this->message->text)
        //             ->line('¡Muchas gracias por usar nuestros servicios!');
    }
}
