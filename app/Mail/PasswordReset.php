<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Mail del código de recuperación de contraseña (`PasswordResetController::sendVerificationCode`).
 *
 * Usa el MISMO diseño HTML que el resto de los mails de ComercioCity (`emails.commerciocity.layout`:
 * encabezado con el color y el logo de `config/commerciocity.php`, cuerpo y pie), con el código en una caja
 * grande y centrada (`highlight` de `ComercioCityMailPayload`). Hasta el 20/10/2026 era un mail de markdown
 * plano con un logo fijo.
 *
 * 🔴 `$code` se mantiene como propiedad PÚBLICA: los tests sacan el código del mail mandado
 * (`Mail::assertSent(PasswordReset::class, fn ($mail) => $mail->code ...)`) y, como toda propiedad pública
 * de un Mailable, viaja a la vista. NO implementa `ShouldQueue` a propósito: se manda en el acto para que el
 * controlador pueda mirar si el servidor rechazó la casilla (`RechazosDeCorreoHelper::fallar_si_hubo_rechazos()`).
 */
class PasswordReset extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Minutos que vive el código. Es lo que dice el mail ("vence en N minutos"): tiene que coincidir con
     * `PasswordResetController::MINUTOS_DE_VIDA_DEL_CODIGO`, que es el que de verdad lo hace vencer.
     */
    const MINUTOS_DE_VIDA_DEL_CODIGO = 30;

    /**
     * El código de verificación, en texto (4 o 6 dígitos según lo que haya pedido la SPA).
     *
     * @var string|int
     */
    public $code;

    /**
     * @param  string|int  $code  Código de verificación que se le muestra a la persona.
     */
    public function __construct($code)
    {
        $this->code = $code;
    }

    /**
     * Arma el mail con el layout de ComercioCity: título, un párrafo, el código destacado (con su
     * vencimiento) y el cierre de "si no lo pediste, ignorá este mail".
     *
     * @return $this
     */
    public function build()
    {
        $payload = new ComercioCityMailPayload([
            'subject'    => 'Tu código para recuperar la contraseña',
            'title'      => 'Recuperá tu contraseña',
            'preheader'  => 'Tu código de verificación es ' . $this->code . '. Vence en ' . self::MINUTOS_DE_VIDA_DEL_CODIGO . ' minutos.',
            'paragraphs' => [
                'Recibimos un pedido para recuperar la contraseña de tu cuenta de ComercioCity.',
                'Escribí este código en la página de recuperación para poder elegir una contraseña nueva:',
            ],
            'highlight'  => [
                'label' => 'Tu código de verificación',
                'value' => (string) $this->code,
                'note'  => 'El código vence en ' . self::MINUTOS_DE_VIDA_DEL_CODIGO . ' minutos y se puede usar una sola vez.',
            ],
            'closing'    => 'Si vos no pediste cambiar tu contraseña, ignorá este mail: tu cuenta sigue segura y tu contraseña actual no se modificó.',
        ]);

        return $this->subject($payload->subject)
                    ->view('emails.commerciocity.layout', ['payload' => $payload]);
    }
}
