<?php

namespace App\Exceptions;

/**
 * El servidor de correo RECHAZÓ la casilla del destinatario: el mail no salió.
 *
 * Existe porque SwiftMailer (Laravel 8) no tira nada cuando eso pasa. Si el servidor SMTP contesta 550 en el RCPT TO ("User unknown", un
 * dominio que no existe, un buzón lleno), `send()` vuelve normal y las casillas rechazadas quedan en `failures()` del mailer. Quien solo atrapa
 * excepciones da por enviado un mail que nunca salió. `RechazosDeCorreoHelper::fallar_si_hubo_rechazos()` convierte ese rechazo silencioso en
 * ESTA excepción, para que la atrape el `catch` del punto de envío y haga lo que corresponde: no afirmar que se mandó y dejar el motivo donde
 * quien lo pidió lo ve.
 *
 * 🔴 **El mensaje NO lleva la casilla.** `getMessage()` devuelve siempre `MOTIVO`, igual para una casilla o para diez. La dirección entera ya la
 * conoce quien la cargó, y repetirla en cada respuesta de la API y en cada pantalla es regar los datos de una persona por todos lados. Quien
 * necesite las direcciones —para el log de operación del servidor, por ejemplo— las pide con `rechazadas()`: el listener
 * `AnotarMailRechazadoPorElServidor` y el comando `check_current_acounts_integrity` SÍ las escriben en el log (un registro de este servidor, que
 * `config/logging.php` conserva 14 días), porque sin la casilla no se puede saber a quién no le llegó el mail.
 *
 * 🔴 **Ningún punto de envío la deja escapar.** `Handler::register()` manda a GitHub (`GitHubErrorReporterService`) toda excepción que no esté en
 * `$dontReport`: un rechazo que llegara hasta ahí sería un "error" más en el triaje de `errores/` por cada casilla mal tipeada de un cliente. Cada
 * punto la atrapa (los tests de `tests/Feature/MailsRechazados/` verifican que ninguno termina en un 500).
 *
 * Extiende `\RuntimeException` y no `\InvalidArgumentException` a propósito: no es un argumento malo, es un fallo de entrega en tiempo de
 * ejecución, y un `catch (\Exception)` (como el de `ProcessSendAdviseMail` o el de probar el SMTP) la atrapa igual que a cualquier otra.
 *
 * Es la misma clase que usa `admin-api` desde el 6/10/2026; el motivo no habla de "la ficha" porque eso es del admin: cada punto de envío le
 * agrega su propio consejo.
 */
class MailRechazadoPorElServidorException extends \RuntimeException
{
    /**
     * Lo que se le muestra a quien lo pidió y lo que queda en el log. Dice qué pasó, sin jerga de SMTP, y no afirma de más: un 451 de
     * greylisting o un 452 de buzón lleno también entran a `failures()`, así que la casilla puede estar bien.
     */
    const MOTIVO = 'el servidor de correo rechazó la casilla del destinatario (no existe, está llena o no acepta mensajes por ahora)';

    /**
     * Las casillas que el servidor rechazó. Se guardan aparte del mensaje (ver el docblock de la clase).
     *
     * @var array<int, string>
     */
    private $rechazadas = [];

    /**
     * Arma la excepción con el motivo fijo (sin la casilla) y guarda aparte las casillas que el servidor rechazó.
     *
     * @param array<int, string> $rechazadas Las casillas rechazadas, tal como las devuelve `RechazosDeCorreoHelper::del_ultimo_envio()`.
     */
    public function __construct(array $rechazadas = [])
    {
        parent::__construct(self::MOTIVO);

        $this->rechazadas = array_values($rechazadas);
    }

    /**
     * Las casillas que el servidor rechazó, para quien las quiera loguear.
     *
     * @return array<int, string>
     */
    public function rechazadas(): array
    {
        return $this->rechazadas;
    }
}
