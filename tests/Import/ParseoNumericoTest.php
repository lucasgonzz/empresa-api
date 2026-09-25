<?php

namespace Tests\Import;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use PHPUnit\Framework\TestCase;

/**
 * Parseo puro de ImportHelper::parseNumericValue (sin base, sin Laravel levantado).
 *
 * Fija la regla de separadores de la importación de Excel:
 *
 *   - Coma sola = decimal, SIEMPRE ("1,234" = 1.234): regla explícita de Lucas.
 *   - Varias comas solas en grupos de 3 = miles ("1,234,567").
 *   - Coma y punto juntos: el de más a la derecha es el decimal y el otro solo puede ser un
 *     agrupador de miles bien formado; si no, es un error (antes "1.5,3" daba 15.3 en silencio).
 *   - Espacio, NBSP (U+00A0), espacio fino (U+202F) y apóstrofo como miles, con patrón estricto.
 *   - Solo punto: depende de $interpretacion_punto (auto / siempre_miles / siempre_decimal).
 *
 * La primera tabla son los valores que YA daban un número correcto antes de tocar el parseo:
 * tienen que seguir dando EXACTAMENTE el mismo, en los tres modos.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class ParseoNumericoTest extends TestCase
{
    const NBSP = "\xC2\xA0";
    const NNBSP = "\xE2\x80\xAF";

    /**
     * Casos que ya andaban: [entrada, esperado]. Da igual el modo de interpretación del punto
     * salvo los que traen SOLO punto, que van en otra tabla.
     */
    public function casos_que_ya_andaban()
    {
        return [
            'entero nativo'                  => [5, 5],
            'float nativo'                   => [12.5, 12.5],
            'cero'                           => ['0', 0.0],
            'entero en texto'                => ['12', 12.0],
            'negativo'                       => ['-12', -12.0],
            'coma decimal'                   => ['12,5', 12.5],
            'coma decimal chica'             => ['0,5', 0.5],
            'coma decimal sin entero'        => [',5', 0.5],
            'coma decimal negativa'          => ['-12,5', -12.5],
            'una sola coma con 3 dígitos'    => ['1,234', 1.234],
            'una coma con 4 decimales'       => ['1,2345', 1.2345],
            'miles con punto, decimal coma'  => ['1.234,56', 1234.56],
            'varios miles con punto'         => ['1.234.567,89', 1234567.89],
            'miles con coma, decimal punto'  => ['1,234.56', 1234.56],
            'varios miles con coma'          => ['1,234,567.89', 1234567.89],
            'moneda $ con coma'              => ['$ 37468,24', 37468.24],
            'moneda $ pegado'                => ['$37468,24', 37468.24],
            'moneda USD'                     => ['USD 12', 12.0],
            'moneda USD con miles'           => ['USD 1.234,50', 1234.5],
            'moneda U$S con coma y punto'    => ['U$S 1,234.50', 1234.5],
            'notación científica con coma'   => ['1,5e3', 1500.0],
            'con espacios alrededor'         => ['  12,5  ', 12.5],
            'negativo con miles'             => ['-1.234,56', -1234.56],
            'negativo con miles en coma'     => ['-1,234.56', -1234.56],
        ];
    }

    /**
     * @dataProvider casos_que_ya_andaban
     */
    public function test_los_casos_que_ya_andaban_dan_el_mismo_numero_en_los_tres_modos($entrada, $esperado)
    {
        foreach (['auto', 'siempre_miles', 'siempre_decimal'] as $modo) {
            $this->assertSame($esperado, ImportHelper::parseNumericValue($entrada, null, null, $modo), "modo {$modo}");
        }
    }

    public function test_vacios_devuelven_null()
    {
        $this->assertNull(ImportHelper::parseNumericValue(null));
        $this->assertNull(ImportHelper::parseNumericValue(''));
        $this->assertNull(ImportHelper::parseNumericValue('   '));
    }

    /**
     * Solo punto: es el único caso ambiguo, y lo resuelve el modo. [entrada, auto, miles, decimal]
     */
    public function casos_de_solo_punto()
    {
        return [
            'grupo de 3'            => ['1.234', 1234.0, 1234.0, 1.234],
            'varios grupos de 3'    => ['1.234.567', 1234567.0, 1234567.0, null],
            'decimal de 2'          => ['3330.95', 3330.95, 333095.0, 3330.95],
            'decimal chico'         => ['2.5', 2.5, 25.0, 2.5],
            'punto sin entero'      => ['.5', 0.5, 5.0, 0.5],
            'mil con punto'         => ['2.500', 2500.0, 2500.0, 2.5],
            'negativo con miles'    => ['-1.234', -1234.0, -1234.0, -1.234],
            'moneda con miles'      => ['$ 2.500', 2500.0, 2500.0, 2.5],
        ];
    }

    /**
     * @dataProvider casos_de_solo_punto
     */
    public function test_solo_punto_depende_del_modo($entrada, $auto, $miles, $decimal)
    {
        $this->assertSame($auto, ImportHelper::parseNumericValue($entrada, null, null, 'auto'));
        $this->assertSame($auto, ImportHelper::parseNumericValue($entrada));
        $this->assertSame($miles, ImportHelper::parseNumericValue($entrada, null, null, 'siempre_miles'));

        if (is_null($decimal)) {
            /* "1.234.567" con punto siempre decimal tiene dos puntos: no es un número. */
            $this->expectException(\InvalidArgumentException::class);
        }
        $this->assertSame($decimal, ImportHelper::parseNumericValue($entrada, null, null, 'siempre_decimal'));
    }

    /**
     * Arreglo 1 del plan: varias comas solas en grupos de 3 dígitos son miles.
     */
    public function casos_de_varias_comas()
    {
        return [
            'dos grupos'          => ['1,234,567', 1234567.0],
            'tres grupos'         => ['12,345,678', 12345678.0],
            'un millón'           => ['1,000,000', 1000000.0],
            'con moneda'          => ['$ 1,234,567', 1234567.0],
            'negativo'            => ['-1,234,567', -1234567.0],
        ];
    }

    /**
     * @dataProvider casos_de_varias_comas
     */
    public function test_varias_comas_en_grupos_de_tres_son_miles($entrada, $esperado)
    {
        foreach (['auto', 'siempre_miles', 'siempre_decimal'] as $modo) {
            $this->assertSame($esperado, ImportHelper::parseNumericValue($entrada, null, null, $modo), "modo {$modo}");
        }
    }

    /**
     * Arreglo 2 del plan: espacio, NBSP, espacio fino y apóstrofo como miles.
     */
    public function casos_de_miles_con_espacio()
    {
        return [
            'espacio con decimal coma'   => ['1 234,50', 1234.5],
            'espacio sin decimal'        => ['1 234', 1234.0],
            'espacio con decimal punto'  => ['1 234.50', 1234.5],
            'NBSP con decimal coma'      => ['1' . self::NBSP . '234,50', 1234.5],
            'espacio fino sin decimal'   => ['1' . self::NNBSP . '234', 1234.0],
            'apóstrofo con decimal'      => ["1'234,50", 1234.5],
            'apóstrofo sin decimal'      => ["1'234", 1234.0],
            'dos grupos'                 => ['1 234 567,89', 1234567.89],
            'negativo'                   => ['-1 234,50', -1234.5],
            'con moneda'                 => ['$ 1 234,50', 1234.5],
        ];
    }

    /**
     * @dataProvider casos_de_miles_con_espacio
     */
    public function test_espacio_nbsp_y_apostrofo_son_miles($entrada, $esperado)
    {
        foreach (['auto', 'siempre_miles', 'siempre_decimal'] as $modo) {
            $this->assertSame($esperado, ImportHelper::parseNumericValue($entrada, null, null, $modo), "modo {$modo}");
        }
    }

    /**
     * Lo que NO es un número: tiene que tirar InvalidArgumentException, en los tres modos.
     */
    public function casos_invalidos()
    {
        return [
            'punto y coma mal agrupados'          => ['1.5,3'],
            'coma y punto mal agrupados'          => ['1,5.3'],
            'miles con punto de 2 dígitos'        => ['1.23,4'],
            'coma decimal con punto en decimales' => ['1.234,56.7'],
            'coma y punto, el de miles al revés'  => ['1234,56.7'],
            'texto'                               => ['abc'],
            'número con letras'                   => ['12abc'],
            'solo moneda'                         => ['$'],
            'dos comas seguidas'                  => ['1,,2'],
            'comas sin grupos de 3'               => ['1,2,3'],
            'espacio con grupo de 2 dígitos'      => ['1 23'],
            'espacio con grupo de 4 dígitos'      => ['1 2345'],
            'espacio y punto de miles juntos'     => ['1 234.567,8'],
            'hexadecimal'                         => ['0x1A'],
        ];
    }

    /**
     * @dataProvider casos_invalidos
     */
    public function test_los_formatos_invalidos_tiran_excepcion($entrada)
    {
        foreach (['auto', 'siempre_miles', 'siempre_decimal'] as $modo) {
            try {
                ImportHelper::parseNumericValue($entrada, 'costo', 7, $modo);
                $this->fail("'{$entrada}' debería ser inválido en modo {$modo}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString("Fila 7:", $e->getMessage());
                $this->assertStringContainsString("'{$entrada}'", $e->getMessage());
                $this->assertStringContainsString('para costo', $e->getMessage());
            }
        }
    }
}
