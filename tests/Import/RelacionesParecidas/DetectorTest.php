<?php

namespace Tests\Import\RelacionesParecidas;

use App\Http\Controllers\Helpers\import\relaciones\EquivalenciasResolver;
use App\Http\Controllers\Helpers\import\relaciones\RelacionesParecidasDetector;
use Tests\TestCase;

/**
 * RelacionesParecidasDetector, la capa determinista del chequeo de nombres parecidos de la
 * importación (misión importacion-relaciones-parecidas, 8/10/2026).
 *
 * Es pura (sin base ni IA): extiende Tests\TestCase directo, igual que RecomendacionIaTest.
 *
 * Lo que fija, en el orden del plan:
 *   - "DEL NORTE" / "Del norte" / "DELNORTE" / "Del Nórte" son la misma escritura (sin IA);
 *   - "Ferret. del Norte" es candidato de "Ferretería Del Norte"; "Del Norte" no lo es de "Del Sur";
 *   - "Caño 1/2" ES candidato de "Caño 3/4": lo que los separa es un dato y eso lo descarta la IA,
 *     no el detector (si el detector lo descartara solo, la IA nunca vería los casos de medida);
 *   - el agrupado entre valores nuevos y su canónico (más frecuente, después el que tiene tildes);
 *   - el tope de 2000 valores por columna;
 *   - cómo se arma cada ítem con lo que contestó la IA (o sin ella).
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class DetectorTest extends TestCase
{
    /**
     * @param  string $a
     * @param  string $b
     * @return float
     */
    protected function puntaje($a, $b)
    {
        return RelacionesParecidasDetector::puntaje_entre(
            RelacionesParecidasDetector::rasgos($a),
            RelacionesParecidasDetector::rasgos($b)
        );
    }

    /**
     * @param  array  $items
     * @param  string $clave
     * @return array|null
     */
    protected function item(array $items, $clave)
    {
        foreach ($items as $item) {
            if ($item['clave'] === $clave) {
                return $item;
            }
        }

        return null;
    }

    /* ------------------------------------------------------------------
     * Claves
     * ------------------------------------------------------------------ */

    public function test_mayusculas_tildes_y_espacios_son_la_misma_escritura()
    {
        foreach (['DEL NORTE', 'Del norte', 'DELNORTE', 'Del Nórte', '  del   norte '] as $valor) {
            $this->assertSame('delnorte', RelacionesParecidasDetector::clave_fuerte($valor), $valor);
        }

        $this->assertSame(
            RelacionesParecidasDetector::clave_fuerte('cañería'),
            RelacionesParecidasDetector::clave_fuerte('CAÑERÍA')
        );
    }

    /**
     * Un valor que es solo símbolos no puede dar clave vacía: si diera '', todos los valores de
     * símbolos colisionarían entre sí.
     */
    public function test_un_valor_de_solo_simbolos_no_da_clave_vacia()
    {
        $this->assertSame('***', RelacionesParecidasDetector::clave_fuerte('***'));
        $this->assertNotSame(
            RelacionesParecidasDetector::clave_fuerte('***'),
            RelacionesParecidasDetector::clave_fuerte('---')
        );
    }

    /**
     * La clave de cache del worker baja la Ñ y las tildes en mayúscula (strtolower no lo hacía) y
     * colapsa espacios, pero NO saca tildes: "pintureria" y "pinturería" son dos claves.
     */
    public function test_la_clave_de_cache_baja_la_enie_y_no_saca_tildes()
    {
        $this->assertSame('cañería', RelacionesParecidasDetector::clave_cache('CAÑERÍA'));
        $this->assertSame('del norte', RelacionesParecidasDetector::clave_cache('  DEL   NORTE '));
        $this->assertNotSame(
            RelacionesParecidasDetector::clave_cache('Pinturería'),
            RelacionesParecidasDetector::clave_cache('Pintureria')
        );
        /* La de las equivalencias recordadas colapsa espacios pero RESPETA mayúsculas y tildes. */
        $this->assertSame('DEL NORTE', EquivalenciasResolver::clave_guardable('  DEL   NORTE '));
        $this->assertNotSame(EquivalenciasResolver::clave_guardable('Del norte'), EquivalenciasResolver::clave_guardable('DEL NORTE'));
        $this->assertNotSame(EquivalenciasResolver::clave_guardable('Pintureria'), EquivalenciasResolver::clave_guardable('Pinturería'));
    }

    /* ------------------------------------------------------------------
     * Candidatos parecidos
     * ------------------------------------------------------------------ */

    public function test_abreviatura_es_candidato()
    {
        $this->assertGreaterThan(0, $this->puntaje('Ferret. del Norte', 'Ferretería Del Norte'));
    }

    public function test_del_norte_y_del_sur_no_son_candidatos()
    {
        $this->assertSame(0.0, $this->puntaje('Del Norte', 'Del Sur'));
    }

    /**
     * 🔴 Candidato a propósito: la medida la juzga la IA. Si este test se pusiera rojo porque
     * alguien endureció los umbrales, la IA dejaría de ver justo los casos que existen para que
     * los vea.
     */
    public function test_cano_de_medidas_distintas_es_candidato_para_la_ia()
    {
        $this->assertGreaterThan(0, $this->puntaje('Caño 1/2', 'Caño 3/4'));
    }

    public function test_plural_tiene_puntaje_alto()
    {
        $this->assertGreaterThanOrEqual(RelacionesParecidasDetector::PUNTAJE_ALTO, $this->puntaje('Martillo', 'Martillos'));
    }

    public function test_textos_de_uno_o_dos_caracteres_no_son_candidatos_entre_si()
    {
        $this->assertSame(0.0, $this->puntaje('S', 'M'));
        $this->assertSame(0.0, $this->puntaje('AB', 'AC'));
    }

    /* ------------------------------------------------------------------
     * Análisis de un grupo
     * ------------------------------------------------------------------ */

    public function test_estados_contra_los_existentes()
    {
        $grupo = RelacionesParecidasDetector::analizar_grupo(
            [
                'DEL NORTE'            => 2,
                'Del norte'            => 3,
                'DELNORTE'             => 1,
                'Del Nórte'            => 1,
                'Ferret. del Norte'    => 1,
                'Herramientas'         => 4,
            ],
            [
                ['id' => 5, 'nombre' => 'DEL NORTE'],
                ['id' => 7, 'nombre' => 'Ferretería Del Norte'],
            ]
        );

        $estados = [];

        foreach ($grupo['valores'] as $valor => $info) {
            $estados[(string) $valor] = $info['estado'];
        }

        $this->assertSame([
            'DEL NORTE'         => 'identico',
            'Del norte'         => 'misma_escritura',
            'DELNORTE'          => 'misma_escritura',
            'Del Nórte'         => 'misma_escritura',
            'Ferret. del Norte' => 'consultar',
            'Herramientas'      => 'nuevo',
        ], $estados);

        $this->assertSame(7, $grupo['valores']['Ferret. del Norte']['candidatos'][0]['id']);
    }

    /**
     * Con dos existentes de la misma escritura, el más viejo va primero (es el preseleccionado),
     * y los dos se ofrecen.
     */
    public function test_misma_escritura_ofrece_todos_los_existentes_con_el_mas_viejo_primero()
    {
        $grupo = RelacionesParecidasDetector::analizar_grupo(
            ['del norte' => 1],
            [
                ['id' => 5, 'nombre' => 'DEL NORTE'],
                ['id' => 9, 'nombre' => 'Del Norte'],
            ]
        );

        $cerrado = RelacionesParecidasDetector::cerrar_grupo('categoria', $grupo, [], [], true);

        $this->assertCount(1, $cerrado['items']);
        $this->assertSame([
            ['accion' => 'usar', 'id' => 5, 'nombre' => 'DEL NORTE'],
            ['accion' => 'usar', 'id' => 9, 'nombre' => 'Del Norte'],
            ['accion' => 'crear'],
        ], $cerrado['items'][0]['opciones']);
        $this->assertSame(0, $cerrado['items'][0]['sugerida']);
        $this->assertSame(5, $cerrado['supuestos']['del norte']);
    }

    /**
     * Una equivalencia recordada va como `recordado`, sin IA; una hacia un modelo que ya no está
     * (borrado o ajeno) no cuenta; y una que apunta justo al modelo que se llama igual no se
     * pregunta.
     */
    public function test_recordadas()
    {
        $existentes = [
            ['id' => 5, 'nombre' => 'DEL NORTE'],
            ['id' => 8, 'nombre' => 'Norteño'],
        ];

        $grupo = RelacionesParecidasDetector::analizar_grupo(
            ['Del norte' => 1, 'Sur' => 1, 'DEL NORTE' => 1, '  Del   norte ' => 1],
            $existentes,
            [
                'Del norte' => 8,   // la clave respeta mayúsculas: "DEL NORTE" no la comparte
                'Sur'       => 999, // modelo que ya no existe
                'DEL NORTE' => 8,
            ]
        );

        $this->assertSame('recordado', $grupo['valores']['Del norte']['estado']);
        $this->assertSame(8, $grupo['valores']['Del norte']['id']);
        $this->assertSame('recordado', $grupo['valores']['  Del   norte ']['estado'], 'Espacios de más: misma clave.');
        $this->assertSame('nuevo', $grupo['valores']['Sur']['estado']);
        $this->assertSame('recordado', $grupo['valores']['DEL NORTE']['estado'], 'La equivalencia apunta a otro modelo: se muestra.');

        $grupo = RelacionesParecidasDetector::analizar_grupo(['DEL NORTE' => 1], $existentes, ['DEL NORTE' => 5]);
        $this->assertSame('identico', $grupo['valores']['DEL NORTE']['estado']);

        /* Una equivalencia de "Del norte" no se aplica a "DEL NORTE": eso lo vuelve a preguntar el paso 3. */
        $grupo = RelacionesParecidasDetector::analizar_grupo(['DEL NORTE' => 1], [['id' => 8, 'nombre' => 'Norteño']], ['Del norte' => 8]);
        $this->assertSame('nuevo', $grupo['valores']['DEL NORTE']['estado']);

        $cerrado = RelacionesParecidasDetector::cerrar_grupo('categoria', RelacionesParecidasDetector::analizar_grupo(
            ['Del norte' => 1],
            $existentes,
            ['Del norte' => 8]
        ), [], [], true);

        $this->assertSame('recordado', $cerrado['items'][0]['motivo']);
        $this->assertSame([['accion' => 'usar', 'id' => 8, 'nombre' => 'Norteño'], ['accion' => 'crear']], $cerrado['items'][0]['opciones']);
    }

    /* ------------------------------------------------------------------
     * Entre valores nuevos
     * ------------------------------------------------------------------ */

    public function test_entre_nuevos_por_clave_fuerte_y_canonico_con_tildes_en_el_empate()
    {
        $grupo = RelacionesParecidasDetector::analizar_grupo(
            ['Pintureria' => 2, 'Pinturería' => 2, 'PINTURERIA' => 1],
            []
        );

        $cerrado = RelacionesParecidasDetector::cerrar_grupo('categoria', $grupo, [], [], true);

        $this->assertCount(2, $cerrado['items']);

        foreach (['categoria|Pintureria', 'categoria|PINTURERIA'] as $clave) {
            $item = $this->item($cerrado['items'], $clave);

            $this->assertNotNull($item, $clave);
            $this->assertSame('entre_nuevos', $item['motivo']);
            $this->assertSame([['accion' => 'unir', 'con' => 'Pinturería'], ['accion' => 'crear']], $item['opciones']);
            $this->assertSame(0, $item['sugerida']);
        }
    }

    public function test_el_canonico_es_el_mas_frecuente()
    {
        $grupo = RelacionesParecidasDetector::analizar_grupo(['Pintureria' => 5, 'Pinturería' => 2], []);

        $cerrado = RelacionesParecidasDetector::cerrar_grupo('categoria', $grupo, [], [], true);

        $this->assertCount(1, $cerrado['items']);
        $this->assertSame('Pinturería', $cerrado['items'][0]['valor']);
        $this->assertSame([['accion' => 'unir', 'con' => 'Pintureria'], ['accion' => 'crear']], $cerrado['items'][0]['opciones']);
    }

    /**
     * Entre valores nuevos parecidos (no misma escritura) el candidato se anota solo del lado del
     * menos canónico, y se unen solo si la IA lo confirma.
     */
    public function test_entre_nuevos_parecidos_dependen_de_la_ia()
    {
        $grupo = RelacionesParecidasDetector::analizar_grupo(['Martillos' => 3, 'Martillo' => 1], []);

        $this->assertSame([], $grupo['valores']['Martillos']['candidatos_archivo']);
        $this->assertSame('Martillos', $grupo['valores']['Martillo']['candidatos_archivo'][0]['valor']);

        $enviados = ['Martillo' => true];

        $si = RelacionesParecidasDetector::cerrar_grupo('sub_categoria', $grupo, ['Martillo' => ['archivo' => 'Martillos', 'seguro' => true]], $enviados, true, 'Herramientas', null);
        $this->assertCount(1, $si['items']);
        $this->assertSame('entre_nuevos', $si['items'][0]['motivo']);
        $this->assertSame('sub_categoria|Herramientas|Martillo', $si['items'][0]['clave']);
        $this->assertSame('Herramientas', $si['items'][0]['padre']);

        /* Dudoso (sin `seguro`, o en false): se pregunta igual, con "crear aparte" marcado. */
        foreach ([['archivo' => 'Martillos', 'seguro' => false], ['archivo' => 'Martillos']] as $eleccion) {
            $dudoso = RelacionesParecidasDetector::cerrar_grupo('sub_categoria', $grupo, ['Martillo' => $eleccion], $enviados, true, 'Herramientas', null);
            $this->assertCount(1, $dudoso['items']);
            $this->assertSame('posible', $dudoso['items'][0]['motivo']);
            $this->assertSame([['accion' => 'unir', 'con' => 'Martillos'], ['accion' => 'crear']], $dudoso['items'][0]['opciones']);
            $this->assertSame(1, $dudoso['items'][0]['sugerida']);
            $this->assertSame('Podría ser «Martillos» escrito de otra forma; si es el mismo, elegilo', $dudoso['items'][0]['explicacion']);
        }

        $no = RelacionesParecidasDetector::cerrar_grupo('sub_categoria', $grupo, ['Martillo' => null], $enviados, true, 'Herramientas', null);
        $this->assertCount(0, $no['items']);

        $caida = RelacionesParecidasDetector::cerrar_grupo('sub_categoria', $grupo, [], $enviados, false, 'Herramientas', null);
        $this->assertCount(1, $caida['items']);
        $this->assertSame('posible', $caida['items'][0]['motivo']);
        $this->assertSame(1, $caida['items'][0]['sugerida'], 'Con la IA caída se preselecciona "crear aparte".');
    }

    /* ------------------------------------------------------------------
     * Tope de columna
     * ------------------------------------------------------------------ */

    public function test_con_mas_de_2000_valores_solo_corre_la_clave_fuerte()
    {
        $valores = ['Ferret. del Norte' => 1, 'del norte' => 1];

        for ($i = 0; $i < RelacionesParecidasDetector::TOPE_VALORES_POR_COLUMNA; $i++) {
            $valores['Valor ' . $i] = 1;
        }

        $grupo = RelacionesParecidasDetector::analizar_grupo($valores, [
            ['id' => 5, 'nombre' => 'DEL NORTE'],
            ['id' => 7, 'nombre' => 'Ferretería Del Norte'],
        ]);

        $this->assertFalse($grupo['con_parecidos']);
        $this->assertSame('nuevo', $grupo['valores']['Ferret. del Norte']['estado'], 'Sin capa de parecidos no hay candidatos.');
        $this->assertSame('misma_escritura', $grupo['valores']['del norte']['estado'], 'La clave fuerte corre igual.');
    }

    /* ------------------------------------------------------------------
     * Cierre con la IA
     * ------------------------------------------------------------------ */

    public function test_cierre_con_la_ia()
    {
        $existentes = [
            ['id' => 7, 'nombre' => 'Ferretería Del Norte'],
            ['id' => 8, 'nombre' => 'Ferretería del Sur'],
        ];

        $grupo = RelacionesParecidasDetector::analizar_grupo(['Ferret. del Norte' => 2], $existentes);
        $enviados = ['Ferret. del Norte' => true];

        $candidatos = $grupo['valores']['Ferret. del Norte']['candidatos'];
        $this->assertSame(7, $candidatos[0]['id']);

        $eligio = RelacionesParecidasDetector::cerrar_grupo('proveedor', $grupo, ['Ferret. del Norte' => ['existente' => 7, 'seguro' => true]], $enviados, true);
        $this->assertCount(1, $eligio['items']);
        $this->assertSame('parecido', $eligio['items'][0]['motivo']);
        $this->assertSame('usar', $eligio['items'][0]['opciones'][$eligio['items'][0]['sugerida']]['accion']);
        $this->assertSame(7, $eligio['items'][0]['opciones'][$eligio['items'][0]['sugerida']]['id']);
        $this->assertSame(['accion' => 'crear'], $eligio['items'][0]['opciones'][count($eligio['items'][0]['opciones']) - 1]);
        $this->assertSame(2, $eligio['items'][0]['filas']);
        $this->assertSame(7, $eligio['supuestos']['Ferret. del Norte']);

        $ninguno = RelacionesParecidasDetector::cerrar_grupo('proveedor', $grupo, ['Ferret. del Norte' => null], $enviados, true);
        $this->assertCount(0, $ninguno['items'], 'Lo que la IA dice que no coincide no se le muestra al usuario.');
        $this->assertNull($ninguno['supuestos']['Ferret. del Norte']);

        foreach ([['existente' => 7, 'seguro' => false], ['existente' => 7], ['existente' => 7, 'seguro' => 'true']] as $eleccion) {
            $dudoso = RelacionesParecidasDetector::cerrar_grupo('proveedor', $grupo, ['Ferret. del Norte' => $eleccion], $enviados, true);
            $item = $dudoso['items'][0];

            $this->assertSame('posible', $item['motivo'], json_encode($eleccion));
            $this->assertSame(['accion' => 'crear'], $item['opciones'][$item['sugerida']], 'Dudoso: "crear" marcado.');
            $this->assertSame(7, $item['opciones'][0]['id'], 'El candidato se ofrece igual.');
            $this->assertSame('Podría ser «Ferretería Del Norte» escrito de otra forma; si es el mismo, elegilo', $item['explicacion']);
            $this->assertNull($dudoso['supuestos']['Ferret. del Norte'], 'Con "crear" marcado, la fila no supone el existente.');
        }
    }
}
