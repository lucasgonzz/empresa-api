<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer;
use App\Http\Controllers\Helpers\import\excel\ExcelHeaderDetector;
use App\Http\Controllers\Helpers\import\excel\ExcelWorkbookReader;
use Tests\TestCase;

/**
 * Lectura de la hoja elegida del libro (unidad 1 de la misión de importación de Excel).
 *
 * El defecto que cubre: los doce lectores del import abrían el libro y cortaban con
 * `break` después de la primera hoja. Un libro con la lista de precios en la hoja 2 se
 * importaba vacío, sin un solo error en pantalla, y el usuario no tenía forma de elegir.
 *
 * No extiende ImportTestCase: acá no se toca la base ni el tenant sembrado, sólo se leen
 * archivos con OpenSpout. La base Tests\TestCase (que sólo aplica el guard de "base de
 * testing segura") alcanza y evita levantar DatabaseTransactions/ImportTestSeeder de más,
 * igual que hace CadenaIdentificacionTest.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
class LecturaDeHojasTest extends TestCase
{
    /**
     * Ruta absoluta a un fixture de tests/Import/fixtures/.
     *
     * @param  string $archivo
     * @return string
     */
    protected function fixture($archivo)
    {
        return __DIR__ . '/fixtures/' . $archivo;
    }

    /**
     * Llama a un método protected de AiExcelAnalyzer vía reflexión.
     *
     * Los métodos son protected a propósito (ver AiExcelAnalyzer): se llaman por reflexión
     * en vez de volverlos públicos, para no ensanchar la API del helper sólo para poder
     * testearla. No "arreglar" esto cambiando la visibilidad del método de producción.
     *
     * @param  string $metodo
     * @param  array  $argumentos
     * @return mixed
     */
    protected function invocar_analyzer($metodo, array $argumentos)
    {
        $analyzer = new AiExcelAnalyzer(1);

        $reflexion = new \ReflectionMethod($analyzer, $metodo);
        $reflexion->setAccessible(true);

        return $reflexion->invokeArgs($analyzer, $argumentos);
    }

    /* ---------------------------------------------------------------------
     * Test 1 — no regresión. El más importante de la unidad.
     * ------------------------------------------------------------------- */

    /**
     * Un libro de una sola hoja con el encabezado en la fila 1 se tiene que leer
     * EXACTAMENTE igual que antes de que existiera todo este namespace.
     *
     * 15_una_sola_hoja.xlsx tiene el mismo contenido celda por celda que
     * 01_codigos_de_proveedor.xlsx, pero está escrito con PhpSpreadsheet en vez de
     * OpenSpout: si los dos se leen idéntico, la lectura tampoco depende de quién escribió
     * el archivo.
     *
     * @return void
     */
    public function test_un_libro_de_una_sola_hoja_se_lee_exactamente_como_hoy()
    {
        $hojas = ExcelWorkbookReader::listar_hojas($this->fixture('15_una_sola_hoja.xlsx'));

        $this->assertCount(1, $hojas, 'Un libro de una sola hoja tiene que ofrecer una sola hoja.');
        $this->assertSame(0, $hojas[0]['indice']);
        $this->assertSame('Hoja1', $hojas[0]['nombre']);

        $muestra_vieja = $this->invocar_analyzer('read_sample_rows', [$this->fixture('01_codigos_de_proveedor.xlsx')]);
        $muestra_nueva = $this->invocar_analyzer('read_sample_rows', [$this->fixture('15_una_sola_hoja.xlsx')]);

        $this->assertSame(
            $muestra_vieja['headers'],
            $muestra_nueva['headers'],
            'Los encabezados leídos cambiaron respecto del fixture de referencia.'
        );

        $this->assertSame(
            $muestra_vieja['rows'],
            $muestra_nueva['rows'],
            'Las filas de muestra leídas cambiaron respecto del fixture de referencia.'
        );

        $filas_viejas = $this->invocar_analyzer('count_data_rows', [$this->fixture('01_codigos_de_proveedor.xlsx')]);
        $filas_nuevas = $this->invocar_analyzer('count_data_rows', [$this->fixture('15_una_sola_hoja.xlsx')]);

        $this->assertSame(7, $filas_viejas);
        $this->assertSame($filas_viejas, $filas_nuevas);
    }

    /**
     * Red de seguridad sobre TODO el parque de fixtures existente: la regla nueva de
     * detección de encabezado tiene que seguir eligiendo la fila 1 en los archivos que ya
     * andaban.
     *
     * Es barato y es lo que detecta de una que alguien tocó la regla de §1.3 y se llevó
     * puesto el caso normal.
     *
     * (Son doce archivos y no once: el prefijo "07_" aparece dos veces, en
     * 07_cadena_sobre_articulo_existente.xlsx y 07_repetidos_en_el_archivo.xlsx.)
     *
     * @dataProvider fixtures_existentes
     *
     * @param  string $archivo
     * @return void
     */
    public function test_los_once_fixtures_existentes_siguen_teniendo_el_encabezado_en_la_fila_1($archivo)
    {
        $resultado = ExcelHeaderDetector::detectar_en($this->fixture($archivo), 0);

        $this->assertSame(1, $resultado['fila'], 'Cambió la fila de encabezado de ' . $archivo);
        $this->assertSame('primera_fila_con_contenido', $resultado['motivo'], 'Cambió el motivo de ' . $archivo);
        $this->assertSame('alta', $resultado['confianza'], 'Cambió la confianza de ' . $archivo);
    }

    /**
     * @return array
     */
    public function fixtures_existentes()
    {
        return [
            ['01_codigos_de_proveedor.xlsx'],
            ['02_codigos_de_barra_repetidos.xlsx'],
            ['03_numeros_y_costos.xlsx'],
            ['04_stock.xlsx'],
            ['05_rollback.xlsx'],
            ['06_incidente_servian.xlsx'],
            ['07_cadena_sobre_articulo_existente.xlsx'],
            ['07_repetidos_en_el_archivo.xlsx'],
            ['08_match_unico_provider_code.xlsx'],
            ['09_cascada_herencia.xlsx'],
            ['10_escalon_nombre.xlsx'],
            ['11_precio_vs_margen.xlsx'],
        ];
    }

    /* ---------------------------------------------------------------------
     * Test 2 — tres hojas (parte helper).
     * ------------------------------------------------------------------- */

    /**
     * Las tres hojas se ofrecen enteras, en orden, con nombre y cantidad de filas: eso es
     * lo que el usuario ve en el selector del paso 1.
     *
     * @return void
     */
    public function test_un_libro_de_tres_hojas_se_ofrece_entero_con_nombre_y_cantidad_de_filas()
    {
        $hojas = ExcelWorkbookReader::listar_hojas($this->fixture('12_tres_hojas.xlsx'));

        $this->assertSame(
            [
                ['indice' => 0, 'nombre' => 'Lista de precios', 'filas' => 8],
                ['indice' => 1, 'nombre' => 'Notas',            'filas' => 3],
                ['indice' => 2, 'nombre' => 'Resumen',          'filas' => 2],
            ],
            $hojas
        );
    }

    /**
     * Éste es el que prueba que el `break` murió de verdad: pedir la hoja 2 devuelve el
     * contenido de la hoja 2, no el de la 1.
     *
     * @return void
     */
    public function test_leer_la_hoja_dos_devuelve_el_contenido_de_la_hoja_dos_y_no_el_de_la_uno()
    {
        $lectura = ExcelWorkbookReader::abrir($this->fixture('12_tres_hojas.xlsx'), 1, true);

        $this->assertSame('Notas', $lectura->nombre());

        $filas = [];

        foreach ($lectura->filas() as $fila) {
            $celdas = [];

            foreach ($fila->getCells() as $celda) {
                $celdas[] = (string) $celda->getValue();
            }

            $filas[] = $celdas;
        }

        $lectura->cerrar();

        $this->assertSame(
            [
                ['Estas son notas internas del vendedor'],
                ['Los precios de la lista no incluyen IVA'],
                ['Actualizado por Marcela el 12 de agosto'],
            ],
            $filas
        );

        /* Y la hoja 0 sigue siendo la hoja 0: pedir una no rompe la otra. */
        $primera = ExcelWorkbookReader::abrir($this->fixture('12_tres_hojas.xlsx'), 0, true);

        $this->assertSame('Lista de precios', $primera->nombre());

        $primera->cerrar();
    }

    /**
     * resolver_indice() prioriza el nombre exacto sobre el índice, y nunca devuelve un
     * índice fuera de rango.
     *
     * El nombre gana porque el índice puede venir calculado por SheetJS en el navegador y
     * no está garantizado que coincida con el de OpenSpout (T11 del plan).
     *
     * @return void
     */
    public function test_el_indice_de_hoja_se_resuelve_por_nombre_antes_que_por_numero()
    {
        $archivo = $this->fixture('12_tres_hojas.xlsx');

        /* El nombre manda aunque el índice diga otra cosa. */
        $this->assertSame(2, ExcelWorkbookReader::resolver_indice($archivo, 0, 'Resumen'));

        /* Sin nombre, manda el índice. */
        $this->assertSame(1, ExcelWorkbookReader::resolver_indice($archivo, 1, null));

        /* Nombre que no existe y sin índice válido: hoja 0, el comportamiento de siempre. */
        $this->assertSame(0, ExcelWorkbookReader::resolver_indice($archivo, null, 'Hoja Que No Existe'));

        /* Índice fuera de rango: hoja 0, nunca un índice inválido. */
        $this->assertSame(0, ExcelWorkbookReader::resolver_indice($archivo, 99, null));
    }
}
