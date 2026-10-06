<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Tests\Fakes\EntornoSinCredenciales;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * 🔴 Antes de arrancar la aplicación se neutralizan las credenciales reales de servicios externos (ver
     * `EntornoSinCredenciales`): el `.env.testing` de cada slot las trae, y hay tests que borran variables del
     * entorno al terminar, así que se reaplica en CADA arranque y no una vez en `phpunit.xml`.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        EntornoSinCredenciales::aplicar();

        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
