<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\import\excel\CsvDeHoja;
use App\Http\Controllers\Helpers\import\excel\ExcelWorkbookReader;
use App\Http\Controllers\Helpers\import\excel\LecturaDeHoja;
use App\Http\Controllers\Helpers\import\excel\LecturaDeHojaCsv;

/**
 * El sidecar CSV de una hoja (misión `importacion-excel-motor-rapido`, 24/9/2026).
 *
 * Lo que protege: que leer una hoja a través del sidecar (CsvDeHoja + LecturaDeHojaCsv,
 * lo que ExcelWorkbookReader::abrir() devuelve cuando el volcado existe) dé EXACTAMENTE
 * lo mismo que leerla del XLSX con OpenSpout: las mismas filas, con las mismas claves de
 * iterador, la misma cantidad de celdas, y en cada celda el mismo VALOR y el mismo TIPO
 * (int, float, \DateTime, bool, string, vacía), con y sin preservar las filas vacías.
 *
 * Importa el tipo y no sólo el texto porque ExcelNumericFormatStats distingue una celda de
 * texto "2.500" de una numérica 2500 con is_string(): si el sidecar devolviera todo como
 * string, el aviso de "números con punto ambiguos" contaría celdas que el Excel ya tiene
 * como números.
 *
 * Y lo otro que protege: que la importación, al confirmar, use el sidecar que dejó el
 * análisis en vez de volver a leer el XLSX (era la conversión de 25 s por cada 30.000 filas
 * adentro del request HTTP).
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
class LecturaDesdeCsvSidecarTest extends ImportTestCase
{
    /** @var array archivos temporales a borrar al terminar cada test */
    protected $temporales = [];

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }

        $this->temporales = [];

        parent::tearDown();
    }

    /**
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
     * Copia del fixture en un directorio temporal, para que el sidecar no quede en
     * tests/Import/fixtures/. Registra la copia y sus tres archivos de sidecar para borrarlos.
     *
     * @param  string $archivo
     * @param  string $carpeta  destino; por defecto el temp del sistema
     * @return string ruta absoluta de la copia
     */
    protected function copia_temporal($archivo, $carpeta = null)
    {
        $carpeta = is_null($carpeta) ? sys_get_temp_dir() : $carpeta;
        $copia   = $carpeta . '/' . uniqid('sidecar_') . '_' . $archivo;

        copy($this->fixture($archivo), $copia);

        $this->registrar_para_borrar($copia);

        return $copia;
    }

    /**
     * @param  string $excel_path
     * @return void
     */
    protected function registrar_para_borrar($excel_path)
    {
        $this->temporales[] = $excel_path;

        foreach ([0, 1, 2] as $indice) {
            $this->temporales[] = CsvDeHoja::ruta_csv($excel_path, $indice);
            $this->temporales[] = CsvDeHoja::ruta_tipos($excel_path, $indice);
            $this->temporales[] = CsvDeHoja::ruta_meta($excel_path, $indice);
        }
    }

    /**
     * Vuelca una lectura entera a un array comparable: clave del iterador => lista de
     * celdas, cada celda como [tipo, valor]. Las fechas se comparan por su texto
     * 'Y-m-d H:i:s' y por la clase; el resto por gettype() y valor estricto.
     *
     * @param  LecturaDeHoja|LecturaDeHojaCsv $lectura
     * @return array
     */
    protected function volcar_lectura($lectura)
    {
        $filas = [];

        foreach ($lectura->filas() as $clave => $fila) {
            $celdas = [];

            foreach ($fila->getCells() as $celda) {
                $valor = $celda->getValue();

                if ($valor instanceof \DateTime) {
                    $celdas[] = ['DateTime', $valor->format('Y-m-d H:i:s')];
                } else {
                    $celdas[] = [gettype($valor), $valor];
                }
            }

            $filas[$clave] = $celdas;
        }

        $lectura->cerrar();

        return $filas;
    }

    /**
     * Fixtures que cubren cada tipo de celda que OpenSpout devuelve: texto, enteros y
     * flotantes reales (06: 7790001234567, 504346, 123123.34324, 10244.36), números como
     * texto con separadores y moneda (03, 06), celdas vacías y nulas (01), una fecha de
     * verdad (17, celda de fecha con formato, no texto), encabezados corridos con filas
     * vacías arriba (14), fusiones (13) y un libro de tres hojas leído por las tres (12).
     *
     * @return array
     */
    public function fixtures()
    {
        return [
            '01 codigos de proveedor'          => ['01_codigos_de_proveedor.xlsx', 0],
            '03 numeros y costos'              => ['03_numeros_y_costos.xlsx', 0],
            '06 incidente servian (numericos)' => ['06_incidente_servian.xlsx', 0],
            '12 tres hojas, hoja 0'            => ['12_tres_hojas.xlsx', 0],
            '12 tres hojas, hoja 1'            => ['12_tres_hojas.xlsx', 1],
            '12 tres hojas, hoja 2'            => ['12_tres_hojas.xlsx', 2],
            '13 cabecera fusionada'            => ['13_cabecera_fusionada.xlsx', 0],
            '14 encabezado corrido'            => ['14_encabezado_corrido.xlsx', 0],
            '17 lista real con fecha'          => ['17_lista_proveedor_real.xlsx', 0],
            '21 repetidos con variantes'       => ['21_pc_repetido_con_variantes.xlsx', 0],
        ];
    }

    /**
     * El corazón de la unidad: por cada fixture, la lectura vía sidecar es idéntica a la
     * lectura vía OpenSpout, fila por fila y celda por celda, en valor y en tipo, con
     * preservar_filas_vacias en true y en false.
     *
     * @dataProvider fixtures
     *
     * @param  string $archivo
     * @param  int    $indice
     * @return void
     */
    public function test_el_sidecar_devuelve_las_mismas_filas_celdas_valores_y_tipos_que_openspout($archivo, $indice)
    {
        $original = $this->fixture($archivo);
        $copia    = $this->copia_temporal($archivo);

        $meta = ExcelWorkbookReader::asegurar_csv($copia, $indice);

        $this->assertSame($indice, (int) $meta['indice']);
        $this->assertFileExists(CsvDeHoja::ruta_csv($copia, $indice));
        $this->assertFileExists(CsvDeHoja::ruta_tipos($copia, $indice));
        $this->assertFileExists(CsvDeHoja::ruta_meta($copia, $indice));

        foreach ([true, false] as $preservar) {
            $desde_xlsx = ExcelWorkbookReader::abrir($original, $indice, $preservar);
            $desde_csv  = ExcelWorkbookReader::abrir($copia, $indice, $preservar);

            /* Sin esto el test podría estar comparando OpenSpout contra OpenSpout. */
            $this->assertInstanceOf(LecturaDeHoja::class, $desde_xlsx, 'El fixture original no tiene que tener sidecar.');
            $this->assertInstanceOf(LecturaDeHojaCsv::class, $desde_csv, 'La copia con sidecar tiene que leerse desde el CSV.');

            $this->assertSame($desde_xlsx->nombre(), $desde_csv->nombre());

            $filas_xlsx = $this->volcar_lectura($desde_xlsx);
            $filas_csv  = $this->volcar_lectura($desde_csv);

            $this->assertNotEmpty($filas_xlsx, 'El fixture ' . $archivo . ' no tiene filas: el test no prueba nada.');

            $this->assertSame(
                $filas_xlsx,
                $filas_csv,
                $archivo . ' hoja ' . $indice . ' con preservar_filas_vacias=' . ($preservar ? 'true' : 'false')
                    . ': el sidecar no devuelve lo mismo que OpenSpout.'
            );
        }

        /* El meta también tiene que decir la verdad sobre lo que hay en el CSV. */
        $con_vacias = $this->volcar_lectura(ExcelWorkbookReader::abrir($copia, $indice, true));

        $this->assertSame(count($con_vacias), (int) $meta['filas_fisicas']);
    }

    /**
     * El .tipos guarda el tipo real de cada celda: las columnas numéricas de un fixture con
     * números de verdad vuelven como int/float, no como texto, y los códigos como texto.
     *
     * @return void
     */
    public function test_los_tipos_del_sidecar_distinguen_numeros_de_texto()
    {
        $copia = $this->copia_temporal('06_incidente_servian.xlsx');

        ExcelWorkbookReader::asegurar_csv($copia, 0);

        $tipos = file(CsvDeHoja::ruta_tipos($copia, 0), FILE_IGNORE_NEW_LINES);

        /* Encabezado: ocho textos. */
        $this->assertSame('ssssssss', $tipos[0]);

        $lectura = ExcelWorkbookReader::abrir($copia, 0, true);

        $this->assertInstanceOf(LecturaDeHojaCsv::class, $lectura);

        $encontro_int = false;
        $encontro_float = false;
        $encontro_texto_numerico = false;

        foreach ($lectura->filas() as $numero => $fila) {
            if ($numero === 1) {
                continue;
            }

            $celdas = $fila->getCells();

            /* Costo (columna 5) */
            $costo = $celdas[4]->getValue();

            if (is_int($costo)) {
                $encontro_int = true;
            } elseif (is_float($costo)) {
                $encontro_float = true;
            } elseif (is_string($costo) && $costo !== '') {
                $encontro_texto_numerico = true;
            }

            /* IVA (columna 8): 21.0 se escribió como número y vuelve como entero 21. */
            $this->assertSame(21, $celdas[7]->getValue());
        }

        $lectura->cerrar();

        $this->assertTrue($encontro_int, 'Ningún costo volvió como int (ej. 11500).');
        $this->assertTrue($encontro_float, 'Ningún costo volvió como float (ej. 10244.36).');
        $this->assertTrue($encontro_texto_numerico, 'Ningún costo volvió como texto (ej. "$ 37468,24").');
    }

    /**
     * Idempotencia por mtime: mientras el XLSX no cambie, asegurar_csv() no rehace el
     * sidecar; si el XLSX cambia, lo rehace.
     *
     * @return void
     */
    public function test_asegurar_csv_es_idempotente_y_se_rehace_si_el_xlsx_cambia()
    {
        $copia = $this->copia_temporal('01_codigos_de_proveedor.xlsx');

        $meta_1 = ExcelWorkbookReader::asegurar_csv($copia, 0);

        $ruta_csv = CsvDeHoja::ruta_csv($copia, 0);

        /* Marca visible: si el volcado se rehace, la marca desaparece. */
        file_put_contents($ruta_csv, "marca-de-idempotencia\n", FILE_APPEND);

        $meta_2 = ExcelWorkbookReader::asegurar_csv($copia, 0);

        $this->assertSame($meta_1, $meta_2);
        $this->assertStringContainsString('marca-de-idempotencia', file_get_contents($ruta_csv), 'asegurar_csv() rehizo un sidecar vigente.');

        /* El XLSX "cambió" (otro mtime, el que guarda el .json): el sidecar deja de valer. */
        touch($copia, (int) $meta_1['xlsx_mtime'] + 10);
        clearstatcache(true, $copia);

        $this->assertNull(CsvDeHoja::meta_vigente($copia, 0));

        $meta_3 = ExcelWorkbookReader::asegurar_csv($copia, 0);

        $this->assertSame((int) $meta_1['xlsx_mtime'] + 10, (int) $meta_3['xlsx_mtime']);
        $this->assertStringNotContainsString('marca-de-idempotencia', file_get_contents($ruta_csv), 'El sidecar viejo tenía que rehacerse.');
    }

    /**
     * Sin sidecar, abrir() sigue siendo OpenSpout, y con el sidecar de OTRA hoja también:
     * el swap es por hoja.
     *
     * @return void
     */
    public function test_sin_sidecar_de_esa_hoja_abrir_sigue_leyendo_el_xlsx()
    {
        $copia = $this->copia_temporal('12_tres_hojas.xlsx');

        $this->assertInstanceOf(LecturaDeHoja::class, $this->cerrar_y_devolver(ExcelWorkbookReader::abrir($copia, 1, true)));

        ExcelWorkbookReader::asegurar_csv($copia, 1);

        $this->assertInstanceOf(LecturaDeHojaCsv::class, $this->cerrar_y_devolver(ExcelWorkbookReader::abrir($copia, 1, true)));
        $this->assertInstanceOf(LecturaDeHoja::class, $this->cerrar_y_devolver(ExcelWorkbookReader::abrir($copia, 0, true)));
        $this->assertInstanceOf(LecturaDeHoja::class, $this->cerrar_y_devolver(ExcelWorkbookReader::abrir($copia, 2, true)));
    }

    /**
     * La importación reusa el sidecar que dejó el análisis: el CSV de imported_files es una
     * copia del sidecar, no una nueva lectura del XLSX.
     *
     * Se prueba alterando el sidecar a mano después del "análisis": si la importación
     * volviera a leer el XLSX, la alteración no aparecería en su CSV.
     *
     * @return void
     */
    public function test_la_importacion_reusa_el_sidecar_que_dejo_el_analisis()
    {
        $carpeta = storage_path('app/imported_files');

        if (!is_dir($carpeta)) {
            mkdir($carpeta, 0777, true);
        }

        $nombre  = uniqid('sidecar_import_') . '.xlsx';
        $destino = $carpeta . '/' . $nombre;

        copy($this->fixture('01_codigos_de_proveedor.xlsx'), $destino);
        $this->registrar_para_borrar($destino);

        /* Lo que hace RunExcelAnalysisJob al arrancar el análisis. */
        ExcelWorkbookReader::asegurar_csv($destino, 0);

        $ruta_csv = CsvDeHoja::ruta_csv($destino, 0);
        $contenido = file_get_contents($ruta_csv);

        $this->assertStringContainsString('PC-NUEVO', $contenido);

        /* La alteración: un código que sólo existe en el sidecar. */
        file_put_contents($ruta_csv, str_replace('PC-NUEVO', 'PC-SOLO-EN-SIDECAR', $contenido));

        $data = array_merge(
            [
                'archivo_excel_path' => 'imported_files/' . $nombre,
                'start_row'          => 2,
                'finish_row'         => 99999,
                'provider_id'        => null,
            ],
            self::config_por_defecto(),
            self::columnas()
        );

        $this->postJson('/api/article/excel/import', $data)->assertStatus(200);

        $csvs = glob($carpeta . '/' . pathinfo($nombre, PATHINFO_FILENAME) . '_*.csv');

        $this->assertCount(1, is_array($csvs) ? $csvs : [], 'La importación tenía que dejar exactamente un CSV.');

        $this->temporales[] = $csvs[0];

        $this->assertSame(
            md5_file($ruta_csv),
            md5_file($csvs[0]),
            'El CSV de la importación tiene que ser byte a byte el sidecar del análisis.'
        );

        /* Y el artículo creado sale del sidecar alterado, no del XLSX. */
        $this->assertNotNull(
            $this->articulos_creados()->firstWhere('provider_code', 'PC-SOLO-EN-SIDECAR'),
            'La importación releyó el XLSX en vez de usar el sidecar.'
        );
    }

    /**
     * Un Excel de UNA fila con finish_row alto (99999, lo que manda el modal con IA) importa
     * exactamente una fila y un lote: ultima_fila_con_contenido del sidecar es la que recorta
     * el rango, y una fila vacía al final (celdas sin contenido, como deja Excel a veces) no
     * cuenta como dato. Es el caso que otro constructor vio fallar con filas_procesadas = 7
     * cuando el sidecar valía sólo por mtime y dos importaciones del mismo segundo compartían
     * imported_files/import_<time()>.xlsx.
     *
     * @return void
     */
    public function test_un_excel_de_una_fila_con_finish_row_alto_importa_una_sola_fila()
    {
        $carpeta = storage_path('app/imported_files');

        if (!is_dir($carpeta)) {
            mkdir($carpeta, 0777, true);
        }

        $nombre  = uniqid('sidecar_una_fila_') . '.xlsx';
        $destino = $carpeta . '/' . $nombre;

        $writer = \OpenSpout\Writer\Common\Creator\WriterEntityFactory::createXLSXWriter();
        $writer->openToFile($destino);
        $writer->addRow(\OpenSpout\Writer\Common\Creator\WriterEntityFactory::createRowFromArray([
            'codigo_de_barras', 'sku', 'codigo_de_proveedor', 'nombre', 'costo', 'precio', 'stock', 'iva',
        ]));
        $writer->addRow(\OpenSpout\Writer\Common\Creator\WriterEntityFactory::createRowFromArray([
            null, null, 'PC-UNA-FILA', 'Articulo de una sola fila', 100.0, 200.0, 3.0, '21',
        ]));
        $writer->close();

        $this->registrar_para_borrar($destino);

        $meta = ExcelWorkbookReader::asegurar_csv($destino, 0);

        $this->assertSame(2, (int) $meta['filas_fisicas'], 'Encabezado más una fila de datos.');
        $this->assertSame(2, (int) $meta['ultima_fila_con_contenido']);

        $data = array_merge(
            [
                'archivo_excel_path' => 'imported_files/' . $nombre,
                'start_row'          => 2,
                'finish_row'         => 99999,
                'provider_id'        => null,
            ],
            self::config_por_defecto(),
            self::columnas()
        );

        $this->postJson('/api/article/excel/import', $data)->assertStatus(200);

        $import = \App\Models\ImportHistory::where('user_id', $this->tenant->id)->orderBy('id', 'DESC')->first();

        $this->assertNotNull($import);
        $this->assertInvariantesDeConteo($import);

        $this->assertSame(1, (int) $import->filas_procesadas, 'Una fila de datos tiene que ser una fila procesada.');
        $this->assertSame(1, (int) $import->total_chunks);
        $this->assertSame(1, (int) $import->created_models);

        $creado = $this->articulos_creados()->firstWhere('provider_code', 'PC-UNA-FILA');

        $this->assertNotNull($creado);
        $this->assertSame('Articulo de una sola fila', $creado->name);

        foreach (glob($carpeta . '/' . pathinfo($nombre, PATHINFO_FILENAME) . '_*.csv') ?: [] as $csv) {
            $this->temporales[] = $csv;
            $this->temporales[] = $csv . '.claves';
        }
    }

    /**
     * @param  LecturaDeHoja|LecturaDeHojaCsv $lectura
     * @return LecturaDeHoja|LecturaDeHojaCsv
     */
    protected function cerrar_y_devolver($lectura)
    {
        $lectura->cerrar();

        return $lectura;
    }
}
