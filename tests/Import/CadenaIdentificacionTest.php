<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer;
use Tests\TestCase;

/**
 * Test de caracterización de AiExcelAnalyzer::analyze_identification_chain()
 * (grupo 284, prompt 05).
 *
 * El bloque "Como se van a identificar los articulos" del paso 3 del modal
 * desapareció de la pantalla el 30/7/2026: el cálculo devolvía un resultado
 * vacío (columnas_mapeadas => [], todos los escalones en 0) y el SPA, que solo
 * dibuja el bloque si hay al menos un escalón con columna mapeada, simplemente
 * no lo mostraba. Desde afuera era indistinguible de "esta feature no existe".
 * El prompt 01 de este grupo agrega 'disponible'/'motivo'/'total_filas' para
 * que el fallo se vea; este test fija los conteos sobre archivos conocidos
 * para que si el cálculo se rompe de nuevo, se sepa antes de que lo vea un
 * cliente (contexto/APRENDER_NO_PARCHEAR.md: la "detección" de un bug).
 *
 * No extiende ImportTestCase: analyze_identification_chain() solo lee el
 * Excel con OpenSpout, no toca la base ni el tenant sembrado, así que la
 * base Tests\TestCase (que solo aplica el guard de "base de testing segura")
 * alcanza y evita levantar DatabaseTransactions/ImportTestSeeder de más.
 *
 * analyze_identification_chain() es protected a propósito (ver AiExcelAnalyzer):
 * se llama vía ReflectionMethod en vez de volverla pública, para no ensanchar
 * la API del helper solo para poder testearla. No "arreglar" esto cambiando la
 * visibilidad del método de producción.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe, argumentos
 * nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
class CadenaIdentificacionTest extends TestCase
{
    /**
     * Llama a AiExcelAnalyzer::analyze_identification_chain() vía reflexión
     * (el método es protected a propósito, ver el comentario de la clase).
     *
     * @param  string $excel_path
     * @param  array  $column_mapping
     * @return array
     */
    protected function analizar($excel_path, array $column_mapping)
    {
        $analyzer = new AiExcelAnalyzer(1);

        $metodo = new \ReflectionMethod($analyzer, 'analyze_identification_chain');
        $metodo->setAccessible(true);

        return $metodo->invoke($analyzer, $excel_path, $column_mapping);
    }

    /**
     * Ruta absoluta a un fixture de tests/Import/fixtures/.
     *
     * @param  string $archivo
     * @return string
     */
    protected function fixture($archivo)
    {
        $ruta = __DIR__ . '/fixtures/' . $archivo;

        $this->assertFileExists($ruta, 'Falta el fixture ' . $archivo);

        return $ruta;
    }

    /**
     * column_mapping enriquecido con las cuatro columnas identificadoras de los
     * fixtures de esta suite (orden fijo, ver fixtures/generar.php): 1 bar_code,
     * 2 sku, 3 provider_code, 4 nombre -> índices 0-based 0, 1, 2 y 3. Ninguno
     * de estos fixtures tiene columna 'numero' (id), así que ese escalón queda
     * siempre sin mapear.
     *
     * @return array
     */
    protected function mapeo_completo()
    {
        return [
            ['excel_column' => 'codigo_de_barras',    'system_property' => 'codigo_de_barras',    'excel_column_index' => 0],
            ['excel_column' => 'sku',                 'system_property' => 'sku',                 'excel_column_index' => 1],
            ['excel_column' => 'codigo_de_proveedor',  'system_property' => 'codigo_de_proveedor', 'excel_column_index' => 2],
            ['excel_column' => 'nombre',               'system_property' => 'nombre',              'excel_column_index' => 3],
        ];
    }

    /**
     * @param  array  $escalones
     * @param  string $campo
     * @return int
     */
    protected function filas_de($escalones, $campo)
    {
        foreach ($escalones as $escalon) {
            if ($escalon['campo'] === $campo) {
                return $escalon['filas'];
            }
        }

        $this->fail('No se encontró el escalón "' . $campo . '" en el resultado.');
    }

    /**
     * 07_repetidos_en_el_archivo.xlsx (9 filas de datos, ver generar.php):
     *   F2, F3      mismo bar_code (7799100)         -> escalón bar_code
     *   F4 a F7     sin bar_code, con sku             -> escalón sku (F6 y F7 TAMBIÉN
     *               tienen provider_code, pero sku tiene más prioridad: es el
     *               núcleo de la regla que este test fija)
     *   F8, F9, F10 solo provider_code (PC-R-Z)       -> escalón provider_code
     * Ningún escalón usa 'numero' ni 'nombre' en este fixture.
     */
    public function test_cuenta_cada_fila_en_su_primer_escalon_con_valor()
    {
        $resultado = $this->analizar(
            $this->fixture('07_repetidos_en_el_archivo.xlsx'),
            $this->mapeo_completo()
        );

        $escalones = $resultado['cadena_identificacion']['escalones'];

        $this->assertSame(0, $this->filas_de($escalones, 'id'));
        $this->assertSame(2, $this->filas_de($escalones, 'bar_code'));
        $this->assertSame(4, $this->filas_de($escalones, 'sku'));
        $this->assertSame(3, $this->filas_de($escalones, 'provider_code'));
        $this->assertSame(0, $this->filas_de($escalones, 'name'));
        $this->assertSame(0, $this->filas_de($escalones, 'sin_identificador'));
    }

    /**
     * La suma de los seis escalones tiene que dar total_filas, sea cual sea el
     * contenido del archivo: es la invariante barata que detecta un conteo
     * roto sin tener que conocer el contenido de cada fixture.
     */
    public function test_la_suma_de_los_escalones_da_el_total_de_filas()
    {
        foreach (['07_repetidos_en_el_archivo.xlsx', '01_codigos_de_proveedor.xlsx'] as $archivo) {
            $resultado = $this->analizar($this->fixture($archivo), $this->mapeo_completo());
            $cadena    = $resultado['cadena_identificacion'];

            $suma = 0;
            foreach ($cadena['escalones'] as $escalon) {
                $suma += $escalon['filas'];
            }

            $this->assertSame(
                $cadena['total_filas'],
                $suma,
                'La suma de los escalones no da total_filas en ' . $archivo
            );
        }
    }

    /**
     * 01_codigos_de_proveedor.xlsx tiene "S/N" (F7) y "-" (F8) en la columna de
     * provider_code: IdentifierNormalizer las descarta como placeholders, así
     * que esas dos filas NO caen en el escalón provider_code sino en el
     * siguiente que tengan valor (name), y además quedan registradas en el
     * array 'placeholders' del resultado. Es la garantía de que el conteo usa
     * el mismo normalizador que el importador real, no un !empty() ingenuo.
     */
    public function test_los_placeholders_no_cuentan_como_identificador()
    {
        $resultado = $this->analizar(
            $this->fixture('01_codigos_de_proveedor.xlsx'),
            $this->mapeo_completo()
        );

        $escalones = $resultado['cadena_identificacion']['escalones'];

        $this->assertSame(2, $this->filas_de($escalones, 'sku'), 'F2 y F3 tienen sku propio');
        $this->assertSame(3, $this->filas_de($escalones, 'provider_code'), 'F4, F5 y F6 solo tienen provider_code');
        $this->assertSame(2, $this->filas_de($escalones, 'name'), 'F7 (S/N) y F8 (-) caen en name');

        $placeholders_provider_code = array_values(array_filter(
            $resultado['placeholders'],
            function ($p) {
                return $p['campo'] === 'provider_code';
            }
        ));

        $this->assertCount(2, $placeholders_provider_code);

        $valores = array_map(function ($p) {
            return $p['valor'];
        }, $placeholders_provider_code);

        $this->assertContains('S/N', $valores);
        $this->assertContains('-', $valores);
    }

    /**
     * Sin la entrada de codigo_de_proveedor en el column_mapping, ese campo no
     * puede figurar en columnas_mapeadas (la columna no está mapeada en el
     * Excel) y las filas que hoy identifica provider_code caen en el siguiente
     * escalón que tengan valor.
     */
    public function test_una_columna_no_mapeada_no_aparece_en_columnas_mapeadas()
    {
        $mapeo_sin_provider_code = array_values(array_filter(
            $this->mapeo_completo(),
            function ($col) {
                return $col['system_property'] !== 'codigo_de_proveedor';
            }
        ));

        $resultado = $this->analizar(
            $this->fixture('01_codigos_de_proveedor.xlsx'),
            $mapeo_sin_provider_code
        );

        $cadena = $resultado['cadena_identificacion'];

        $this->assertNotContains('provider_code', $cadena['columnas_mapeadas']);

        /* F4 (PC-CROSS) ya no tiene ningún escalón previo con valor: sin
         * provider_code mapeado, cae directo en name. */
        $this->assertSame(5, $this->filas_de($cadena['escalones'], 'name'));
        $this->assertSame(0, $this->filas_de($cadena['escalones'], 'provider_code'));
    }

    /**
     * Este es el test del bug que originó el grupo: si ninguna columna
     * identificadora quedó mapeada, el resultado tiene que avisarlo
     * explícitamente (disponible = false, motivo = 'sin_columnas_identificadoras'),
     * no devolver un resultado vacío indistinguible de "no hay nada que ver acá".
     */
    public function test_sin_ninguna_columna_identificadora_avisa_en_vez_de_devolver_ceros()
    {
        $mapeo_sin_identificadoras = [
            ['excel_column' => 'costo',  'system_property' => 'costo',  'excel_column_index' => 4],
            ['excel_column' => 'precio', 'system_property' => 'precio', 'excel_column_index' => 5],
        ];

        $resultado = $this->analizar(
            $this->fixture('01_codigos_de_proveedor.xlsx'),
            $mapeo_sin_identificadoras
        );

        $cadena = $resultado['cadena_identificacion'];

        $this->assertFalse($cadena['disponible']);
        $this->assertSame('sin_columnas_identificadoras', $cadena['motivo']);
        $this->assertSame([], $cadena['columnas_mapeadas']);
    }
}
