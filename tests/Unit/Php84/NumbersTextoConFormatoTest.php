<?php

namespace Tests\Unit\Php84;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers as NumbersComun;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\Numbers;
use PHPUnit\Framework\TestCase;

/**
 * Misión php84-errores-fatales (1/11/2026), caso B4: guardar un artículo con el costo escrito con
 * formato («$88.782,13») daba `TypeError: Unsupported operand types: string + string` en
 * `Numbers::price()` (PHP 8 del VPS) y `Incorrect decimal value` en el shared.
 *
 * Funciones puras: no arrancan Laravel ni tocan una base. Pensado para correr en PHP 8.x: en 7.4 el
 * mismo código era un warning y por eso la regresión no se veía en el shared.
 */
class NumbersTextoConFormatoTest extends TestCase
{
    /** @return array<string, array{0: mixed, 1: float|null}> */
    public function textos_de_numero()
    {
        return [
            'el caso del log, con signo y coma'      => ['$88.782,13', 88782.13],
            'sin signo'                              => ['88.782,13', 88782.13],
            'con espacios'                           => [' $ 1.234,50 ', 1234.5],
            'coma sola es decimal'                   => ['12,5', 12.5],
            'miles con coma y decimal con punto'     => ['1,000.80', 1000.8],
            'varias comas son miles'                 => ['1,234,567', 1234567.0],
            'varios puntos, último de tres: miles'   => ['1.234.567', 1234567.0],
            'varios puntos, último de dos: decimal'  => ['$88.782.13', 88782.13],
            'un punto con tres dígitos: miles'       => ['$88.782', 88782.0],
            'negativo'                               => ['-$1.500,25', -1500.25],
            'con porcentaje'                         => ['12 %', 12.0],
            'ya es numérico: no se reinterpreta'     => ['1.234', 1.234],
            'decimal estándar'                       => ['88782.13', 88782.13],
            'entero'                                 => [15, 15.0],
            'float'                                  => [2.5, 2.5],
            'vacío'                                  => ['', null],
            'null'                                   => [null, null],
            'sin dígitos'                            => ['abc', null],
            'solo el signo'                          => ['$', null],
            'un array'                               => [[1, 2], null],
            'espacio final (7.4 y 8 igual)'          => ['1.234 ', 1.234],
            'espacio duro alrededor'                 => ["\u{00A0}\$1.234,5\u{00A0}", 1234.5],
            'menos tipográfico'                      => ["\u{2212}5", -5.0],
            'cero punto tres dígitos es decimal'     => ['$0.125', 0.125],
            'notación científica con coma: rechazo'  => ['1,5e3', null],
            'letras en el medio: rechazo'            => ['12abc3', null],
            'unidades en el medio: rechazo'          => ['5 x 10', null],
            'hexadecimal: rechazo'                   => ['0x1A', null],
            'menos al final: rechazo'                => ['5-', null],
            'negativo contable: rechazo'             => ['(5)', null],
            'menos dos veces: rechazo'               => ['-5-', null],
            'puntos que no agrupan: rechazo'         => ['1.2.3', null],
            'comas dobles: rechazo'                  => ['1,,5', null],
            'miles mal agrupados con decimal: rechazo' => ['1.2,3', null],
            'fecha: rechazo'                         => ['2024-01-05', null],
            'cientos de dígitos: no es finito'       => ['$'.str_repeat('9', 400), null],
            'INF'                                    => [INF, null],
            'NAN'                                    => [NAN, null],
        ];
    }

    /**
     * @test
     * @dataProvider textos_de_numero
     */
    public function parse_input_decimal_lee_el_formato_argentino($entrada, $esperado)
    {
        $leido = Numbers::parse_input_decimal($entrada);

        if (is_null($esperado)) {
            $this->assertNull($leido);
        } else {
            $this->assertEqualsWithDelta($esperado, $leido, 0.00001);
        }
    }

    /** @test */
    public function price_con_un_costo_con_formato_no_tira_typeerror()
    {
        $this->assertSame('$88.782,13', Numbers::price('$88.782,13', true));
        $this->assertSame('88.782,13', Numbers::price('88.782,13'));
        $this->assertSame('USD 88.782,13', Numbers::price('$88.782,13', false, 2));
    }

    /** @test */
    public function price_mantiene_el_formato_de_siempre_para_numeros()
    {
        $this->assertSame('1.000', Numbers::price(1000));
        $this->assertSame('1.000', Numbers::price('1000.00'));
        $this->assertSame('1.000,50', Numbers::price('1000.5'));
        $this->assertSame('$1.000,50', Numbers::price(1000.5, true));
        $this->assertSame('USD 12,00', Numbers::price(12, false, 2));
        $this->assertSame('$12', Numbers::price(12, false, 1));
        $this->assertSame('inf', Numbers::price(INF));
        $this->assertSame('1', Numbers::price(true));
    }

    /** @test */
    public function price_con_lo_que_no_es_un_numero_muestra_cero_en_vez_de_tumbar_la_pantalla()
    {
        $this->assertSame('0', Numbers::price(null));
        $this->assertSame('0', Numbers::price(''));
        $this->assertSame('0', Numbers::price('abc'));
        $this->assertSame('0', Numbers::price([]));
        $this->assertSame('$0', Numbers::price(null, true));
    }

    /** @test */
    public function las_otras_dos_copias_de_price_dan_lo_mismo_y_tampoco_tiran_typeerror()
    {
        $this->assertSame('$88.782,50', ArticleHelper::price(88782.5));
        $this->assertSame('$88.782,13', ArticleHelper::price('$88.782,13'));
        $this->assertSame('$1.000', ArticleHelper::price('1000.00'));
        $this->assertSame('1.000', NumbersComun::price(1000));
        $this->assertSame('1.000,50', NumbersComun::price('1000.5'));
        $this->assertSame('88.782,13', NumbersComun::price('$88.782,13'));
    }
}
