<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Correo HTML reutilizable con layout ComercioCity (header / body / footer).
 *
 * Uso típico cuando el cliente tiene email configurado:
 *
 *   use Illuminate\Support\Facades\Mail;
 *
 *   if ($client->email) {
 *       Mail::to($client->email)->send(new ComercioCityMail(
 *           new ComercioCityMailPayload([
 *               'subject' => 'Se registró un pago',
 *               'title' => 'Detalle de tu pago',
 *               'paragraphs' => ['Gracias por tu compra.'],
 *               'detail_lines' => [
 *                   ['label' => 'Importe', 'value' => '$ 1.000', 'bold_label' => true],
 *               ],
 *               'links' => [
 *                   ['text' => 'Ver en la app', 'url' => config('app.url')],
 *               ],
 *               'closing' => 'Muchas gracias por elegirnos.',
 *               'preheader' => 'Resumen de tu pago',
 *               'footer_links' => [
 *                   ['img_url' => 'https://ejemplo.com/icono-web.png', 'link_url' => 'https://commerciocity.com'],
 *                   ['img_url' => 'https://ejemplo.com/icono-ig.png', 'link_url' => 'https://instagram.com/...'],
 *               ],
 *           ])
 *       ));
 *   }
 *
 * Envío en cola (requiere worker y QUEUE_CONNECTION distinto de sync):
 *
 *   Mail::to($client->email)->queue(new ComercioCityMail($payload));
 */
class ComercioCityMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** @var ComercioCityMailPayload */
    public $payload;

    public function __construct(ComercioCityMailPayload $payload)
    {
        $this->payload = $payload;
    }

    public function build()
    {
        return $this->subject($this->payload->subject)
            ->view('emails.commerciocity.layout');
    }
}
