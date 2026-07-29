<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Marca si ya corrimos la verificación del guard en este proceso. El chequeo
     * en sí corre una sola vez (no se recalcula en cada test), pero si detectó un
     * problema, el mensaje queda cacheado y se re-lanza en cada test para frenar
     * la suite entera (ver guardBaseDeTesting()).
     *
     * @var bool
     */
    protected static $baseDeTestingVerificada = false;

    /**
     * Mensaje de error cacheado si la verificación falló. Null si pasó.
     *
     * @var string|null
     */
    protected static $errorDeGuardDeTesting = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guardBaseDeTesting();
    }

    /**
     * Aborta la suite entera si la conexión activa apunta a una base que no
     * es de testing.
     *
     * Lee el nombre real de la conexión activa (DB::connection()->getDatabaseName()),
     * no la variable de entorno: lo que importa es contra qué se va a escribir de
     * verdad, no lo que alguien creyó configurar (un <server> de phpunit.xml puede
     * estar pisando el .env.testing).
     *
     * El chequeo en sí se computa una sola vez por proceso. Pero si falla, el
     * error se re-lanza en cada test subsiguiente (barato: no vuelve a leer nada,
     * solo repite el mensaje cacheado) para que la suite entera aborte en vez de
     * fallar un solo test y seguir corriendo el resto contra la base equivocada.
     *
     * @return void
     */
    protected function guardBaseDeTesting()
    {
        if (!static::$baseDeTestingVerificada) {
            static::$baseDeTestingVerificada = true;
            static::$errorDeGuardDeTesting = $this->calcularErrorDeGuardDeTesting();
        }

        if (static::$errorDeGuardDeTesting !== null) {
            throw new \RuntimeException(static::$errorDeGuardDeTesting);
        }
    }

    /**
     * @return string|null Mensaje de error si la base activa no es segura, null si está OK.
     */
    protected function calcularErrorDeGuardDeTesting()
    {
        $nombreBaseActiva = (string) DB::connection()->getDatabaseName();
        $nombreBaseActivaMinuscula = strtolower($nombreBaseActiva);

        $noContieneTest = strpos($nombreBaseActivaMinuscula, 'test') === false;

        if (!$noContieneTest) {
            return null;
        }

        return "Guard de base de testing: se abortó la suite antes de correr tests contra una base insegura.\n"
            . "Base detectada (conexión activa): \"{$nombreBaseActiva}\".\n"
            . "Problema: el nombre \"{$nombreBaseActiva}\" no contiene la cadena \"test\", así que no parece "
            . "ser una base de testing segura.\n"
            . "Qué hacer: revisá el DB_DATABASE de tu .env.testing y confirmá que phpunit.xml no tenga "
            . "un <server name=\"DB_DATABASE\"> hardcodeado que lo esté pisando.";
    }
}
