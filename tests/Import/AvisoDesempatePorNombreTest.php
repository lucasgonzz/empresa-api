<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer;
use Tests\TestCase;

/**
 * El aviso del paso 3: ¿al desempate por nombre le va a servir ESTE archivo?
 * (mision `desempate-por-nombre-codigo-repetido`, 9/9/2026).
 *
 * La opcion `desempatar_por_nombre` le pide al usuario una decision que el solo no puede
 * tomar: "los codigos de proveedor que se repiten en tu archivo, ¿tienen nombres distintos
 * entre si?". Para contestarla habria que abrir el Excel y cruzar codigo por codigo. El
 * analizador ya recorre el archivo entero, asi que lo calcula y lo devuelve en la clave
 * `desempate_por_nombre`, que la SPA lee para decidir si ofrece la opcion y con que texto.
 *
 * 🔴 EL NORMALIZADOR TIENE QUE SER EL MISMO que usa el desempate real
 * (ArticleIndexCache::normalize_name_for_match), no el mb_strtolower() de
 * `nombres_duplicados`. Si divergen, la pantalla dice "se pueden separar por nombre" para
 * un archivo donde el importador despues no los separa: le estariamos mintiendo al usuario
 * justo en la decision que le estamos pidiendo. Lo mide
 * test_dos_nombres_que_solo_difieren_en_espacios_no_desempatan.
 *
 * No extiende ImportTestCase por el mismo motivo que CadenaIdentificacionTest:
 * analyze_identification_chain() solo lee el Excel con OpenSpout, no toca la base.
 *
 * El metodo es protected a proposito y se llama via ReflectionMethod. No "arreglar" esto
 * cambiandole la visibilidad al metodo de produccion.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promocion de constructor, readonly, enum ni #[...].
 */
class AvisoDesempatePorNombreTest extends TestCase
{
    /**
     * Llama a AiExcelAnalyzer::analyze_identification_chain() via reflexion y devuelve
     * solo el bloque `desempate_por_nombre`.
     *
     * @param  string $archivo         nombre del fixture
     * @param  array  $column_mapping
     * @return array
     */
    protected function aviso($archivo, array $column_mapping)
    {
        $ruta = __DIR__ . '/fixtures/' . $archivo;

        $this->assertFileExists($ruta, 'Falta el fixture ' . $archivo);

        $analyzer = new AiExcelAnalyzer(1);

        $metodo = new \ReflectionMethod($analyzer, 'analyze_identification_chain');
        $metodo->setAccessible(true);

        $resultado = $metodo->invoke($analyzer, $ruta, $column_mapping);

        $this->assertArrayHasKey(
            'desempate_por_nombre',
            $resultado,
            'La clave `desempate_por_nombre` es contrato con la SPA: si desaparece, el modal '
            . 'deja de ofrecer la opcion EN SILENCIO.'
        );

        return $resultado['desempate_por_nombre'];
    }

    /**
     * Mapeo de las cuatro columnas identificadoras de los fixtures de esta suite
     * (orden fijo, ver fixtures/generar.php): 1 bar_code, 2 sku, 3 provider_code, 4 nombre
     * -> indices 0-based 0, 1, 2 y 3.
     *
     * @return array
     */
    protected function mapeo()
    {
        return [
            ['excel_column' => 'codigo_de_barras',     'system_property' => 'codigo_de_barras',    'excel_column_index' => 0],
            ['excel_column' => 'sku',                  'system_property' => 'sku',                 'excel_column_index' => 1],
            ['excel_column' => 'codigo_de_proveedor',  'system_property' => 'codigo_de_proveedor', 'excel_column_index' => 2],
            ['excel_column' => 'nombre',               'system_property' => 'nombre',              'excel_column_index' => 3],
        ];
    }

    /**
     * 22_desempate_por_nombre.xlsx tiene UN solo codigo repetido (PC-PACK, dos filas con
     * nombres distintos). PC-IGUAL y PC-REDACT aparecen una sola vez cada uno en el
     * archivo: su ambiguedad esta contra la BASE, no adentro del archivo, y el analizador
     * -- que solo mira el archivo -- no la puede ver.
     *
     * O sea que EN ESTE ARCHIVO el unico codigo repetido tiene nombres distintos. Eso es
     * todo lo que se puede afirmar, y por eso la clave se llama `sirve_en_el_archivo`:
     * `DesempatePorNombreTest` demuestra sobre el MISMO fixture que PC-IGUAL y PC-REDACT
     * no desempatan contra la base. Los dos tests son ciertos porque hablan de universos
     * distintos -- ver test_el_aviso_no_ve_la_ambiguedad_que_esta_en_la_base.
     *
     * @return void
     */
    public function test_codigos_repetidos_con_nombres_distintos_dicen_que_sirve()
    {
        $aviso = $this->aviso('22_desempate_por_nombre.xlsx', $this->mapeo());

        $this->assertTrue($aviso['aplica'], 'Hay un provider_code repetido en el archivo.');

        $this->assertTrue(
            $aviso['sirve_en_el_archivo'],
            'El unico codigo repetido DEL ARCHIVO tiene nombres distintos entre si.'
        );

        $this->assertSame(
            'solo_el_archivo',
            $aviso['alcance'],
            'El resumen tiene que decir de donde salio: se miro el archivo, no la base.'
        );

        $this->assertSame(1, $aviso['codigos_repetidos']);
        $this->assertSame(1, $aviso['codigos_con_nombres_distintos']);
        $this->assertSame(0, $aviso['codigos_con_nombres_repetidos']);
        $this->assertSame(2, $aviso['filas_afectadas']);

        $this->assertCount(1, $aviso['ejemplos']);

        $this->assertSame('PC-PACK', $aviso['ejemplos'][0]['codigo']);
        $this->assertSame(2,         $aviso['ejemplos'][0]['veces']);
        $this->assertSame(2,         $aviso['ejemplos'][0]['nombres_distintos']);
        $this->assertTrue($aviso['ejemplos'][0]['todos_los_nombres_distintos']);
    }

    /**
     * 18_pc_repetido_mismo_nombre.xlsx: tres filas con el mismo provider_code Y el mismo
     * nombre. No hay nada que desempatar, y la pantalla NO tiene que ofrecer la opcion
     * como si fuera la solucion.
     *
     * @return void
     */
    public function test_codigo_repetido_con_el_mismo_nombre_dice_que_no_sirve()
    {
        $aviso = $this->aviso('18_pc_repetido_mismo_nombre.xlsx', $this->mapeo());

        $this->assertTrue($aviso['aplica'], 'PC-MN-1 aparece tres veces: el caso aplica.');

        $this->assertFalse(
            $aviso['sirve_en_el_archivo'],
            'Las tres filas se llaman igual: el nombre no distingue nada y el desempate no sirve.'
        );

        $this->assertSame(1, $aviso['codigos_repetidos']);
        $this->assertSame(0, $aviso['codigos_con_nombres_distintos']);
        $this->assertSame(1, $aviso['codigos_con_nombres_repetidos']);
        $this->assertSame(3, $aviso['filas_afectadas']);

        $this->assertFalse($aviso['ejemplos'][0]['todos_los_nombres_distintos']);
        $this->assertSame(1, $aviso['ejemplos'][0]['nombres_distintos'], 'Tres filas, un solo nombre distinto.');
    }

    /**
     * 🔴 LO QUE ESTE RESUMEN NO PUEDE VER, y por eso ninguna de sus claves se llama
     * "sirve" a secas (lo senialo el chequeo independiente del 9/9/2026).
     *
     * El analizador mira el ARCHIVO; el desempate al reimportar compara cada fila contra
     * los ARTICULOS DE LA BASE. En el fixture 22, PC-IGUAL y PC-REDACT aparecen UNA sola
     * vez cada uno: para el analizador no son codigos repetidos y no cuentan para nada.
     * Pero en `DesempatePorNombreTest` esos mismos dos codigos matchean DOS articulos de
     * la base cada uno y el desempate NO los resuelve.
     *
     * O sea que el archivo dice "el unico codigo repetido tiene nombres distintos" (cierto)
     * y la importacion deja dos codigos sin resolver (tambien cierto). Los dos son ciertos
     * porque hablan de universos distintos, y este test lo deja escrito: sin el, los dos
     * tests verdes se contradicen a la vista y el proximo que los lea va a "arreglar" el
     * que no esta roto.
     *
     * Lo que el dato tiene que garantizar es que la SPA pueda redactar un texto honesto:
     * `alcance` dice que se miro solo el archivo, y `sirve_en_el_archivo` no promete el
     * resultado de la importacion.
     *
     * @return void
     */
    public function test_el_aviso_no_ve_la_ambiguedad_que_esta_en_la_base()
    {
        $aviso = $this->aviso('22_desempate_por_nombre.xlsx', $this->mapeo());

        $this->assertSame(
            1,
            $aviso['codigos_repetidos'],
            'Solo PC-PACK se repite DENTRO del archivo; PC-IGUAL y PC-REDACT aparecen una vez cada uno.'
        );

        $codigos_de_los_ejemplos = array_map(function ($ejemplo) {
            return $ejemplo['codigo'];
        }, $aviso['ejemplos']);

        $this->assertNotContains(
            'PC-IGUAL',
            $codigos_de_los_ejemplos,
            'El analizador no puede ver una ambiguedad que vive en la base: PC-IGUAL no aparece aca.'
        );

        $this->assertNotContains(
            'PC-REDACT',
            $codigos_de_los_ejemplos,
            'Idem PC-REDACT. Que el archivo no lo denuncie no significa que el desempate lo resuelva.'
        );

        $this->assertSame(
            'solo_el_archivo',
            $aviso['alcance'],
            'La clave que le avisa a la SPA que este resumen no habla por la base.'
        );
    }

    /**
     * 20_mismo_nombre_solo_una_con_codigo.xlsx: dos filas con el mismo nombre, pero solo
     * una trae provider_code. Ningun codigo se repite, asi que el caso NO aplica y la
     * opcion no tiene por que aparecer.
     *
     * Es el borde que separa "aplica" de "sirve_en_el_archivo": `nombres_duplicados` para
     * este archivo dice que hay un nombre repetido, y sin este chequeo alguien podria
     * concluir que el desempate tiene algo que hacer aca.
     *
     * @return void
     */
    public function test_sin_codigos_repetidos_el_caso_no_aplica()
    {
        $aviso = $this->aviso('20_mismo_nombre_solo_una_con_codigo.xlsx', $this->mapeo());

        $this->assertFalse($aviso['aplica'], 'Ningun provider_code se repite en este archivo.');
        $this->assertFalse($aviso['sirve_en_el_archivo'],  'Si no aplica, no puede servir.');

        $this->assertSame(0, $aviso['codigos_repetidos']);
        $this->assertSame(0, $aviso['filas_afectadas']);
        $this->assertSame([], $aviso['ejemplos']);
    }

    /**
     * 07_repetidos_en_el_archivo.xlsx tiene DOS codigos repetidos con comportamientos
     * distintos: PC-R-X (2 filas, nombres distintos) y PC-R-Z (3 filas, nombres
     * distintos). Los dos desempatan.
     *
     * Sirve para fijar que el conteo es por CODIGO y no por fila, y que un archivo con
     * varios codigos repetidos se resume bien.
     *
     * @return void
     */
    public function test_varios_codigos_repetidos_se_cuentan_por_codigo()
    {
        $aviso = $this->aviso('07_repetidos_en_el_archivo.xlsx', $this->mapeo());

        $this->assertTrue($aviso['aplica']);
        $this->assertTrue($aviso['sirve_en_el_archivo']);

        $this->assertSame(2, $aviso['codigos_repetidos'],     'PC-R-X y PC-R-Z.');
        $this->assertSame(2, $aviso['codigos_con_nombres_distintos']);
        $this->assertSame(0, $aviso['codigos_con_nombres_repetidos']);
        $this->assertSame(5, $aviso['filas_afectadas'],       '2 filas de PC-R-X + 3 de PC-R-Z.');
    }

    /**
     * 🔴 EL TEST DEL NORMALIZADOR. Dos filas con el mismo codigo cuyos nombres solo
     * difieren en mayusculas y espacios internos NO desempatan, porque
     * normalize_name_for_match() los colapsa al mismo valor -- que es exactamente lo que
     * va a hacer el importador cuando corra el desempate de verdad.
     *
     * Si alguien "simplificara" esto comparando los nombres crudos, este test se pone en
     * rojo. Sin el, el aviso diria que sirve y despues la importacion dejaria los dos
     * articulos pisandose, sin que nada en la pantalla lo hubiera anticipado.
     *
     * El archivo se arma en el momento (no es un fixture del repo) porque es un caso de
     * borde del NORMALIZADOR, no de la importacion: fabricarlo aca lo deja al lado de la
     * afirmacion que sostiene.
     *
     * @return void
     */
    public function test_dos_nombres_que_solo_difieren_en_espacios_no_desempatan()
    {
        $ruta = $this->escribir_excel_temporal([
            [null, null, 'PC-ESP', 'Silicona neutra negra'],
            [null, null, 'PC-ESP', '  SILICONA   NEUTRA  NEGRA '],
        ]);

        $aviso = $this->aviso_de_ruta($ruta, $this->mapeo());

        @unlink($ruta);

        $this->assertTrue($aviso['aplica']);

        $this->assertFalse(
            $aviso['sirve_en_el_archivo'],
            'Los dos nombres colapsan al mismo valor con normalize_name_for_match(): no desempatan.'
        );

        $this->assertSame(1, $aviso['ejemplos'][0]['nombres_distintos']);
    }

    /**
     * Igual que aviso(), pero con una ruta absoluta ya armada.
     *
     * @param  string $ruta
     * @param  array  $column_mapping
     * @return array
     */
    protected function aviso_de_ruta($ruta, array $column_mapping)
    {
        $analyzer = new AiExcelAnalyzer(1);

        $metodo = new \ReflectionMethod($analyzer, 'analyze_identification_chain');
        $metodo->setAccessible(true);

        $resultado = $metodo->invoke($analyzer, $ruta, $column_mapping);

        return $resultado['desempate_por_nombre'];
    }

    /**
     * Escribe un xlsx temporal con la cabecera de cuatro columnas identificadoras.
     *
     * Usa OpenSpout, el mismo writer que fixtures/generar.php, para que el archivo se lea
     * igual que los del repo.
     *
     * @param  array $filas
     * @return string ruta absoluta al archivo
     */
    protected function escribir_excel_temporal(array $filas)
    {
        $ruta = sys_get_temp_dir() . '/' . uniqid('desempate_') . '.xlsx';

        $writer = \OpenSpout\Writer\Common\Creator\WriterEntityFactory::createXLSXWriter();
        $writer->openToFile($ruta);

        $writer->addRow(\OpenSpout\Writer\Common\Creator\WriterEntityFactory::createRowFromArray([
            'codigo_de_barras',
            'sku',
            'codigo_de_proveedor',
            'nombre',
        ]));

        foreach ($filas as $fila) {
            $writer->addRow(\OpenSpout\Writer\Common\Creator\WriterEntityFactory::createRowFromArray($fila));
        }

        $writer->close();

        return $ruta;
    }
}
