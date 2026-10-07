<?php

namespace Tests\Fakes;

use Illuminate\Mail\MailManager;

/**
 * Un administrador de mails que crea el transporte `smtp` DE VERDAD, pero solo hacia esta máquina.
 *
 * 🔴 POR QUÉ EXISTE. `Tests\TestCase::cerrar_la_salida_a_internet()` reemplaza el transporte `smtp` de los
 * mailers por uno en memoria (`ArrayTransport`) en CADA test: es la capa 4 del freno de internet (6/10/2026),
 * porque `ClientMailConfigHelper::apply()` pisa en caliente `mail.default` con el SMTP de la casilla del comercio
 * y eso abriría un socket de verdad. Pero con un transporte en memoria el servidor NUNCA rechaza una casilla, y
 * el defecto de esta misión (SwiftMailer no tira excepción ante un 550 en el RCPT TO: `send()` vuelve normal y la
 * casilla queda en `failures()`) es invisible: ni `Mail::fake()` ni `ArrayTransport` pueden mostrarlo. Hace falta
 * hablar SMTP con un servidor que conteste 550 de verdad (`ServidorSmtpFake`).
 *
 * Qué hace. Es un `MailManager` nuevo, SIN el reemplazo en memoria (`$customCreators` vacío), así que
 * `createSmtpTransport()` de Laravel arma el transporte real con todo lo que lee del config (host, puerto, cifrado,
 * usuario, clave, timeout), igual que en producción. Se instala con `ServidorSmtpFake::restaurar_el_transporte_smtp()`.
 *
 * 🔴 La guarda que no se saltea: **solo abre sockets a esta máquina** (`127.0.0.1`, `localhost`, `::1`). El
 * `.env.testing` de cada slot es una copia del `.env` de desarrollo de Lucas y trae `MAIL_HOST=smtp.hostinger.com`:
 * un test que restaurara el SMTP real sin esta guarda y se olvidara de apuntarlo al servidor de prueba abriría una
 * conexión a Hostinger. Con esta clase, ese olvido es una excepción en el primer envío, no una conexión a internet.
 *
 * Cada test arma su propia aplicación (`createApplication()`), así que lo que se instala acá no se filtra al test
 * siguiente: no hay nada que desarmar.
 */
class MailManagerSoloLoopback extends MailManager
{
    /**
     * Los únicos hosts a los que el transporte `smtp` puede conectarse.
     */
    const HOSTS_PERMITIDOS = ['127.0.0.1', 'localhost', '::1'];

    /**
     * Los únicos transportes que este manager arma: `smtp` (solo hacia esta máquina), los dos que no salen de la aplicación (`array` y `log`) y `failover`, que
     * no abre nada por su cuenta: crea cada uno de sus transportes internos con `createTransport()`, o sea que cada uno pasa por esta guarda.
     *
     * 🔴 Una LISTA BLANCA y no solo "el smtp mira el host": `mailgun`, `ses`, `postmark` (HTTP a un proveedor real) y `sendmail`/`mail` (un proceso del sistema) no
     * pasaban por ninguna guarda. Hoy ninguna configuración de este repo los usa, pero esta clase existe para que NADA pueda salir de un test.
     */
    const TRANSPORTES_PERMITIDOS = ['smtp', 'array', 'log', 'failover'];

    /**
     * Crea el transporte de un mailer; el `smtp` solo si apunta a esta máquina.
     *
     * @param array $config Configuración del mailer (`mail.mailers.<nombre>`).
     *
     * @return mixed El transporte (`Swift_Transport`).
     *
     * @throws \RuntimeException Si el transporte es `smtp` y el host no es de esta máquina.
     */
    public function createTransport(array $config)
    {
        // Sin distinguir mayúsculas: Laravel resuelve el método `create<Nombre>Transport()` y PHP no distingue mayúsculas en un nombre de método, así que
        // `SMTP` o `Smtp` arman el mismo transporte real (medido el 7/10/2026 contra el `MailManager` de Laravel 8.83.29).
        $transporte = strtolower(trim((string) (isset($config['transport']) ? $config['transport'] : '')));

        if (! in_array($transporte, self::TRANSPORTES_PERMITIDOS, true)) {
            throw new \RuntimeException(
                'Transporte de mail bloqueado en los tests: «' . $transporte . '». Estos tests solo arman el transporte smtp (hacia 127.0.0.1), array, log y failover: '
                . 'mailgun, ses, postmark, sendmail y mail salen de la aplicación.'
            );
        }

        if ($transporte === 'smtp') {
            $host = strtolower(trim((string) (isset($config['host']) ? $config['host'] : '')));

            if (! in_array($host, self::HOSTS_PERMITIDOS, true)) {
                throw new \RuntimeException(
                    'SMTP real bloqueado en los tests: el mailer apunta a «' . $host . '», que no es de esta máquina. '
                    . 'Los tests de rechazos de mail hablan SMTP SOLO con el servidor de prueba (127.0.0.1): '
                    . 'apuntá el mailer con ServidorSmtpFake::apuntar_el_mailer() o cargá el host 127.0.0.1 en la configuración.'
                );
            }
        }

        return parent::createTransport($config);
    }
}
