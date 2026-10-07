<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Mail;
use Tests\Fakes\MailManagerSoloLoopback;
use Tests\Fakes\ServidorSmtpFake;
use Tests\TestCase;

/**
 * La guarda de seguridad de los tests de mails rechazados: `MailManagerSoloLoopback` crea el transporte `smtp` DE VERDAD, pero se niega a
 * conectarse a cualquier host que no sea de esta máquina.
 *
 * Importa porque el freno de internet de los tests (capa 4) reemplaza el `smtp` por uno en memoria, y los tests de esta misión lo restauran para poder
 * hablar SMTP con un servidor que conteste 550. El `.env.testing` de un slot trae `MAIL_HOST=smtp.hostinger.com`: un test que restaurara el SMTP real y se
 * olvidara de apuntarlo al servidor de prueba abriría una conexión a Hostinger. Esta guarda es lo que lo impide, y sin un test propio nadie se enteraría
 * de que se aflojó.
 *
 * Los tests NO abren ningún socket: `createTransport()` solo arma el objeto transporte (el `Swift_SmtpTransport` se conecta recién al mandar), y para el
 * caso del host real alcanza con ver que lanza antes de armar nada.
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
class MailManagerSoloLoopbackTest extends TestCase
{
    /**
     * @return MailManagerSoloLoopback
     */
    private function un_manager(): MailManagerSoloLoopback
    {
        return new MailManagerSoloLoopback(app());
    }

    /**
     * @param string $host
     *
     * @return array<string, mixed>
     */
    private function config_smtp(string $host): array
    {
        return ['transport' => 'smtp', 'host' => $host, 'port' => 2525, 'encryption' => null, 'username' => null, 'password' => null];
    }

    /**
     * 🔴 El centro: un host que no es de esta máquina NO se conecta. El mensaje dice cuál fue y cómo apuntarlo al servidor de prueba.
     *
     * @dataProvider hosts_de_internet
     *
     * @param string $host
     *
     * @return void
     */
    public function test_un_host_que_no_es_de_esta_maquina_se_bloquea(string $host): void
    {
        try {
            $this->un_manager()->createTransport($this->config_smtp($host));
            $this->fail('El host «' . $host . '» no es de esta máquina: tenía que bloquearse.');
        } catch (\RuntimeException $excepcion) {
            $this->assertStringContainsString('SMTP real bloqueado', $excepcion->getMessage());
            $this->assertStringContainsString(strtolower(trim($host)), $excepcion->getMessage());
        }
    }

    /**
     * Hosts que NO son de esta máquina, incluidos los que se parecen a uno que sí lo es.
     *
     * @return array<string, array<int, string>>
     */
    public static function hosts_de_internet(): array
    {
        return [
            'el SMTP de Hostinger del .env.testing' => ['smtp.hostinger.com'],
            'un dominio cualquiera'                 => ['mail.ejemplo.test'],
            'una IP pública'                        => ['8.8.8.8'],
            'localhost.ejemplo.test se parece a localhost' => ['localhost.ejemplo.test'],
            '127.0.0.1.ejemplo.test se parece a la IP' => ['127.0.0.1.ejemplo.test'],
            'host vacío'                            => [''],
            'con mayúsculas y espacios'             => ['  SMTP.Hostinger.COM '],
        ];
    }

    /**
     * Los hosts de esta máquina pasan (y se arma el transporte de verdad, `Swift_SmtpTransport`, sin conectarse).
     *
     * @dataProvider hosts_de_esta_maquina
     *
     * @param string $host
     *
     * @return void
     */
    public function test_un_host_de_esta_maquina_arma_el_transporte_smtp_de_verdad(string $host): void
    {
        $transporte = $this->un_manager()->createTransport($this->config_smtp($host));

        $this->assertInstanceOf(\Swift_SmtpTransport::class, $transporte);
        $this->assertSame($host, $transporte->getHost());
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function hosts_de_esta_maquina(): array
    {
        return [
            'la IP de loopback' => ['127.0.0.1'],
            'localhost'         => ['localhost'],
            'loopback IPv6'     => ['::1'],
        ];
    }

    /**
     * 🔴 El nombre del transporte NO distingue mayúsculas: Laravel resuelve el método `create<Nombre>Transport()` y PHP no distingue mayúsculas en un nombre de
     * método, así que `SMTP` o `Smtp` arman el mismo transporte real. Con una comparación exacta (`=== 'smtp'`) esas dos variantes saltaban la guarda (medido
     * el 7/10/2026 contra el `MailManager` de Laravel 8.83.29).
     *
     * @dataProvider variantes_del_nombre_smtp
     *
     * @param string $nombre
     *
     * @return void
     */
    public function test_el_nombre_del_transporte_smtp_no_distingue_mayusculas(string $nombre): void
    {
        $config = $this->config_smtp('smtp.ejemplo.test');

        $config['transport'] = $nombre;

        try {
            $this->un_manager()->createTransport($config);
            $this->fail('El transporte «' . $nombre . '» a un host que no es de esta máquina tenía que bloquearse.');
        } catch (\RuntimeException $excepcion) {
            $this->assertStringContainsString('SMTP real bloqueado', $excepcion->getMessage());
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function variantes_del_nombre_smtp(): array
    {
        return [
            'SMTP'          => ['SMTP'],
            'Smtp'          => ['Smtp'],
            'con espacios'  => ['  smtp '],
        ];
    }

    /**
     * 🔴 Es una LISTA BLANCA: los transportes que salen de la aplicación (HTTP a un proveedor de mail real, o un proceso del sistema) se bloquean sin mirar nada
     * más. Hoy ninguna configuración del repo los usa; esta clase existe para que NADA pueda salir de un test.
     *
     * @dataProvider transportes_que_salen_de_la_aplicacion
     *
     * @param string $nombre
     *
     * @return void
     */
    public function test_los_transportes_que_salen_de_la_aplicacion_se_bloquean(string $nombre): void
    {
        try {
            $this->un_manager()->createTransport(['transport' => $nombre, 'host' => '127.0.0.1']);
            $this->fail('El transporte «' . $nombre . '» sale de la aplicación: tenía que bloquearse.');
        } catch (\RuntimeException $excepcion) {
            $this->assertStringContainsString('Transporte de mail bloqueado en los tests', $excepcion->getMessage());
            $this->assertStringContainsString(strtolower($nombre), $excepcion->getMessage());
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function transportes_que_salen_de_la_aplicacion(): array
    {
        return [
            'mailgun'  => ['mailgun'],
            'ses'      => ['ses'],
            'postmark' => ['postmark'],
            'sendmail' => ['sendmail'],
            'mail'     => ['mail'],
            'Mailgun'  => ['Mailgun'],
            'vacío'    => [''],
            'inventado' => ['transporte-inventado'],
        ];
    }

    /**
     * Los transportes que no salen de la aplicación (`array`, `log`) siguen andando sin mirar ningún host.
     *
     * @return void
     */
    public function test_los_transportes_que_no_salen_de_la_aplicacion_no_se_tocan(): void
    {
        $this->assertInstanceOf(\Illuminate\Mail\Transport\ArrayTransport::class, $this->un_manager()->createTransport(['transport' => 'array']));
        $this->assertInstanceOf(\Illuminate\Mail\Transport\LogTransport::class, $this->un_manager()->createTransport(['transport' => 'log']));
    }

    /**
     * 🔴 `failover` arma cada transporte interno con `createTransport()`, o sea que cada uno pasa por la guarda: un `failover` con un mailer interno que sale de la
     * aplicación (un SMTP de internet o un proveedor de mail) se bloquea en vez de armarse. Sin este test nadie notaría que un cambio de Laravel armara los internos por otro
     * camino y `failover` se volviera la puerta trasera de la lista blanca.
     *
     * @dataProvider mailers_internos_que_salen_de_la_aplicacion
     *
     * @param array<string, mixed> $mailer_interno Configuración del mailer interno.
     * @param string               $mensaje        Lo que tiene que decir la excepción.
     *
     * @return void
     */
    public function test_un_failover_con_un_mailer_interno_que_sale_de_la_aplicacion_se_bloquea(array $mailer_interno, string $mensaje): void
    {
        config([
            'mail.mailers.registro_de_prueba' => ['transport' => 'log'],
            'mail.mailers.interno_de_prueba'  => $mailer_interno,
        ]);

        try {
            $this->un_manager()->createTransport(['transport' => 'failover', 'mailers' => ['registro_de_prueba', 'interno_de_prueba']]);
            $this->fail('El failover tiene un mailer interno que sale de la aplicación: tenía que bloquearse.');
        } catch (\RuntimeException $excepcion) {
            $this->assertStringContainsString($mensaje, $excepcion->getMessage());
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function mailers_internos_que_salen_de_la_aplicacion(): array
    {
        return [
            'un SMTP de internet'  => [['transport' => 'smtp', 'host' => 'smtp.ejemplo.test', 'port' => 2525, 'encryption' => null, 'username' => null, 'password' => null], 'SMTP real bloqueado'],
            'un proveedor de mail' => [['transport' => 'mailgun'], 'Transporte de mail bloqueado en los tests'],
        ];
    }

    /**
     * Y un `failover` hecho solo con transportes de esta máquina sí se arma (sin abrir ningún socket: los transportes internos se conectan recién al mandar).
     *
     * @return void
     */
    public function test_un_failover_con_transportes_de_esta_maquina_se_arma(): void
    {
        config([
            'mail.mailers.local_de_prueba'    => $this->config_smtp('127.0.0.1'),
            'mail.mailers.registro_de_prueba' => ['transport' => 'log'],
        ]);

        $transporte = $this->un_manager()->createTransport(['transport' => 'failover', 'mailers' => ['local_de_prueba', 'registro_de_prueba']]);

        $this->assertInstanceOf(\Swift_FailoverTransport::class, $transporte);
    }

    /**
     * 🔴 Y la aplicación sin esta restauración sigue con el transporte en memoria de TODOS los tests: la capa 4 del freno de internet no se afloja por
     * existir esta clase. Solo `ServidorSmtpFake::restaurar_el_transporte_smtp()` la reemplaza, y solo para el test que la pide.
     *
     * @return void
     */
    public function test_sin_restaurar_el_smtp_de_los_tests_sigue_siendo_en_memoria(): void
    {
        config(['mail.mailers.smtp.host' => 'smtp.ejemplo.test']);
        app('mail.manager')->purge('smtp');

        $transporte = app('mail.manager')->mailer('smtp')->getSwiftMailer()->getTransport();

        $this->assertInstanceOf(\Illuminate\Mail\Transport\ArrayTransport::class, $transporte);
        $this->assertNotInstanceOf(MailManagerSoloLoopback::class, app('mail.manager'));
    }

    /**
     * `restaurar_el_transporte_smtp()` instala el manager que bloquea hosts: con un host real en el config, el primer uso del mailer falla en vez de conectarse.
     *
     * @return void
     */
    public function test_al_restaurar_el_smtp_un_host_real_en_el_config_falla_en_el_primer_uso(): void
    {
        ServidorSmtpFake::restaurar_el_transporte_smtp();

        $this->assertInstanceOf(MailManagerSoloLoopback::class, app('mail.manager'));

        // 🔴 Un host de un TLD reservado (`.test`): este es el único test que MANDA de verdad con el transporte restaurado, y si la guarda se aflojara no puede
        // llegar a un servidor real (el DNS no lo resuelve). Con el host de Hostinger del `.env.testing` esa red de seguridad no existiría.
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.ejemplo.test']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SMTP real bloqueado');

        Mail::raw('hola', function ($mensaje) {
            $mensaje->to('alguien@ejemplo.test')->subject('prueba');
        });
    }
}
