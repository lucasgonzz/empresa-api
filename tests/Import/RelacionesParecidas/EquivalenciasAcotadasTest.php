<?php

namespace Tests\Import\RelacionesParecidas;

use App\Http\Controllers\Helpers\import\relaciones\EquivalenciasResolver;
use Tests\TestCase;

/**
 * El tope y la normalización de las `equivalencias` del POST (misión
 * importacion-relaciones-parecidas, tanda 2): InitExcelImport y el controller las acotan y sanean
 * UNA vez antes de armar la cadena de lotes, así lo que viaja serializado en cada lote es chico y
 * válido.
 *
 * Sin base: acotar() no consulta nada, y el saneado de tipos/acciones/valores tampoco (solo los
 * `usar` van a la base, y acá no hay).
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class EquivalenciasAcotadasTest extends TestCase
{
    public function test_mas_del_tope_se_cortan()
    {
        $muchas = [];

        for ($i = 0; $i < EquivalenciasResolver::TOPE_DECISIONES + 5; $i++) {
            $muchas[] = ['tipo' => 'marca', 'valor' => 'Marca ' . $i, 'accion' => 'crear'];
        }

        $acotadas = EquivalenciasResolver::acotar($muchas);

        $this->assertCount(EquivalenciasResolver::TOPE_DECISIONES, $acotadas);
        $this->assertSame('Marca 0', $acotadas[0]['valor'], 'Se quedan las primeras.');
    }

    public function test_acotar_normaliza_json_y_descarta_lo_que_no_es_una_decision()
    {
        $this->assertSame([], EquivalenciasResolver::acotar(null));
        $this->assertSame([], EquivalenciasResolver::acotar('no es json'));
        $this->assertSame(
            [['tipo' => 'marca', 'valor' => 'Bosh', 'accion' => 'crear']],
            EquivalenciasResolver::acotar('[{"tipo":"marca","valor":"Bosh","accion":"crear"}, "basura", 3]')
        );
    }

    public function test_el_saneado_deja_solo_lo_valido_para_la_importacion()
    {
        $saneadas = EquivalenciasResolver::sanear(900, [
            ['tipo' => 'marca',     'valor' => ' Bosh ', 'accion' => 'crear'],
            ['tipo' => 'provincia', 'valor' => 'Santa Fe', 'accion' => 'crear'],      // no es de artículos
            ['tipo' => 'categoria', 'valor' => '', 'accion' => 'crear'],              // valor vacío
            ['tipo' => 'categoria', 'valor' => 'X', 'accion' => 'borrar'],            // acción desconocida
            ['tipo' => 'categoria', 'valor' => 'Pintureria', 'accion' => 'unir', 'con' => 'Pintureria'],
            ['tipo' => 'categoria', 'valor' => 'Pintureria', 'accion' => 'unir', 'con' => 'Pinturería'],
        ], EquivalenciasResolver::TIPOS_ARTICULOS);

        $this->assertCount(2, $saneadas);
        $this->assertSame('Bosh', $saneadas[0]['valor']);
        $this->assertSame('Pinturería', $saneadas[1]['con']);
    }
}
