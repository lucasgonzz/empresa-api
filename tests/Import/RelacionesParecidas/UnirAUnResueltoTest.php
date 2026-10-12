<?php

namespace Tests\Import\RelacionesParecidas;

use App\Http\Controllers\Helpers\import\relaciones\RelacionesParecidasChequeo;

/**
 * Hijos de un padre A que la IA une SEGURA a otro valor del archivo B, cuando B apunta a un
 * existente X (misión importacion-relaciones-parecidas, tanda 4).
 *
 * La sugerida de A pasa a ser "usar X" (la de B), con "unir con B" disponible: así los hijos de A
 * se revisan contra las subcategorías de X (`padre_ref` "id:X"). Antes, con "unir con B" marcado,
 * se revisaban como de un padre nuevo y el worker igual los metía en X sin compararlos con nada.
 *
 * Fixture 40: "Pintureria Sur" (B, 2 filas) y "Pinturerias Sur" (A, 1 fila, sub "Latex"); cargada,
 * la categoría X "Pinturería del Sur" con la subcategoría "Látex".
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class UnirAUnResueltoTest extends RelacionesParecidasTestCase
{
    public function test_los_hijos_de_un_padre_unido_a_un_resuelto_se_revisan_contra_el_existente()
    {
        $existente = $this->crear_categoria('Pinturería del Sur');
        $latex = $this->crear_sub('Látex', $existente);

        $this->falsear_ia(function (array $consulta) {
            foreach ($consulta['candidatos'] as $candidato) {
                if ($consulta['valor'] === 'Pintureria Sur' && $candidato['nombre'] === 'Pinturería del Sur') {
                    return ['elegido' => $candidato['c'], 'seguro' => true];
                }

                if ($consulta['valor'] === 'Pinturerias Sur' && $candidato['nombre'] === 'Pintureria Sur' && $candidato['origen'] === 'archivo') {
                    return ['elegido' => $candidato['c'], 'seguro' => true];
                }
            }

            return ['elegido' => null];
        });

        $relativa = $this->copiar_a_storage(self::UNIR_A_RESUELTO);

        $relaciones = RelacionesParecidasChequeo::para_articulos($this->tenant->id, storage_path('app/' . $relativa), [
            'provider_id'    => null,
            'column_mapping' => $this->column_mapping([
                'codigo_de_barras'    => 0,
                'codigo_de_proveedor' => 2,
                'nombre'              => 3,
                'categoria'           => 9,
                'sub_categoria'       => 10,
            ]),
            'hoja'           => 0,
            'header_row'     => null,
        ]);

        $b = $this->item($relaciones, 'categoria|Pintureria Sur');
        $this->assertSame('parecido', $b['motivo']);
        $this->assertSame(['accion' => 'usar', 'id' => (int) $existente->id, 'nombre' => 'Pinturería del Sur'], $b['opciones'][$b['sugerida']]);

        $a = $this->item($relaciones, 'categoria|Pinturerias Sur');
        $this->assertNotNull($a, json_encode($this->claves($relaciones)));
        $this->assertSame(['accion' => 'usar', 'id' => (int) $existente->id, 'nombre' => 'Pinturería del Sur'], $a['opciones'][$a['sugerida']], 'Sugerida: la de B.');
        $this->assertContains(['accion' => 'unir', 'con' => 'Pintureria Sur'], $a['opciones'], '"Unir con B" sigue disponible.');

        $hijo = $this->item($relaciones, 'sub_categoria|Pinturerias Sur|Latex|id:' . $existente->id);
        $this->assertNotNull($hijo, 'El hijo de A se revisa contra las subcategorías de X. Claves: ' . json_encode($this->claves($relaciones)));
        $this->assertSame('misma_escritura', $hijo['motivo']);
        $this->assertSame(['accion' => 'usar', 'id' => (int) $latex->id, 'nombre' => 'Látex'], $hijo['opciones'][$hijo['sugerida']]);
    }
}
