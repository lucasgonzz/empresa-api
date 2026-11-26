<?php

namespace Tests\Feature\Integraciones;

use App\Services\Andreani\AndreaniClient;
use App\Services\Andreani\AndreaniException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Misión envios-andreani, ronda 2 (hallazgo 4): cuando Andreani no responde, el log lleva sólo la ruta (sin query)
 * y el tipo de error. El mensaje de Guzzle trae la URL completa con `cliente` y `contrato` del comercio.
 *
 * Archivo espejo entre empresa-api y tienda-api (sólo cambia el namespace de la clase probada).
 */
class AndreaniClientLogTest extends TestCase
{
    public function test_el_log_de_una_falla_de_conexion_no_trae_cliente_ni_contrato()
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out for https://apis.andreani.com/v1/tarifas?cpDestino=5000&contrato=CONTRATO-SECRETO&cliente=CL-SECRETO');
        });

        $mensajes = [];
        Log::shouldReceive('warning')->andReturnUsing(function ($mensaje) use (&$mensajes) {
            $mensajes[] = (string) $mensaje;
        });

        try {
            (new AndreaniClient())->tarifa('CL-SECRETO', 'CONTRATO-SECRETO', '5000', [['kilos' => 1, 'volumen' => 1000, 'valorDeclarado' => 0]]);
            $this->fail('Tenía que lanzar AndreaniException.');
        } catch (AndreaniException $e) {
            $this->assertSame(0, $e->getStatus());
        }

        $this->assertNotEmpty($mensajes);
        $log = implode("\n", $mensajes);

        $this->assertStringNotContainsString('CL-SECRETO', $log);
        $this->assertStringNotContainsString('CONTRATO-SECRETO', $log);
        $this->assertStringNotContainsString('cliente=', $log);
        $this->assertStringContainsString('/v1/tarifas', $log, 'La ruta sí se loguea.');
        $this->assertStringContainsString('ConnectionException', $log, 'Y el tipo de error.');
    }
}
