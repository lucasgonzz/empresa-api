<?php

namespace Tests\Feature\MailsRechazados;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\Fakes\ConServidorSmtpDePrueba;
use Tests\TestCase;

/**
 * Base de los tests de "un mail que el servidor SMTP rechaza no figura como enviado" (misión
 * mails-rechazados-por-smtp-empresa, 7/10/2026).
 *
 * El defecto: SwiftMailer (Laravel 8) NO tira excepción cuando el servidor contesta 550 en el RCPT TO.
 * `send()` vuelve normal, el mail no sale y la casilla queda en `Mail::mailer(...)->failures()`. Quien solo
 * mira si `send()` tiró da por enviado un mail que nunca salió. Ni `Mail::fake()` ni el transporte `array`
 * pueden mostrarlo: hace falta hablar SMTP con un servidor que conteste 550 de verdad.
 *
 * Qué pone esta clase:
 *  - El servidor SMTP de prueba (`ConServidorSmtpDePrueba`: un proceso aparte que escucha SOLO en 127.0.0.1), con la regla
 *    heredada de la misión de admin-api: si no arranca y el entorno podía lanzarlo, el test FALLA (no se saltea en silencio).
 *  - Un comercio (dueño) propio, creado dentro de la transacción: los tests no dependen del fixture sembrado.
 *
 * 🔴 Ningún test de esta carpeta sale a internet: el único socket que abren es el del servidor de prueba, en esta
 * máquina (`MailManagerSoloLoopback` se niega a conectarse a cualquier otro host).
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
abstract class MailsRechazadosTestCase extends TestCase
{
    use DatabaseTransactions;
    use ConServidorSmtpDePrueba;

    /**
     * Un comercio (usuario dueño, sin `owner_id`) propio del test, con un mail que no se repite.
     *
     * @param array<string, mixed> $atributos Lo que se quiera pisar.
     *
     * @return User
     */
    protected function crear_un_dueno(array $atributos = []): User
    {
        return User::create(array_merge([
            'name'         => 'Dueño de prueba',
            'company_name' => 'Comercio de prueba ' . uniqid(),
            'email'        => 'dueno-' . uniqid() . '@ejemplo.test',
            'password'     => Hash::make('secreto'),
        ], $atributos));
    }
}
