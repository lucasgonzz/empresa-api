<?php

namespace App\Mail\Helpers;

use App\Exceptions\MailRechazadoPorElServidorException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Qué casillas rechazó el servidor de correo en el último envío de un mailer.
 *
 * OJO: que `send()` no haya tirado NO quiere decir que el mail haya salido. Cuando el servidor SMTP rechaza una casilla en el RCPT TO (550
 * "User unknown", un dominio que no existe, un buzón lleno) SwiftMailer no tira ninguna excepción: `send()` vuelve normal y las casillas
 * rechazadas quedan en `failures()` del mailer. Quien solo atrapa la excepción da por enviado un mail que nunca salió, y encima sigue de largo
 * como si hubiera salido: contesta "enviado" al usuario, borra el aviso que el comprador pidió, imprime "Mail enviado" en la consola.
 *
 * Es lo mismo en todos los envíos por SMTP de `empresa-api`, y por eso la lectura vive acá y no copiada en cada punto. Es la misma clase que
 * resolvió `admin-api` el 6/10/2026. Hoy la usan:
 *
 *   - con `fallar_si_hubo_rechazos()` (dentro de un `try/catch` que responde o anota el fallo): el código de recuperación de contraseña
 *     (`PasswordResetController`), el botón de probar el SMTP (`OnlineConfigurationController::testMail`), el aviso de ingreso de stock
 *     (`ProcessSendAdviseMail`) y el mail a un cliente potencial (`ClientePotencialController`);
 *   - con `del_ultimo_envio()` (el punto arma su propia respuesta con la lista): los comandos `check_current_acounts_integrity` y `check_stocks`, que
 *     imprimen un éxito que el rechazo desmiente;
 *   - y el listener `AnotarMailRechazadoPorElServidor`, que lo deja en el log para los mails que ningún punto puede mirar (los encolados, que se mandan
 *     en un worker, y las `Notification` por el canal `mail`).
 *
 * 🔴 **Todo `Mail::...->send()` nuevo en `app/` tiene que mirar los rechazos, o tener una decisión escrita de por qué no.** No es un pedido de buena
 * voluntad: `TodoEnvioDeMailMiraLosRechazosTest` falla si aparece un envío sin su chequeo (o un `->queue()` sin decisión), porque el décimo punto de
 * envío nace con el mismo hueco que los otros nueve.
 *
 * Y 🔴 **no se reemplaza por `method_exists(Mail::getFacadeRoot(), 'failures')`.** Es lo que tenía un envío de `admin-api` y NUNCA corrió: la raíz de
 * la fachada es el `MailManager`, que no tiene `failures()` (le llega por `__call`, que `method_exists` no ve), así que el chequeo daba siempre `false`
 * y el rechazo pasaba como un envío exitoso. Un chequeo que se ve correcto y no se ejecuta nunca es peor que no tenerlo.
 *
 * Se lee de `Mail::mailer($nombre)->failures()`, que es la MISMA instancia del mailer que mandó el mail (el administrador de mails guarda cada mailer
 * ya armado), y que arranca vacía en cada envío. Bajo `Mail::fake()` el falso también responde `failures()` y devuelve vacío.
 *
 * 🔴 **Límites que hay que saber (medidos con el SwiftMailer 6.3 de `vendor/` contra un servidor SMTP de mentira).**
 *
 *   - `failures()` lista CUALQUIER destinatario rechazado, no solo el principal. Hoy ningún mailable de `app/Mail` agrega `cc` ni `bcc`, así que "hubo
 *     rechazos" significa "el destinatario no recibió el mail". El día que uno agregue una copia, un rechazo de ESA copia marcaría como fallido un mail que
 *     SÍ llegó (y un reintento se lo mandaría dos veces): ahí hay que mirar si la casilla rechazada es la del destinatario.
 *   - Entran a `failures()` los rechazos temporales (un 451 de greylisting, un 452 de buzón lleno) igual que un 550: con 4xx la casilla puede estar bien.
 *     Por eso el motivo dice "no existe, está llena o no acepta mensajes por ahora" y no "la casilla está mal".
 *   - Solo ve el rechazo SÍNCRONO en el RCPT TO. Un servidor que acepta el mail y después lo devuelve (rebote asincrónico al buzón remitente) no se ve
 *     desde acá.
 *   - Un mail ENCOLADO se manda en un worker: cuando `send()` corre ahí, quien lo pidió ya no está. El helper no puede devolverle nada; por eso los
 *     `->queue()` no lo llaman y los cubre el listener (ver `ComercioCityMailHelper`).
 */
class RechazosDeCorreoHelper
{
    /**
     * Las casillas que el servidor rechazó en el ÚLTIMO envío del mailer.
     *
     * Hay que llamarlo inmediatamente después del `send()`: el mailer reinicia la lista en cada envío.
     *
     * Si la lista no se puede leer (un mailer de mentira que no responde `failures()`, por ejemplo), se devuelve vacío: no hay con qué decir que el
     * servidor rechazó nada, y no se inventa un fallo sobre un mail que pudo haber salido bien. Pero NO se calla: se deja un aviso en el log.
     *
     * 🔴 **`null` es el mailer por defecto, y se lee con `Mail::mailer(null)`, no con `Mail::failures()`.** Los envíos de `empresa-api` usan `Mail::to()`,
     * que va al mailer por defecto (`ClientMailConfigHelper::apply()` lo deja en `smtp` cuando el comercio tiene su propia casilla); `Mail::mailer(null)`
     * devuelve ESA misma instancia (`MailManager::mailer()` resuelve `null` al driver por defecto). `Mail::failures()` a secas también llegaría al mailer por
     * defecto, pero por `__call` y sin dejar claro de qué mailer se habla: la lectura por nombre es una sola y es la que hay que mockear en un test.
     *
     * @param string|null $mailer Nombre del mailer en `config/mail.php`, o null para el mailer por defecto (el que usa `Mail::to()` a secas).
     *
     * @return array<int, string> Las casillas rechazadas, sin repetir. Vacío = el servidor aceptó a todas.
     */
    public static function del_ultimo_envio(?string $mailer = null): array
    {
        try {
            // Las casillas que el servidor rechazó en el último envío de ese mailer.
            $rechazadas = Mail::mailer($mailer)->failures();
        } catch (\Throwable $excepcion) {
            // 🔴 No se inventa un fallo, pero tampoco se calla: si `failures()` deja de responder (un upgrade de Laravel, un typo), los envíos volverían
            // EN SILENCIO a darse por enviados aunque el servidor los rechace, que es justo el defecto que este helper existe para evitar. Un renglón en el
            // log hace que ese día se note. Bajo `Mail::fake()` no se pasa por acá: el falso sí responde `failures()`.
            //
            // 🔴 Y ese aviso tampoco puede romper el envío: si el logger también falla (disco lleno, permisos de `storage/logs`) la excepción escaparía del helper hacia
            // los puntos de envío. Por ejemplo, en `ProcessSendAdviseMail` caería en su `catch` y dejaría el aviso pendiente aunque el mail SÍ haya salido
            // (el comprador lo recibiría duplicado en el próximo ingreso de stock).
            try {
                Log::warning('RechazosDeCorreoHelper: no se pudo leer failures() del mailer; se asume que el servidor no rechazó nada.', [
                    'mailer' => $mailer === null ? '(por defecto)' : $mailer,
                    'error'  => $excepcion->getMessage(),
                ]);
            } catch (\Throwable $excepcion_del_log) {
                // Es un aviso: si ni siquiera se puede escribir, se sigue como si nada.
            }

            return [];
        }

        if (! is_array($rechazadas)) {
            return [];
        }

        // Lista limpia: sin repetidos, recortada y sin lo que no es una casilla, en el orden en que llegaron.
        $salida = [];

        foreach ($rechazadas as $rechazada) {
            if (! is_scalar($rechazada)) {
                continue;
            }

            $casilla = trim((string) $rechazada);

            if ($casilla !== '' && ! in_array($casilla, $salida, true)) {
                $salida[] = $casilla;
            }
        }

        return $salida;
    }

    /**
     * Tira `MailRechazadoPorElServidorException` si el último envío del mailer tuvo casillas rechazadas.
     *
     * Es para los puntos de envío que ya tienen (o arman) un `try { ... } catch` que responde o anota el fallo: una línea justo después del `send()` y
     * antes de afirmar nada ("enviado", borrar un aviso, escribir una marca) convierte el rechazo silencioso en una excepción más, que el `catch` trata
     * como cualquier otro fallo.
     *
     * Conserva la regla de `del_ultimo_envio()`: si no se puede leer la lista (`Mail::fake()`, un mock sin `failures()`), no tira. Los tests que
     * verifican "qué mail sale y a quién" con `Mail::fake()` no cambian.
     *
     * @param string|null $mailer Nombre del mailer, o null para el mailer por defecto (ver `del_ultimo_envio()`).
     *
     * @return void
     *
     * @throws MailRechazadoPorElServidorException Si el servidor rechazó al menos una casilla.
     */
    public static function fallar_si_hubo_rechazos(?string $mailer = null): void
    {
        $rechazadas = self::del_ultimo_envio($mailer);

        if (! empty($rechazadas)) {
            throw new MailRechazadoPorElServidorException($rechazadas);
        }
    }
}
