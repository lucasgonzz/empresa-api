<?php

namespace App\Services\Mail;

use App\Mail\Helpers\RechazosDeCorreoHelper;
use App\Models\OnlineConfiguration;
use App\Models\User;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Mail\Transport\LogTransport;
use Psr\Log\LoggerInterface;

class PruebaDeCorreoService
{
    private $configuracion_base;

    public function __construct(array $configuracion_base)
    {
        $this->configuracion_base = $configuracion_base;
    }

    public function configuracion($user_id): array
    {
        $mail = $this->configuracion_base;
        $origen = 'comerciocity';
        $propia = OnlineConfiguration::where('user_id', $user_id)->first();

        // Es el mismo criterio de selección que los envíos normales; la prueba no lo modifica.
        if ($propia && $propia->mail_enabled && !empty($propia->mail_host)
            && !empty($propia->mail_username) && !empty($propia->mail_password)) {
            $origen = 'propia';
            $mail['default'] = 'smtp';
            $mail['mailers']['smtp'] = array_merge($mail['mailers']['smtp'] ?? [], [
                'transport' => 'smtp',
                'host' => $propia->mail_host,
                'port' => $propia->mail_port,
                'encryption' => $propia->mail_encryption,
                'username' => $propia->mail_username,
                'password' => $propia->mail_password,
            ]);
            $dueno = User::find($user_id);
            $mail['from'] = [
                'address' => $propia->mail_from_address ?: $propia->mail_username,
                'name' => $propia->mail_from_name ?: ($dueno ? $dueno->company_name : null),
            ];
            $mail['mailers']['smtp']['from'] = $mail['from'];
        }

        return ['mail' => $mail, 'origen' => $origen];
    }

    public function enviar(array $configuracion, string $destino): string
    {
        // Capturar Config al entrar heredaría una casilla aplicada antes en este proceso.
        // El manager privado usa la base de arranque y no comparte mailers cacheados ni modifica
        // el contenedor global, incluso cuando el transporte o un listener falla.
        $contenedor = new Container();
        $config = new Repository(config()->all());
        $config->set('mail', $configuracion['mail']);
        $contenedor->instance('config', $config);
        foreach (['view', 'events', 'queue', LoggerInterface::class] as $servicio) {
            if (app()->bound($servicio)) {
                $contenedor->instance($servicio, app($servicio));
            }
        }

        // El evento no ocurre si un listener cancela MessageSending. Se escucha en una copia
        // privada para no acumular callbacks en workers ni afirmar éxito sobre un envío cancelado.
        $eventos = clone app('events');
        $enviado = null;
        $eventos->listen(MessageSent::class, function ($evento) use (&$enviado) {
            $enviado = $evento->message;
        });
        $contenedor->instance('events', $eventos);

        // Clonar conserva transportes registrados por la aplicación (y las guardas de los tests).
        $manager = clone app('mail.manager');
        $manager->forgetMailers();
        $manager->setApplication($contenedor);
        $mailer = $manager->mailer();
        try {
            $mailer->send(['raw' => 'Este es un correo de prueba de ComercioCity.'], ['prueba_correo_aislada' => true], function ($message) use ($destino) {
                $message->to($destino)->subject('Mail de prueba - ComercioCity');
            });
            RechazosDeCorreoHelper::fallar_si_hubo_rechazos_del_mailer($mailer);
            if (!$enviado) {
                throw new \RuntimeException('El envío de prueba fue cancelado.');
            }
            $transporte = $mailer->getSwiftMailer()->getTransport();
            while ($transporte instanceof \Swift_Transport_LoadBalancedTransport) {
                $transporte = $transporte->getLastUsedTransport();
            }
            if (!$transporte || $transporte instanceof ArrayTransport || $transporte instanceof LogTransport) {
                throw new \RuntimeException('El transporte de prueba no envió correo.');
            }
            $remitentes = array_keys($enviado->getFrom() ?: []);
            return $remitentes[0] ?? '';
        } finally {
            // Fallar al cerrar no convierte en fallido un correo que el servidor ya aceptó,
            // ni reemplaza el motivo de un rechazo. La instancia privada no se reutiliza.
            try {
                $mailer->getSwiftMailer()->getTransport()->stop();
            } catch (\Throwable $error_de_cierre) {
            }
        }
    }
}
