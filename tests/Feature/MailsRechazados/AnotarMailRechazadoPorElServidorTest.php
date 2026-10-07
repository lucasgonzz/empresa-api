<?php

namespace Tests\Feature\MailsRechazados;

use App\Listeners\AnotarMailRechazadoPorElServidor;
use App\Providers\EventServiceProvider;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Tests\Fakes\ServidorSmtpFake;

/**
 * La red de seguridad que anota los mails que el servidor SMTP rechazó: el listener de `MessageSent`.
 *
 * Existe para lo que NINGÚN punto de envío puede leer: un mail encolado (se manda en un worker, lejos de quien lo pidió) y una `Notification`
 * por el canal `mail` (no pasa por la fachada `Mail`; hoy `MessageSend` y `RespuestaDelComercioPorMail`). Solo ANOTA (`Log::warning`): nunca
 * tira ni cambia el flujo de nadie. Tirar desde acá rompería flujos que no esperan una excepción (una venta que ya hizo commit, un pedido
 * confirmado) y llenaría el triaje de errores de GitHub con cada casilla mal tipeada de un cliente.
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
class AnotarMailRechazadoPorElServidorTest extends MailsRechazadosTestCase
{
    /**
     * Un `Mail::raw` de prueba, por el mailer por defecto.
     *
     * @param string $para
     *
     * @return void
     */
    private function mandar_un_mail_de_prueba(string $para): void
    {
        Mail::raw('Cuerpo de prueba.', function ($mensaje) use ($para) {
            $mensaje->to($para)->subject('Asunto de prueba');
        });
    }

    /**
     * El centro: un 550 en el RCPT TO deja un warning que dice que el mail NO salió, con el asunto y las casillas rechazadas.
     *
     * @return void
     */
    public function test_un_mail_rechazado_deja_un_warning_con_el_asunto_y_las_casillas(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_RECHAZA);

        $casilla = $this->una_casilla('rechazada');

        Log::spy();

        $this->mandar_un_mail_de_prueba($casilla);

        Log::shouldHaveReceived('warning')->withArgs(function ($texto, $contexto = []) use ($casilla) {
            return strpos((string) $texto, 'Mail rechazado por el servidor de correo') !== false
                && isset($contexto['asunto']) && $contexto['asunto'] === 'Asunto de prueba'
                && isset($contexto['rechazadas']) && $contexto['rechazadas'] === [$casilla];
        });
    }

    /**
     * Control: un mail que el servidor ACEPTA no deja ningún warning.
     *
     * @return void
     */
    public function test_un_mail_aceptado_no_deja_warning(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_ACEPTA);

        Log::spy();

        $this->mandar_un_mail_de_prueba($this->una_casilla('aceptada'));

        Log::shouldNotHaveReceived('warning');
    }

    /**
     * La red cubre una `Notification` por el canal `mail`, que no pasa por la fachada `Mail` (y donde ningún punto puede mirar el rechazo).
     *
     * @return void
     */
    public function test_cubre_una_notificacion_por_el_canal_mail(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_RECHAZA);

        $notificacion = new class extends Notification {
            public function via($notifiable)
            {
                return ['mail'];
            }

            public function toMail($notifiable)
            {
                return (new MailMessage)->subject('Notificación de prueba')->line('Hola.');
            }
        };

        Log::spy();

        NotificationFacade::route('mail', $this->una_casilla('notificada'))->notify($notificacion);

        Log::shouldHaveReceived('warning')->withArgs(function ($texto, $contexto = []) {
            return strpos((string) $texto, 'Mail rechazado por el servidor de correo') !== false
                && isset($contexto['asunto']) && $contexto['asunto'] === 'Notificación de prueba';
        });
    }

    /**
     * 🔴 El listener NUNCA rompe un envío: si no puede leer los rechazos, no tira (el helper deja su propio aviso en el log, y tampoco tira si el log falla).
     *
     * @return void
     */
    public function test_el_listener_nunca_tira_si_no_puede_leer_los_rechazos(): void
    {
        Mail::shouldReceive('mailer')->andThrow(new \RuntimeException('el mailer no responde'));
        Log::shouldReceive('warning')->andThrow(new \RuntimeException('el log no escribe'));

        (new AnotarMailRechazadoPorElServidor())->handle(new MessageSent(new \Swift_Message('Asunto de prueba')));

        $this->addToAssertionCount(1);
    }

    /**
     * 🔴 El listener NUNCA rompe un envío: si leyó un rechazo y no puede escribir el log, no tira. Es el `try/catch` PROPIO del listener (el del helper no llega
     * hasta acá): sin él, un disco lleno en `storage/logs` rompería cada envío que el servidor rechaza, justo dentro de `Mailer::send()`.
     *
     * @return void
     */
    public function test_el_listener_nunca_tira_si_no_puede_escribir_el_log(): void
    {
        Mail::shouldReceive('mailer')->with(null)->andReturnSelf();
        Mail::shouldReceive('failures')->andReturn(['rechazada@ejemplo.test']);
        Log::shouldReceive('warning')->once()->andThrow(new \RuntimeException('el log no escribe'));

        (new AnotarMailRechazadoPorElServidor())->handle(new MessageSent(new \Swift_Message('Asunto de prueba')));

        $this->addToAssertionCount(1);
    }

    /**
     * El listener está ENGANCHADO al evento: sin esto, la clase existiría y no correría nunca (el chequeo que no se ejecuta, que es la
     * clase de error de esta misión). Se verifica contra el despachador real de la aplicación.
     *
     * @return void
     */
    public function test_el_listener_esta_enganchado_al_evento_de_la_aplicacion(): void
    {
        $this->assertTrue(
            app('events')->hasListeners(MessageSent::class),
            'Nadie escucha MessageSent: el listener no está registrado en EventServiceProvider.'
        );

        // `hasListeners()` no distingue QUÉ listener: se mira también el registro del proveedor, que es el que lo engancha.
        $escuchas = app()->getProvider(EventServiceProvider::class)->listens();

        $this->assertArrayHasKey(MessageSent::class, $escuchas);
        $this->assertContains(AnotarMailRechazadoPorElServidor::class, $escuchas[MessageSent::class]);
    }
}
