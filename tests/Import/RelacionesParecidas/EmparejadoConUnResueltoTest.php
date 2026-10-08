<?php

namespace Tests\Import\RelacionesParecidas;

use App\Http\Controllers\Helpers\import\relaciones\RelacionesParecidasChequeo;
use App\Http\Controllers\Helpers\import\relaciones\RelacionesParecidasDetector;
use App\Models\PriceType;

/**
 * Ningún valor que la IA emparejó puede quedar sin ítem (misión importacion-relaciones-parecidas,
 * tanda 3).
 *
 * El caso medido con la IA real (clientes, 8/10/2026): listas "Distribuidor", "Mayorista",
 * "Minorista" y "Tienda Nube" cargadas; el Excel trae "Mayorista", "Mayoristas", "Mayorista 2" y
 * "Minorista". La IA contestó "Mayorista 2" → "Mayorista" (cargado) DUDOSO y "Mayoristas" →
 * "Mayorista 2" (del archivo) SEGURO. "Mayorista 2" salía `posible`, pero "Mayoristas" no salía en
 * ningún ítem: su par era un valor que ya tenía ítem contra un existente, no un valor nuevo, y el
 * agrupado entre nuevos lo descartaba. La importación creaba una lista "Mayoristas" sin preguntar.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class EmparejadoConUnResueltoTest extends RelacionesParecidasTestCase
{
    /**
     * @param  string $nombre
     * @return \App\Models\PriceType
     */
    protected function lista($nombre)
    {
        return PriceType::create(['name' => $nombre, 'user_id' => $this->tenant->id]);
    }

    /**
     * La respuesta exacta de la IA real, por valor (los `n` dependen de las otras columnas).
     *
     * @return callable
     */
    protected function respuesta_medida()
    {
        return function (array $consulta) {
            $buscado = null;
            $seguro = false;

            if ($consulta['valor'] === 'Mayorista 2') {
                $buscado = ['Mayorista', 'cargado'];
                $seguro = false;
            } elseif ($consulta['valor'] === 'Mayoristas') {
                $buscado = ['Mayorista 2', 'archivo'];
                $seguro = true;
            }

            if (is_null($buscado)) {
                return ['elegido' => null];
            }

            foreach ($consulta['candidatos'] as $candidato) {
                if ($candidato['nombre'] === $buscado[0] && $candidato['origen'] === $buscado[1]) {
                    return ['elegido' => $candidato['c'], 'seguro' => $seguro];
                }
            }

            return ['elegido' => null];
        };
    }

    public function test_un_valor_emparejado_con_otro_ya_resuelto_tiene_su_item()
    {
        $this->lista('Distribuidor');
        $mayorista = $this->lista('Mayorista');
        $minorista = $this->lista('Minorista');
        $this->lista('Tienda Nube');

        $this->falsear_ia($this->respuesta_medida());

        $relativa = $this->copiar_a_storage(self::LISTAS);

        $relaciones = RelacionesParecidasChequeo::para_clientes($this->tenant->id, storage_path('app/' . $relativa), [
            'column_mapping' => $this->column_mapping(['nombre' => 0, 'tipo_de_precio' => 1]),
            'hoja'           => 0,
            'header_row'     => null,
        ]);

        /* Las consultas que vio la IA son las de la medición. */
        $vistas = [];

        foreach ($this->llamadas_relaciones[0] as $consulta) {
            $nombres = [];

            foreach ($consulta['candidatos'] as $candidato) {
                $nombres[] = $candidato['nombre'] . ' (' . $candidato['origen'] . ')';
            }

            $vistas[$consulta['valor']] = $nombres;
        }

        $this->assertSame([
            'Mayorista 2' => ['Mayorista (cargado)', 'Minorista (cargado)'],
            'Mayoristas'  => ['Mayorista (cargado)', 'Minorista (cargado)', 'Mayorista 2 (archivo)'],
        ], $vistas);

        $this->assertSame(['tipo_de_precio|Mayorista 2', 'tipo_de_precio|Mayoristas'], $this->claves($relaciones));

        $dudoso = $this->item($relaciones, 'tipo_de_precio|Mayorista 2');
        $this->assertSame('posible', $dudoso['motivo']);

        $mayoristas = $this->item($relaciones, 'tipo_de_precio|Mayoristas');
        $this->assertNotNull($mayoristas, '"Mayoristas" tiene que salir en la lista: si no, se crea una lista duplicada sin preguntar.');
        $this->assertSame('parecido', $mayoristas['motivo']);
        $this->assertSame([
            ['accion' => 'unir', 'con' => 'Mayorista 2'],
            ['accion' => 'usar', 'id' => (int) $mayorista->id, 'nombre' => 'Mayorista'],
            ['accion' => 'usar', 'id' => (int) $minorista->id, 'nombre' => 'Minorista'],
            ['accion' => 'crear'],
        ], $mayoristas['opciones']);
        $this->assertSame(0, $mayoristas['sugerida'], 'Seguro: hacia donde eligió la IA (unir con "Mayorista 2").');
    }

    /**
     * El mismo par, DUDOSO, y con el destino resuelto con seguridad contra un existente: el ítem
     * sale igual, con "crear" marcado y ofreciendo usar el existente del destino.
     */
    public function test_dudoso_hacia_un_valor_resuelto_sale_con_crear_marcado()
    {
        $grupo = RelacionesParecidasDetector::analizar_grupo(
            ['Mayoristas' => 1, 'Mayorista 2' => 1],
            [['id' => 7, 'nombre' => 'Mayorista']]
        );

        $cerrado = RelacionesParecidasDetector::cerrar_grupo('tipo_de_precio', $grupo, [
            'Mayorista 2' => ['existente' => 7, 'seguro' => true],
            'Mayoristas'  => ['archivo' => 'Mayorista 2', 'seguro' => false],
        ], ['Mayorista 2' => true, 'Mayoristas' => true], true);

        $items = [];

        foreach ($cerrado['items'] as $item) {
            $items[$item['valor']] = $item;
        }

        $this->assertSame('parecido', $items['Mayorista 2']['motivo']);

        $this->assertArrayHasKey('Mayoristas', $items);
        $this->assertSame('posible', $items['Mayoristas']['motivo']);
        $this->assertSame([
            ['accion' => 'unir', 'con' => 'Mayorista 2'],
            ['accion' => 'usar', 'id' => 7, 'nombre' => 'Mayorista'],
            ['accion' => 'crear'],
        ], $items['Mayoristas']['opciones']);
        $this->assertSame(2, $items['Mayoristas']['sugerida']);
        $this->assertSame('Podría ser «Mayorista 2» escrito de otra forma; si es el mismo, elegilo', $items['Mayoristas']['explicacion']);
    }
}
