<?php

namespace Tests\Unit;

use App\Services\Andreani\AndreaniTarifaNormalizer;
use Tests\TestCase;

/**
 * Misión envios-andreani, ronda 2 (hallazgo 1): Andreani sólo entiende el código postal NUMÉRICO de 4 dígitos
 * (medido: `cpDestino=1425` da 200; `cpDestino=X5000ABC` y `codigoPostal=X5000ABC` dan 400). Google Places devuelve
 * el CPA ("X5000ABC", "C1425ABC") y el teclado del teléfono lo ensucia (" x5000-abc "), así que
 * `AndreaniTarifaNormalizer::codigo_postal_numerico()` saca de ahí los 4 dígitos.
 *
 * Archivo espejo entre empresa-api y tienda-api.
 */
class AndreaniCodigoPostalTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public function formasDelCodigoPostal()
    {
        return [
            'numérico'                  => ['5000', '5000'],
            'letra y 4 dígitos'         => ['X5000', '5000'],
            'CPA completo'              => ['X5000ABC', '5000'],
            'CPA de la capital'         => ['C1425ABC', '1425'],
            'CPA sucio de teléfono'     => [' x5000-abc ', '5000'],
            'CPA con espacios'          => ['B 1900 AAA', '1900'],
            'número como entero'        => [1425, '1425'],
        ];
    }

    /**
     * @dataProvider formasDelCodigoPostal
     */
    public function test_saca_los_cuatro_digitos_del_codigo_postal($entrada, $esperado)
    {
        $this->assertSame($esperado, AndreaniTarifaNormalizer::codigo_postal_numerico($entrada));
    }

    public function test_lo_que_no_es_un_codigo_postal_queda_limpio_y_sin_inventar_digitos()
    {
        // Andreani lo rechazará con su 400 (sin_tarifa): acá no se adivina nada.
        $this->assertSame('ABC', AndreaniTarifaNormalizer::codigo_postal_numerico('abc'));
        $this->assertSame('12345', AndreaniTarifaNormalizer::codigo_postal_numerico('12345'));
        $this->assertSame('', AndreaniTarifaNormalizer::codigo_postal_numerico(null));
        $this->assertSame('', AndreaniTarifaNormalizer::codigo_postal_numerico(['5000']));
    }
}
