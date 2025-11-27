<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MantenimientoMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($user_id, $mensajes)
    {
        $this->user = User::find($user_id);
        $this->mensajes = $mensajes;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject('Mantenimiento '.$this->user->company_name)
                    ->markdown('emails.message-send', [
                        'messages'  => $this->mensajes
                    ]);
    }
}
