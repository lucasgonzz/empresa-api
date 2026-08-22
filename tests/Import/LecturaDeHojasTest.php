<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer;
use App\Http\Controllers\Helpers\import\excel\ExcelHeaderDetector;
use App\Http\Controllers\Helpers\import\excel\ExcelSheetInspector;
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
     * 🔴 LOS VALORES DE ABAJO ESTÁN ESCRITOS A MANO, NO SALEN DE OTRA LLAMADA AL MISMO
     * CÓDIGO. Ésa es la única forma de que este test sirva para lo que dice servir. Antes
     * comparaba `read_sample_rows(01)` contra `read_sample_rows(15)`: si el código nuevo
     * hubiera roto la lectura de forma pareja —que es exactamente lo que pasa cuando se
     * rompe un lector compartido— los dos lados habrían devuelto la misma basura y el test
     * pasaba igual. Un test de no regresión que se compara contra sí mismo no mide nada.
     * Estos literales son los que producía producción ANTES de la misión, medidos sobre el
     * archivo. Si alguno cambia, cambió el comportamiento del caso normal, que es lo único
     * que esta misión no puede tocar.
     *
     * @return void
     */
    public function test_un_libro_de_una_sola_hoja_se_lee_exactamente_como_hoy()
    {
        $encabezados_esperados = [
            'codigo_de_barras', 'sku', 'codigo_de_proveedor', 'nombre', 'costo', 'precio', 'stock_actual', 'iva',
        ];

        $filas_esperadas = [
            ['', 'SKU-NUEVO-UNICO', 'PC-100',   'Art unico prov A EDITADO',  '111', '222',  '11', '21'],
            ['', 'SKU-NUEVO-DUP',   'PC-DUP',   'Art PC repetido EDITADO',   '333', '444',  '33', '21'],
            ['', '',                'PC-CROSS', 'Art PC cruzado EDITADO',    '555', '666',  '55', '21'],
            ['', '',                'PC-NUEVO', 'Articulo nuevo por PC',     '777', '888',  '77', '21'],
            ['', '',                'PC-1500',  'Art solo prov C EDITADO',   '999', '1110', '99', '21'],
            ['', '',                'S/N',      'Placeholder SN sin codigo', '100', '200',  '5',  '21'],
            ['', '',                '-',        'Placeholder guion sin cod', '120', '240',  '6',  '21'],
        ];

        $hojas = ExcelWorkbookReader::listar_hojas($this->fixture('15_una_sola_hoja.xlsx'));

        $this->assertSame(
            [
                ['indice' => 0, 'nombre' => 'Hoja1', 'filas' => 8],
            ],
            $hojas,
            'Un libro de una sola hoja tiene que ofrecer una sola hoja, con sus 8 filas físicas.'
        );

        $muestra_vieja = $this->invocar_analyzer('read_sample_rows', [$this->fixture('01_codigos_de_proveedor.xlsx')]);
        $muestra_nueva = $this->invocar_analyzer('read_sample_rows', [$this->fixture('15_una_sola_hoja.xlsx')]);

        $this->assertSame($encabezados_esperados, $muestra_vieja['headers']);
        $this->assertSame($encabezados_esperados, $muestra_nueva['headers']);

        $this->assertSame($filas_esperadas, $muestra_vieja['rows']);
        $this->assertSame($filas_esperadas, $muestra_nueva['rows']);

        $this->assertSame(7, $this->invocar_analyzer('count_data_rows', [$this->fixture('01_codigos_de_proveedor.xlsx')]));
        $this->assertSame(7, $this->invocar_analyzer('count_data_rows', [$this->fixture('15_una_sola_hoja.xlsx')]));
    }

    /* ---------------------------------------------------------------------
     * La cantidad de filas del selector de hoja.
     * ------------------------------------------------------------------- */

    /**
     * 🔴 LA CANTIDAD DE FILAS ES EL ÚNICO DATO NUMÉRICO QUE EL USUARIO TIENE PARA RECONOCER
     * SU LISTA EN EL SELECTOR DE HOJA. Si dice "Lista (1 filas)" y "Notas (3 filas)", elige
     * mal y no hay nada en pantalla que lo denuncie.
     *
     * `<dimension>` es un dato que escribe quien generó el archivo, y hay generadores que lo
     * escriben mal. Los tres casos medidos, todos sobre una hoja de 6 filas reales:
     *
     *   ref="A1:A1"  ->  daba 1   (pasaba el filtro de "tiene dos puntos" y se le creía)
     *   ref="A1"     ->  daba 0 y caía bien al recorrido, éste ya andaba
     *   ref="A1:D2"  ->  daba 2   (declara de menos)
     *
     * Y una hoja vacía declara `ref="A1:A1"`, así que el selector decía "Vacia (1 filas)".
     *
     * Los libros de este test se arman acá y no en fixtures/: son archivos con la XML
     * manipulada a mano, no planillas que alguien pueda abrir y entender.
     *
     * @return void
     */
    public function test_un_dimension_que_miente_no_le_gana_a_las_filas_reales()
    {
        $base = $this->libro_de_seis_filas_y_una_hoja_vacia();

        $this->assertSame(
            [0 => 6, 1 => 0],
            ExcelSheetInspector::filas_por_hoja($base),
            'Con el <dimension> que escribe PhpSpreadsheet: 6 filas, y la hoja vacía en 0 (no en 1).'
        );

        foreach (['A1:A1', 'A1', 'A1:D2'] as $ref) {
            $mentiroso = $this->copia_con_dimension($base, $ref);

            $this->assertSame(
                [0 => 6, 1 => 0],
                ExcelSheetInspector::filas_por_hoja($mentiroso),
                'El <dimension ref="' . $ref . '"> se tomó como verdad sobre una hoja de 6 filas.'
            );
        }
    }

    /**
     * Un libro con una hoja "Lista" de 6 filas (una celda por fila) y una hoja "Vacia" sin
     * una sola celda. Se escribe en el directorio temporal del sistema.
     *
     * @return string  ruta absoluta
     */
    protected function libro_de_seis_filas_y_una_hoja_vacia()
    {
        $ruta = sys_get_temp_dir() . '/lectura_de_hojas_seis_filas.xlsx';

        $libro = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Lista');

        for ($fila = 1; $fila <= 6; $fila++) {
            $hoja->setCellValue('A' . $fila, 'valor ' . $fila);
        }

        $vacia = $libro->createSheet();
        $vacia->setTitle('Vacia');

        $escritor = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro);
        $escritor->save($ruta);

        $libro->disconnectWorksheets();

        return $ruta;
    }

    /**
     * Copia el libro reemplazando el `<dimension>` de la primera hoja por el ref pedido.
     *
     * @param  string $origen
     * @param  string $ref
     * @return string  ruta absoluta de la copia
     */
    protected function copia_con_dimension($origen, $ref)
    {
        $destino = sys_get_temp_dir() . '/lectura_de_hojas_dim_' . preg_replace('/[^A-Za-z0-9]/', '', $ref) . '.xlsx';

        copy($origen, $destino);

        $zip = new \ZipArchive();

        $this->assertTrue($zip->open($destino) === true, 'No se pudo abrir la copia del libro.');

        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');

        $xml = preg_replace('/<dimension[^>]*\/>/', '<dimension ref="' . $ref . '"/>', $xml, 1);

        $zip->deleteName('xl/worksheets/sheet1.xml');
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->close();

        return $destino;
    }

    /**
     * Red de seguridad sobre TODO el parque de fixtures existente: la regla nueva de
     * detección de encabezado tiene que seguir eligiendo la fila 1 en los archivos que ya
     * andaban.
     *
     * Es barato y es lo que detecta de una que alguien tocó la regla de §1.3 y se llevó
     * puesto el caso normal. 🔴 Cuando se toque esa regla —y se tocó, para que el corte por
     * fila de datos dejara de dispararse con un CUIT al lado de la razón social—, ESTE es el
     * test que dice si el arreglo se llevó puesto el parque que ya andaba. No se afloja.
     *
     * (Son catorce entradas y no once: el prefijo "07_" aparece dos veces, y se sumaron
     * 12_tres_hojas.xlsx —hoja 0— y 15_una_sola_hoja.xlsx, que también tienen el encabezado
     * en la fila 1. Quedan afuera 13 y 14, que son justamente los que NO son el caso normal,
     * y 16, que es un .xls que no se puede leer.)
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
            ['12_tres_hojas.xlsx'],
            ['15_una_sola_hoja.xlsx'],
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
