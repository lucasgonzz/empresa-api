<?php

namespace Tests\Feature\Caja;

use App\Exceptions\OperacionDeCajaInvalidaException;
use App\Http\Controllers\Helpers\caja\MovimientoCajaHelper;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Validacion_de_operacion_Test extends TestCase
{
    /** @test */
    public function el_rechazo_conserva_el_motivo_para_los_catch_historicos_y_la_respuesta_422()
    {
        $mensaje = 'La caja Principal no tiene ninguna apertura registrada, asi que no puede recibir movimientos. Hay que abrirla primero.';
        try {
            MovimientoCajaHelper::rechazar_operacion($mensaje);
            $this->fail('La operación debe rechazarse.');
        } catch (OperacionDeCajaInvalidaException $excepcion) {
            $this->assertInstanceOf(ValidationException::class, $excepcion);
            $this->assertSame($mensaje, $excepcion->getMessage());
            $this->assertSame(['caja_id' => [$mensaje]], $excepcion->errors());
            $response = $this->app->make(ExceptionHandler::class)->render(Request::create('/api/sale'), $excepcion);
            $this->assertSame(422, $response->getStatusCode());
            $this->assertSame(['message' => $mensaje, 'errors' => ['caja_id' => [$mensaje]]], $response->getData(true));
        }
    }

    /** @test */
    public function una_apertura_invalida_conserva_su_campo_y_el_primer_motivo()
    {
        $mensaje = 'La apertura elegida no es la vigente de la caja.';
        try {
            MovimientoCajaHelper::rechazar_operacion($mensaje, 'apertura_caja_id');
            $this->fail('La apertura inválida debe rechazarse.');
        } catch (OperacionDeCajaInvalidaException $excepcion) {
            $this->assertSame($mensaje, $excepcion->getMessage());
            $this->assertSame(['apertura_caja_id' => [$mensaje]], $excepcion->errors());
            $this->assertSame(422, $excepcion->response->getStatusCode());
        }
    }
}
