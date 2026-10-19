<?php

namespace App\Mail;

use App\Http\Controllers\Helpers\asistente_ia\EnviosFallidosDelAsistenteHelper;
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

    /**
     * La tarjeta del asistente que mandó este mail (misión asistente-arreglos-segunda-prueba,
     * 19/10/2026), o null si no lo mandó el asistente (la venta desde la pantalla, una oferta).
     *
     * 🔴 PÚBLICA A PROPÓSITO: viaja serializada con el mail encolado (la usa failed() en el worker) y,
     * como toda propiedad pública de un Mailable, entra en `MessageSent::$data`, que es por donde el
     * listener AnotarMailRechazadoPorElServidor se entera de qué tarjeta era un mail que el servidor
     * rechazó con un 550 sin tirar excepción. Default null en la declaración: un mail ya encolado antes
     * de este cambio se deserializa sin ella y no registra nada.
     *
     * @var int|null
     */
    public $ai_message_action_id = null;

    public function __construct(ComercioCityMailPayload $payload)
    {
        $this->payload = $payload;
    }

    public function build()
    {
        return $this->subject($this->payload->subject)
            ->view('emails.commerciocity.layout');
    }

    /**
     * El mail encolado murió en el worker (lo llama `SendQueuedMailable::failed()` cuando el envío tira:
     * en demo3 el 19/10/2026, el transporte devolvió 401 y el dueño quedó creyendo que el presupuesto le
     * había llegado a su cliente).
     *
     * Si lo mandó el asistente, se registra el envío fallido atado a su tarjeta para avisarle al dueño
     * por WhatsApp (EnviosFallidosDelAsistenteHelper, que nunca lanza). Si no, no hace nada: el fallo
     * sigue en `failed_jobs` y en el log como siempre.
     *
     * 🔴 SIN `implements ShouldQueue`: el `->queue()` explícito de ComercioCityMailHelper sigue siendo el
     * único que encola (lo cuida tests/Unit/TodoEnvioDeMailMiraLosRechazosTest). Un failed() en un
     * Mailable no cambia eso: SendQueuedMailable lo invoca si existe.
     *
     * @param  \Throwable  $e
     * @return void
     */
    public function failed($e)
    {
        if (empty($this->ai_message_action_id)) {
            return;
        }

        $destino = null;

        if (isset($this->to[0]['address'])) {
            $destino = (string) $this->to[0]['address'];
        }

        $cliente = null;

        // El nombre del cliente lo trae el renglón "Cliente" del detalle del mail (el que leyó el dueño).
        foreach ((array) $this->payload->detail_lines as $renglon) {
            if (isset($renglon['label'], $renglon['value']) && $renglon['label'] === 'Cliente') {
                $cliente = (string) $renglon['value'];
                break;
            }
        }

        EnviosFallidosDelAsistenteHelper::registrar(
            $this->ai_message_action_id,
            \App\Models\AiEnvioFallido::CANAL_MAIL,
            $destino,
            $cliente,
            EnviosFallidosDelAsistenteHelper::MOTIVO_MAIL_NO_SALIO
        );
    }
}
