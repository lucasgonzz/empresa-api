<?php

namespace Tests\Import\RelacionesParecidas;

use App\Http\Controllers\Helpers\import\relaciones\RelacionesParecidasChequeo;
use App\Http\Controllers\Helpers\import\relaciones\RelacionesParecidasDetector;

/**
 * `padre_ref` y `recortado` en `relaciones_parecidas` (misión importacion-relaciones-parecidas,
 * tanda 2, extensión aditiva del contrato).
 *
 * Los ítems hijos (subcategoría, localidad con provincia) salen UNA VEZ POR CADA RESOLUCIÓN POSIBLE
 * del padre, cada uno con su `padre_ref`: "id:X" por cada existente que el ítem padre ofrece
 * (`usar`), "nuevo:<padre>" para `crear` y "nuevo:<C>" para `unir` con C. El SPA muestra solo los
 * de la opción que el usuario eligió en el padre: si el usuario corrige un padre dudoso a "usar el
 * existente", los hijos de esa resolución ya están revisados. La clave es `tipo|padre|valor|ref`.
 *
 * Fixture 38 (ver el generador): "Pintureria Sur" (3 filas) con "Látex", "Latex" y "Esmalte";
 * "PINTURERIA SUR" (2 filas) con "Esmaltes" y "ESMALTE".
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class PadreRefTest extends RelacionesParecidasTestCase
{
    /**
     * @return array relaciones_parecidas
     */
    protected function chequear_padres()
    {
        $relativa = $this->copiar_a_storage(self::PADRES);

        return RelacionesParecidasChequeo::para_articulos($this->tenant->id, storage_path('app/' . $relativa), [
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
    }

    /**
     * Padre DUDOSO (`posible`, "crear" sugerida, con la opción de usar "Pinturería del Sur"): sus
     * hijos existen bajo "nuevo:Pintureria Sur" (comparados entre sí) Y bajo "id:X" (comparados
     * con las subcategorías de X).
     */
    public function test_padre_dudoso_tiene_hijos_bajo_crear_y_bajo_usar()
    {
        $existente = $this->crear_categoria('Pinturería del Sur');
        $latex     = $this->crear_sub('Látex', $existente);

        $this->falsear_ia(function (array $consulta) {
            /* En el prompt el tipo va con su nombre para la IA (RelacionesParecidasIa::NOMBRES_DE_TIPO). */
            if ($consulta['tipo'] === 'categoría de artículos') {
                return ['elegido' => $consulta['candidatos'][0]['c'], 'seguro' => false];
            }

            return ['elegido' => null];
        });

        $relaciones = $this->chequear_padres();

        $padre = $this->item($relaciones, 'categoria|Pintureria Sur');
        $this->assertSame('posible', $padre['motivo']);
        $this->assertSame(['accion' => 'crear'], $padre['opciones'][$padre['sugerida']]);

        $bajo_usar  = $this->item($relaciones, 'sub_categoria|Pintureria Sur|Latex|id:' . $existente->id);
        $bajo_crear = $this->item($relaciones, 'sub_categoria|Pintureria Sur|Latex|nuevo:Pintureria Sur');

        $this->assertNotNull($bajo_usar, 'Hijo bajo "usar el existente". Claves: ' . json_encode($this->claves($relaciones)));
        $this->assertSame('id:' . $existente->id, $bajo_usar['padre_ref']);
        $this->assertSame((int) $existente->id, $bajo_usar['padre_id']);
        $this->assertSame('misma_escritura', $bajo_usar['motivo']);
        $this->assertSame((int) $latex->id, $bajo_usar['opciones'][0]['id'], 'Comparado contra la subcategoría de X.');

        $this->assertNotNull($bajo_crear, 'Hijo bajo "crear uno nuevo".');
        $this->assertSame('nuevo:Pintureria Sur', $bajo_crear['padre_ref']);
        $this->assertNull($bajo_crear['padre_id']);
        $this->assertSame('entre_nuevos', $bajo_crear['motivo']);
        $this->assertSame([['accion' => 'unir', 'con' => 'Látex'], ['accion' => 'crear']], $bajo_crear['opciones']);

        $this->assertFalse($relaciones['recortado']);
    }

    /**
     * Padre `entre_nuevos` ("PINTURERIA SUR", unir con "Pintureria Sur" o crear aparte): sus hijos
     * existen bajo "nuevo:Pintureria Sur" (juntos con los de la canónica) y bajo
     * "nuevo:PINTURERIA SUR" (solos). Y la consulta idéntica de los dos grupos ("ESMALTE" contra
     * "Esmaltes") va UNA sola vez a la IA.
     */
    public function test_padre_entre_nuevos_tiene_hijos_bajo_el_canonico_y_bajo_la_variante()
    {
        $this->falsear_ia($this->elegir_por_nombre([
            'ESMALTE' => 'Esmaltes',
            'Esmalte' => 'Esmaltes',
        ]));

        $relaciones = $this->chequear_padres();

        $padre = $this->item($relaciones, 'categoria|PINTURERIA SUR');
        $this->assertSame('entre_nuevos', $padre['motivo']);
        $this->assertNull($this->item($relaciones, 'categoria|Pintureria Sur'), 'La canónica no tiene ítem: una sola resolución.');

        $con_canonico = $this->item($relaciones, 'sub_categoria|PINTURERIA SUR|ESMALTE|nuevo:Pintureria Sur');
        $sola         = $this->item($relaciones, 'sub_categoria|PINTURERIA SUR|ESMALTE|nuevo:PINTURERIA SUR');

        $this->assertNotNull($con_canonico, json_encode($this->claves($relaciones)));
        $this->assertNotNull($sola, json_encode($this->claves($relaciones)));
        $this->assertSame('nuevo:Pintureria Sur', $con_canonico['padre_ref']);
        $this->assertSame('nuevo:PINTURERIA SUR', $sola['padre_ref']);
        $this->assertSame([['accion' => 'unir', 'con' => 'Esmaltes'], ['accion' => 'crear']], $sola['opciones']);

        /* Los de la canónica, sin ítem propio, salen con su única resolución. */
        $latex = $this->item($relaciones, 'sub_categoria|Pintureria Sur|Latex|nuevo:Pintureria Sur');
        $this->assertNotNull($latex);
        $this->assertSame([['accion' => 'unir', 'con' => 'Látex'], ['accion' => 'crear']], $latex['opciones']);

        $valores = [];

        foreach ($this->llamadas_relaciones[0] as $consulta) {
            $valores[] = $consulta['valor'];
        }

        sort($valores);

        $this->assertSame(['ESMALTE', 'Esmalte'], $valores, 'Consultas idénticas de dos grupos: una sola vez.');
    }

    /**
     * `recortado`: false en una corrida normal, true cuando alguna capa se acotó (acá, la IA con
     * más consultas que su tope).
     */
    public function test_recortado_cuando_hay_mas_consultas_que_el_tope_de_la_ia()
    {
        $this->falsear_ia(function () {
            return null;
        });

        $grupos = [];
        $valores = [];

        for ($i = 0; $i < RelacionesParecidasChequeo::TOPE_ITEMS_IA + 5; $i++) {
            $valores['Martillo ' . $i . 'x'] = 1;
            $valores['Martillo ' . $i . 'xx'] = 2;
        }

        $grupos[] = [
            'tipo'     => 'marca',
            'es_hijo'  => false,
            'analisis' => RelacionesParecidasDetector::analizar_grupo($valores, []),
        ];

        $metodo = new \ReflectionMethod(RelacionesParecidasChequeo::class, 'armar_consultas');
        $metodo->setAccessible(true);

        $consultas = $metodo->invoke(null, $grupos);

        $this->assertTrue($consultas['recortado']);
        $this->assertCount(RelacionesParecidasChequeo::TOPE_ITEMS_IA, $consultas['consultas']);

        /* Y una corrida normal no se marca. */
        $this->assertFalse($this->chequear_padres()['recortado']);
    }
}
