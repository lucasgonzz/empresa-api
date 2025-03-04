<?php

namespace Tests\Browser;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class client_Test extends DuskTestCase
{
    /**
     * A Dusk test example.
     *
     * @return void
     */
    public function test_client()
    {

        dump('1');
        // Obtener el cliente desde una variable de entorno o argumento
        $cliente = env('DUSK_CLIENTE');

        if (!$cliente) {
            dump('Indique el cliente!');
            return;
        }
        dump('2');

        // Cargar el archivo JSON con la configuración de pruebas por cliente
        $config = json_decode(file_get_contents(base_path('tests/Browser/pruebas_por_cliente.json')), true);

        // Verificar si el cliente tiene pruebas asignadas
        if (!isset($config[$cliente])) {
            $this->markTestSkipped("No hay pruebas definidas para el cliente: $cliente.");
        }
        // Recorrer cada módulo y ejecutar sus pruebas
        foreach ($config[$cliente] as $modulo => $pruebas) {
            foreach ($pruebas as $testClass) {
                $rutaClase = "Tests\\Browser\\$modulo\\Clientes\\$cliente\\$testClass";
                // $this->runDuskTest($rutaClase);

                $this->browse(function (Browser $browser) use ($rutaClase) {
                    (new $rutaClase())->run();
                });
            }
        }
    }

    
    /**
     * Ejecuta dinámicamente una prueba Dusk.
     */
    protected function runDuskTest($testClass)
    {
        $testInstance = new $testClass();
        // $testInstance->setUp();  // Inicializar Laravel Dusk
        $testInstance->run();
        // $testInstance->tearDown();  // Limpiar después de la ejecución
    }
}
