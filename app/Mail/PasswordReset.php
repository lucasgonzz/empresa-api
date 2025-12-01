<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordReset extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct($code)
    {
        $this->code = $code;
    }

    public function build()
    {
        return $this->subject('Restablecer Contraseña')
                    ->markdown('emails.password-reset', [
                        'code'      => $this->code,
                        'logo_url'  => 'https://api.comerciocity.com/public/storage/logo.png',
                    ]);
    }
}
