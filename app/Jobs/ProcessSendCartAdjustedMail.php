<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\ClientMailConfigHelper;
use App\Http\Controllers\Helpers\MailNotificationConfigHelper;
use App\Mail\CartAdjusted;
use App\Mail\Helpers\RechazosDeCorreoHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Manda al comprador el mail de "te ajustamos el carrito" (misión carritos-ajuste-stock): cuando se le baja la
 * cantidad de un renglón por falta de stock ('recortado') o cuando se le completa al reponer ('restaurado').
 *
 * Calcado de `ProcessSendAdviseMail`: recibe datos planos (no modelos, el carrito puede cambiar entre el
 * despacho y el envío), revalida el gate del comercio y el mail, aplica el SMTP propio del comercio si lo tiene y
 * verifica los rechazos del servidor. Nunca relanza una excepción: con QUEUE_CONNECTION=sync corre dentro del
 * request que movió el stock y no puede romperlo.
 */
class ProcessSendCartAdjustedMail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Dueño del comercio.
     *
     * @var int
     */
    public $owner_id;

    /**
     * Casilla del comprador.
     *
     * @var string|null
     */
    public $buyer_email;

    /**
     * Nombre del comprador (para el saludo).
     *
     * @var string|null
     */
    public $buyer_name;

    /**
     * Artículo del renglón.
     *
     * @var int
     */
    public $article_id;

    /**
     * Variante del renglón (null si no lleva).
     *
     * @var int|null
     */
    public $variant_id;

    /**
     * Tipo de aviso: 'recortado' o 'restaurado'.
     *
     * @var string
     */
    public $type;

    /**
     * Cantidad que el comprador había agregado (X).
     *
     * @var float
     */
    public $requested_amount;

    /**
     * Cantidad que quedó en el carrito (Y).
     *
     * @var float
     */
    public $new_amount;

    /**
     * Reintentos de la queue antes de llamar a failed().
     *
     * @var int
     */
    public $tries = 3;

    /**
     * @param int $owner_id Dueño del comercio.
     * @param string|null $buyer_email Casilla del comprador.
     * @param string|null $buyer_name Nombre del comprador.
     * @param int $article_id Artículo del renglón.
     * @param int|null $variant_id Variante del renglón.
     * @param string $type 'recortado' | 'restaurado'.
     * @param float $requested_amount X: lo que había agregado.
     * @param float $new_amount Y: lo que quedó en el carrito.
     * @return void
     */
    public function __construct($owner_id, $buyer_email, $buyer_name, $article_id, $variant_id, $type, $requested_amount, $new_amount)
    {
        // Nunca publicar efectos de una tanda cuya transacción todavía puede revertirse.
        $this->afterCommit = true;

        $this->owner_id = $owner_id;
        $this->buyer_email = $buyer_email;
        $this->buyer_name = $buyer_name;
        $this->article_id = $article_id;
        $this->variant_id = $variant_id;
        $this->type = $type;
        $this->requested_amount = $requested_amount;
        $this->new_amount = $new_amount;
    }

    /**
     * Manda el mail. Todo va en try/catch: una excepción acá jamás se propaga hacia la operación de stock.
     *
     * @return void
     */
    public function handle()
    {
        try {
            // Revalida el mail: este job puede despacharse desde otro lado en el futuro.
            $email = trim((string) $this->buyer_email);

            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                Log::warning('ProcessSendCartAdjustedMail: email invalido, no se envia', [
                    'owner_id' => $this->owner_id,
                    'email'    => $email,
                ]);
                return;
            }

            // Gate por cliente: el comercio puede haber apagado el aviso entre el despacho y el envío.
            if (!MailNotificationConfigHelper::avisarAjusteCarrito($this->owner_id)) {
                Log::info('ProcessSendCartAdjustedMail: aviso de ajuste de carrito deshabilitado para el cliente', [
                    'owner_id' => $this->owner_id,
                ]);
                return;
            }

            // Correo propio del comercio (si lo configuró y está activo); si no, sale por el .env.
            ClientMailConfigHelper::apply($this->owner_id);

            Mail::to($email)->send(new CartAdjusted(
                $this->owner_id,
                $this->buyer_name,
                $this->article_id,
                $this->variant_id,
                $this->type,
                $this->requested_amount,
                $this->new_amount
            ));

            // Que send() no tire no quiere decir que el mail haya salido: si el servidor rechaza la casilla del
            // comprador (un 550 en el RCPT TO) SwiftMailer no lanza nada. Se convierte en excepción para que
            // quede en el log en vez de darlo por enviado.
            RechazosDeCorreoHelper::fallar_si_hubo_rechazos();
        } catch (\Exception $e) {
            // No se relanza: con QUEUE_CONNECTION=sync rompería el movimiento de stock del ERP.
            Log::error('ProcessSendCartAdjustedMail: error enviando mail de carrito ajustado', [
                'owner_id'   => $this->owner_id,
                'article_id' => $this->article_id,
                'email'      => $this->buyer_email,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Se ejecuta cuando el job agota los reintentos. Sólo deja constancia en el log.
     *
     * @param \Exception $e Excepción que causó el fallo final.
     * @return void
     */
    public function failed(\Exception $e)
    {
        Log::error('ProcessSendCartAdjustedMail: fallo definitivo tras agotar reintentos', [
            'owner_id'   => $this->owner_id,
            'article_id' => $this->article_id,
            'error'      => $e->getMessage(),
        ]);
    }
}
