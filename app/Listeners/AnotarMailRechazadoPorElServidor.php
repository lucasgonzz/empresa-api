<?php

namespace App\Listeners;

use App\Mail\Helpers\RechazosDeCorreoHelper;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Log;

/**
 * Anota en el log los mails que el servidor SMTP rechazó. SOLO anota: nunca tira ni cambia el flujo de nadie.
 *
 * Es la red de seguridad de la clase "no tiró excepción no es salió" para lo que NINGÚN punto de envío puede leer:
 *
 *   - un mail ENCOLADO (`Mail::to()->queue()`: `ComercioCityMailHelper::new_sale()` y `nueva_oferta()`) se manda en un worker, lejos de quien lo pidió;
 *   - una `Notification` por el canal `mail` (`MessageSend`, `RespuestaDelComercioPorMail`) no pasa por la fachada `Mail`: manda `MailChannel` por su cuenta;
 *   - y los avisos internos que no afirman nada (el aviso de un error del SPA a Lucas, el envío muerto de `check_stock_movements`).
 *
 * Hasta ahora el único rastro de esos mails era `Log::info('Se mando mail a …')` al ENCOLAR, que no dice si el mail salió. Con esto, un rechazo deja un
 * renglón verdadero: es lo que se busca en el log cuando un cliente dice "nunca me llegó".
 *
 * 🔴 POR QUÉ NO TIRA. Sería lo "correcto" para un envío que quiere enterarse, pero acá rompería cosas:
 *   - (el motivo que decide) con `QUEUE_CONNECTION=sync` (los tests, y algunos clientes) una excepción de un mail encolado VUELVE al request que lo encoló: un
 *     500 sobre una venta que ya hizo commit (`SaleController`) o sobre un pedido ya confirmado;
 *   - (secundario, y con salida) un job que tira pasa por `Handler::report()` y de ahí a `GitHubErrorReporterService`: cada casilla mal tipeada de un cliente
 *     sería un "error" más en el triaje de `errores/`, y no es un bug del sistema. Eso por sí solo se resolvería sumando `MailRechazadoPorElServidorException` a
 *     `Handler::$dontReport`; no se hizo para no tocar `Handler` (fuera de la lista cerrada de esta misión), y no alcanzaría por el motivo de arriba.
 * Por eso tampoco convierte el envío encolado en uno sincrónico: el comentario de `ComercioCityMailHelper` lo prohíbe (el request se quedaría esperando al SMTP).
 *
 * Se engancha a `MessageSent` (en `EventServiceProvider`), que `Illuminate\Mail\Mailer` dispara justo DESPUÉS de mandarle el mail al transporte y con la lista de
 * rechazos de ese envío ya cargada. Lee del mailer por defecto: es el que usan todos los envíos de `app/` hoy (`Mail::to()` y las notificaciones).
 *
 * 🔴 LÍMITE: `MessageSent` no trae el NOMBRE del mailer (Laravel 8.83), así que si algún día un envío sale por un mailer con nombre (`Mail::mailer('x')`, o una
 * `Notification` con `->mailer('x')`) este listener mira la lista del default: da un falso negativo (el de `x` rechazó y no se anota) y puede dar un falso
 * positivo (anota un rechazo viejo del default con el asunto del envío bueno). Hoy no pasa: ningún envío de `app/` usa un mailer con nombre, y
 * `ClientMailConfigHelper::apply()` fuerza `mail.default` y olvida los mailers armados (`forgetMailers()`). Quien agregue un envío con nombre tiene que mirar
 * los rechazos de ESE mailer con `RechazosDeCorreoHelper::del_ultimo_envio('x')`. El guardián (`TodoEnvioDeMailMiraLosRechazosTest`) solo exige que cada envío
 * tenga UN chequeo; no mira con qué nombre se lo llamó, así que eso depende de quien lo escriba.
 *
 * Las casillas rechazadas quedan en el log (`config/logging.php`: canal por defecto `stack`/`daily`, nivel `warning`, 14 días): es un registro de operación de
 * este servidor, sin el que no se puede saber a quién no le llegó el mail.
 *
 * El evento NO se dispara bajo `Mail::fake()`, y con el transporte `array` (el de los tests) no hay rechazos: en la suite este listener solo hace algo en los tests que hablan SMTP de verdad
 * con el servidor de prueba (`tests/Feature/MailsRechazados/`).
 *
 * Los límites son los de `RechazosDeCorreoHelper` (solo el rechazo síncrono en el RCPT TO; un 4xx también entra).
 */
class AnotarMailRechazadoPorElServidor
{
    /**
     * Si el último envío del mailer tuvo casillas rechazadas, lo deja en el log.
     *
     * 🔴 Todo va dentro de un `try/catch (\Throwable)`: un listener que tira dentro de `Mailer::send()` rompería el envío que lo disparó, y esto existe justamente
     * para no cambiar ningún flujo. Si ni siquiera se puede escribir el log, no pasa nada.
     *
     * @param MessageSent $evento El mail que acaba de pasar por el transporte.
     *
     * @return void
     */
    public function handle(MessageSent $evento): void
    {
        try {
            // Las casillas que el servidor rechazó en este envío (vacío si aceptó a todas).
            $rechazadas = RechazosDeCorreoHelper::del_ultimo_envio();

            if (empty($rechazadas)) {
                return;
            }

            // Las casillas van en el contexto del log (es un registro de operación de este servidor) y no en el texto, igual que en la excepción.
            Log::warning('Mail rechazado por el servidor de correo: el mail NO salió.', [
                'asunto'     => (string) $evento->message->getSubject(),
                'rechazadas' => $rechazadas,
            ]);
        } catch (\Throwable $excepcion) {
            // Anotar es lo único que hace este listener: si falla, el envío que lo disparó sigue como si nada.
        }
    }
}
