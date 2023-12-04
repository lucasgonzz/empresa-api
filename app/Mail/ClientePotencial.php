<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ClientePotencial extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($nombre_negocio)
    {
        $this->nombre_negocio = $nombre_negocio;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->from('contacto@comerciocity.com', 'ComercioCity')
                    ->subject('Servicios de automatizacion para empresas')
                    ->markdown('emails.clientes-potenciales.cliente-potencial', [
                        'nombre_negocio'    => $this->nombre_negocio,
                    ]);
    }
}
