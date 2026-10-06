<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\CatalogoPorListaHelper;
use PHPUnit\Framework\TestCase;

/**
 * El Sí/No de una celda de la columna "visible en la tienda para la lista X" de la importación de
 * Excel (misión catalogo-por-lista-tienda, B1 de la revisión independiente, 6/10/2026):
 * CatalogoPorListaHelper::interpretar_si_no(). Parseo puro, sin base y sin Laravel levantado.
 *
 * La regla, que es la que impide que un typo DESHABILITE artículos de la tienda:
 *
 *   - Un sí reconocible (si, sí, s, 1, yes, y, true, verdadero) → 1.
 *   - Un no reconocible (no, n, 0, false, falso) → 0.
 *   - TODO LO DEMÁS → null = no informado, y la importación no escribe nada: la celda vacía, un
 *     typo ("Sii"), una "x", cualquier número que no sea 0 ni 1, el texto de otra columna.
 *
 * Sin importar mayúsculas ("SÍ" con la tilde en mayúscula también es un sí) y con los espacios de
 * alrededor recortados, incluido el espacio de no separación (NBSP) que trae lo copiado de una web.
 *
 * El efecto de punta a punta (una columna mal mapeada no deshabilita a nadie) lo fija
 * VisibleEnTiendaPorListaTest::test_un_texto_que_no_es_si_ni_no_no_deshabilita_a_nadie.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class SiONoDeLaCeldaTest extends TestCase
{
    /** El espacio de no separación (U+00A0), que trim() no recorta. */
    const NBSP = "\xC2\xA0";

    /**
     * Celdas que son un "Sí": [entrada].
     *
     * @return array
     */
    public function celdas_que_valen_si()
    {
        return [
            'Si'                     => ['Si'],
            'si'                     => ['si'],
            'SI'                     => ['SI'],
            'Sí con tilde'           => ['Sí'],
            'sí con tilde'           => ['sí'],
            'SÍ con la tilde en mayúscula' => ['SÍ'],
            'S'                      => ['S'],
            's'                      => ['s'],
            '1 en texto'             => ['1'],
            'yes'                    => ['yes'],
            'YES'                    => ['YES'],
            'Yes'                    => ['Yes'],
            'y'                      => ['y'],
            'Y'                      => ['Y'],
            'true'                   => ['true'],
            'TRUE'                   => ['TRUE'],
            'True'                   => ['True'],
            'verdadero'              => ['verdadero'],
            'Verdadero'              => ['Verdadero'],
            'con espacios alrededor' => ['  Sí  '],
            'con NBSP alrededor'     => [self::NBSP . 'Sí' . self::NBSP],
            'con tab y salto de línea' => ["\t1\n"],
            'entero 1'               => [1],
            'float 1.0'              => [1.0],
            'booleano true'          => [true],
        ];
    }

    /**
     * @dataProvider celdas_que_valen_si
     */
    public function test_estas_celdas_valen_si($entrada)
    {
        $this->assertSame(1, CatalogoPorListaHelper::interpretar_si_no($entrada));
    }

    /**
     * Celdas que son un "No": [entrada].
     *
     * @return array
     */
    public function celdas_que_valen_no()
    {
        return [
            'No'                     => ['No'],
            'NO'                     => ['NO'],
            'no'                     => ['no'],
            'N'                      => ['N'],
            'n'                      => ['n'],
            '0 en texto'             => ['0'],
            'false'                  => ['false'],
            'FALSE'                  => ['FALSE'],
            'False'                  => ['False'],
            'falso'                  => ['falso'],
            'Falso'                  => ['Falso'],
            'con espacios alrededor' => [' no '],
            'con NBSP alrededor'     => [self::NBSP . 'No' . self::NBSP],
            'entero 0'               => [0],
            'float 0.0'              => [0.0],
            'booleano false'         => [false],
        ];
    }

    /**
     * @dataProvider celdas_que_valen_no
     */
    public function test_estas_celdas_valen_no($entrada)
    {
        $this->assertSame(0, CatalogoPorListaHelper::interpretar_si_no($entrada));
    }

    /**
     * 🔴 Celdas que NO son ni un sí ni un no: "no informado" (null), y la importación no escribe
     * nada. Antes valían 0 y deshabilitaban artículos. [entrada].
     *
     * @return array
     */
    public function celdas_que_no_se_pueden_leer_como_si_ni_no()
    {
        return [
            'typo: Sii'                  => ['Sii'],
            'typo: sii'                  => ['sii'],
            'una x'                      => ['x'],
            'una X'                      => ['X'],
            'ok'                         => ['ok'],
            'OK'                         => ['OK'],
            'un número que no es 0 ni 1' => ['2'],
            'un número negativo'         => ['-1'],
            'un decimal'                 => ['1.5'],
            'sí con un signo'            => ['si!'],
            'sí con un punto'            => ['Sí.'],
            'nope'                       => ['nope'],
            'a veces'                    => ['a veces'],
            'si no'                      => ['si no'],
            'quizás'                     => ['quizás'],
            'verdad'                     => ['verdad'],
            'la f de falso'              => ['f'],
            'la t de true'               => ['t'],
            'oui'                        => ['oui'],
            'el texto de otra columna'   => ['Art unico prov B'],
            'celda vacía'                => [''],
            'solo espacios'              => ['   '],
            'solo un NBSP'               => [self::NBSP],
            'la columna sin mapear'      => [null],
            'un arreglo'                 => [[]],
            'texto que no es UTF-8'      => ["\xFF\xFEsi"],
        ];
    }

    /**
     * @dataProvider celdas_que_no_se_pueden_leer_como_si_ni_no
     */
    public function test_estas_celdas_son_no_informado($entrada)
    {
        $this->assertNull(CatalogoPorListaHelper::interpretar_si_no($entrada));
    }
}
