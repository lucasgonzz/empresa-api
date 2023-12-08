<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ErrorHandler extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($owner_user, $auth_user, $error)
    {
        $this->owner_user = $owner_user;
        $this->auth_user = $auth_user;
        $this->error = $error;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->from('contacto@comerciocity.com', 'comerciocity.com')
                    ->subject('Error '.$this->owner_user->company_name)
                    ->markdown('emails.errors.handler', [
                        'owner_user'        => $this->owner_user,
                        'auth_user'         => $this->auth_user,
                        'error'             => $this->error,
                    ]);
    }
}
