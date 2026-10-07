<?php

namespace Tests\Fakes;

/**
 * El ciclo de vida del servidor SMTP de prueba para una clase de test: levantarlo, apuntar el mailer por defecto a él y
 * bajarlo al terminar.
 *
 * Lo comparten los tests de `tests/Feature/MailsRechazados/` y el de `RechazosDeCorreoHelperTest` (que no es un Feature). Vive en
 * `tests/Fakes/` por lo mismo que el servidor: ahí se lanza un proceso con `proc_open`, y el meta-test del freno de internet
 * (`SalidaAInternetDeLosTestsTest`) no mira esa carpeta.
 *
 * 🔴 Si el servidor no arranca y el entorno podía lanzarlo, el test FALLA (ver `FallaSiElServidorSmtpNoArranca`): estos tests son la
 * única prueba de que SwiftMailer no tira excepción ante un 550, y un servidor roto no puede dejar la suite en verde.
 *
 * Se baja solo al terminar cada test (`@after`), así la clase que lo usa no tiene que acordarse de sobreescribir `tearDown()`.
 */
trait ConServidorSmtpDePrueba
{
    use FallaSiElServidorSmtpNoArranca;

    /**
     * Los servidores SMTP de mentira que levantó el test, para bajarlos al terminar.
     *
     * @var array<int, ServidorSmtpFake>
     */
    private $servidores_smtp = [];

    /**
     * Baja todos los servidores que levantó el test. Corre solo, después de cada test.
     *
     * @after
     *
     * @return void
     */
    public function bajar_los_servidores_smtp(): void
    {
        foreach ($this->servidores_smtp as $servidor) {
            $servidor->bajar();
        }

        $this->servidores_smtp = [];
    }

    /**
     * Lanza un servidor SMTP de verdad (sin tocar ningún mailer). Si este entorno no puede lanzar procesos el test se
     * saltea; si puede y el servidor no arranca, el test FALLA (ver el trait).
     *
     * @param string $modo ServidorSmtpFake::MODO_RECHAZA | MODO_ACEPTA.
     *
     * @return ServidorSmtpFake
     */
    protected function levantar_un_smtp(string $modo): ServidorSmtpFake
    {
        $servidor = ServidorSmtpFake::levantar($modo);

        if ($servidor === null) {
            $this->el_servidor_smtp_no_arranco();
        }

        $this->servidores_smtp[] = $servidor;

        return $servidor;
    }

    /**
     * Lo levanta y lo deja como el mailer POR DEFECTO (`smtp`), que es el que usa `Mail::to()` y `Mail::raw()`.
     * En `phpunit.xml` el default es `array`, que nunca rechaza nada, y el transporte `smtp` es en memoria: acá se
     * restaura el de verdad, pero solo hacia 127.0.0.1.
     *
     * Hay que llamarlo ANTES de que el test use un mailer por primera vez: el administrador de mails guarda cada mailer ya armado.
     *
     * @param string $modo ServidorSmtpFake::MODO_RECHAZA | MODO_ACEPTA.
     *
     * @return ServidorSmtpFake
     */
    protected function levantar_un_smtp_por_defecto(string $modo): ServidorSmtpFake
    {
        $servidor = $this->levantar_un_smtp($modo);

        $servidor->apuntar_el_mailer('smtp');

        config([
            'mail.default' => 'smtp',
            'mail.from'    => ['address' => 'comercio@ejemplo.test', 'name' => 'Comercio de prueba'],
        ]);

        return $servidor;
    }

    /**
     * Una casilla de destino que no se repite entre tests, en un dominio que no existe (`.test` está reservado).
     *
     * @param string $prefijo Para reconocerla en un mensaje de error.
     *
     * @return string
     */
    protected function una_casilla(string $prefijo = 'destino'): string
    {
        return $prefijo . '-' . uniqid() . '@ejemplo.test';
    }
}
