<?php

namespace Tests\Unit;

use App\Http\Controllers\Helpers\import\excel\CsvDeHoja;
use App\Http\Controllers\Helpers\import\excel\LecturaDeHoja;
use App\Http\Controllers\Helpers\import\excel\LecturaDeHojaCsv;
use App\Http\Controllers\Helpers\import\excel\OpenSpoutCompatible;
use OpenSpout\Common\Entity\Row;
use PHPUnit\Framework\TestCase;

class OpenSpoutCompatibleTest extends TestCase
{
    public function test_cerrar_libera_la_hoja_aunque_falle_el_cierre_del_libro()
    {
        $ruta = tempnam(sys_get_temp_dir(), 'cierre_error_');
        $writer = OpenSpoutCompatible::createXLSXWriter();
        $writer->openToFile($ruta);
        $writer->addRow(OpenSpoutCompatible::createRowFromArray(['Primera']));
        $writer->addRow(OpenSpoutCompatible::createRowFromArray(['Segunda']));
        $writer->close();
        $reader = OpenSpoutCompatible::createXLSXReader();
        $reader->open($ruta);
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $lector_fallido = new class {
                    public function close() { throw new \RuntimeException('Falla de cierre simulada'); }
                };
                $lectura = new LecturaDeHoja($lector_fallido, $sheet, $ruta);
                $iterador = $lectura->filas();
                $iterador->rewind();
                $propiedad = new \ReflectionProperty(LecturaDeHoja::class, 'lector_formulas');
                $propiedad->setAccessible(true);
                $xml = $propiedad->getValue($lectura);
                try {
                    $lectura->cerrar();
                    $this->fail('El error de cierre debe seguir visible.');
                } catch (\RuntimeException $e) {
                    $this->assertSame('Falla de cierre simulada', $e->getMessage());
                }
                $lectura->cerrar();
                $this->assertNull($propiedad->getValue($lectura));
                if ($xml !== null) {
                    try {
                        $cerrado = !@$xml->read();
                    } catch (\Throwable $e) {
                        $cerrado = true;
                    }
                    $this->assertTrue($cerrado);
                }
                unset($iterador);
                break;
            }
        } finally {
            $reader->close();
            unlink($ruta);
        }
    }

    public function test_cerrar_libera_el_xml_auxiliar_aunque_el_iterador_quede_retenido()
    {
        $ruta = tempnam(sys_get_temp_dir(), 'cierre_');
        $writer = OpenSpoutCompatible::createXLSXWriter();
        $writer->openToFile($ruta);
        $writer->addRow(OpenSpoutCompatible::createRowFromArray(['Primera']));
        $writer->addRow(OpenSpoutCompatible::createRowFromArray(['Segunda']));
        $writer->close();
        try {
            $reader = OpenSpoutCompatible::createXLSXReader();
            $reader->open($ruta);
            foreach ($reader->getSheetIterator() as $sheet) {
                $lectura = new LecturaDeHoja($reader, $sheet, $ruta);
                $iterador = $lectura->filas();
                $iterador->rewind();
                $this->assertSame('Primera', $iterador->current()->toArray()[0]);
                $propiedad = new \ReflectionProperty(LecturaDeHoja::class, 'lector_formulas');
                $propiedad->setAccessible(true);
                $xml = $propiedad->getValue($lectura);
                $lectura->cerrar();
                $lectura->cerrar();
                if (OpenSpoutCompatible::es_version_cuatro()) {
                    $this->assertInstanceOf(\XMLReader::class, $xml);
                    try {
                        $cerrado = !@$xml->read();
                    } catch (\Throwable $e) {
                        $cerrado = true;
                    }
                    $this->assertTrue($cerrado);
                }
                $this->assertNull($propiedad->getValue($lectura));
                unset($iterador);
                break;
            }
        } finally {
            unlink($ruta);
        }
    }

    public function test_fechas_numericas_y_duraciones_con_ambas_bases_del_calendario_excel()
    {
        foreach ([false, true] as $calendario_1904) {
            $ruta = tempnam(sys_get_temp_dir(), 'fechas_');
            $writer = OpenSpoutCompatible::createXLSXWriter();
            $writer->openToFile($ruta);
            foreach ([[0.5, 'yyyy-mm-dd hh:mm:ss'], [1.5, '[h]:mm:ss'], [-0.5, '[h]:mm:ss']] as $dato) {
                $estilo = new \OpenSpout\Common\Entity\Style\Style();
                $estilo->setFormat($dato[1]);
                $writer->addRow(new Row([OpenSpoutCompatible::createCell($dato[0], $estilo)], null));
            }
            $writer->close();
            if ($calendario_1904) {
                $zip = new \ZipArchive();
                $zip->open($ruta);
                $xml = $zip->getFromName('xl/workbook.xml');
                $xml = preg_replace('/<workbookPr\b[^>]*\/>/', '', $xml);
                $xml = str_replace('<sheets>', '<workbookPr date1904="1"/><sheets>', $xml);
                $zip->addFromString('xl/workbook.xml', $xml);
                $zip->close();
            }

            try {
                $reader = OpenSpoutCompatible::createXLSXReader(true);
                $reader->open($ruta);
                foreach ($reader->getSheetIterator() as $sheet) {
                    $lectura = new LecturaDeHoja($reader, $sheet, $ruta);
                    try {
                        $filas = iterator_to_array($lectura->filas());
                        $esperadas = $calendario_1904
                            ? ['1904-01-01 12:00:00', '1904-01-02 12:00:00', '1903-12-31 12:00:00']
                            : ['1899-12-30 12:00:00', '1899-12-31 12:00:00', '1899-12-29 12:00:00'];
                        foreach ($esperadas as $indice => $esperada) {
                            $valor = $filas[$indice + 1]->toArray()[0];
                            $this->assertInstanceOf(\DateTime::class, $valor);
                            $this->assertSame($esperada, $valor->format(CsvDeHoja::FORMATO_FECHA));
                        }
                    } finally {
                        $lectura->cerrar();
                    }
                    break;
                }
            } finally {
                unlink($ruta);
            }
        }
    }

    public function test_volcado_csv_relee_tipos_y_texto_multilinea_con_el_writer_instalado()
    {
        $ruta = tempnam(sys_get_temp_dir(), 'volcado_');
        $fecha = new \DateTime('2026-10-21 12:34:56');
        $valores = [0, 0.5, false, true, null, '', '=ABC', "Dos\nlíneas", 'C:\\Carpeta\\', $fecha];
        $lectura = new class($valores) extends LecturaDeHoja {
            private $valores;
            public function __construct($valores) { $this->valores = $valores; }
            public function nombre() { return 'Datos'; }
            public function filas() {
                $celdas = array_map(function ($valor) {
                    return OpenSpoutCompatible::celda_literal($valor);
                }, $this->valores);
                yield 1 => new Row($celdas, null);
                yield 2 => new Row([], null);
            }
        };
        $log_anterior = \Illuminate\Support\Facades\Log::getFacadeRoot();
        \Illuminate\Support\Facades\Log::swap(new \Psr\Log\NullLogger());
        try {
            $meta = CsvDeHoja::volcar($ruta, 0, $lectura);
            $this->assertSame(2, $meta['filas_fisicas']);
            $this->assertSame(1, $meta['ultima_fila_con_contenido']);
            $csv = new LecturaDeHojaCsv(CsvDeHoja::ruta_csv($ruta, 0), CsvDeHoja::ruta_tipos($ruta, 0), $meta);
            try {
                $filas = iterator_to_array($csv->filas());
                $esperados = $valores;
                $esperados[4] = '';
                $this->assertEquals($esperados, $filas[1]->toArray());
                $this->assertSame([0, 0.5, false, true, '', '', '=ABC', "Dos\nlíneas", 'C:\\Carpeta\\'], array_slice($filas[1]->toArray(), 0, 9));
                $this->assertSame([], $filas[2]->toArray());
            } finally {
                $csv->cerrar();
            }
        } finally {
            if ($log_anterior === null) {
                \Illuminate\Support\Facades\Log::clearResolvedInstance('log');
            } else {
                \Illuminate\Support\Facades\Log::swap($log_anterior);
            }
            foreach ([$ruta, CsvDeHoja::ruta_csv($ruta, 0), CsvDeHoja::ruta_tipos($ruta, 0), CsvDeHoja::ruta_meta($ruta, 0)] as $archivo) {
                @unlink($archivo);
            }
        }
    }

    public function test_conserva_tipos_y_textos_literales_sin_tratarlos_como_formulas()
    {
        $fecha = new \DateTime('2026-10-21 12:34:56');
        $valores = [0, 0.0, '', null, false, true, '0', ' ', '=ABC', $fecha];

        foreach ($valores as $valor) {
            $celda = OpenSpoutCompatible::celda_literal($valor);
            $this->assertSame($valor, $celda->getValue());
            $this->assertSame($valor === null || $valor === '', OpenSpoutCompatible::celda_vacia($celda));
        }

        $this->assertSame(CsvDeHoja::TIPO_FECHA, CsvDeHoja::tipo_de(new \DateTimeImmutable('2026-10-21')));
    }

    public function test_sidecar_conserva_ceros_booleanos_fechas_y_filas_vacias()
    {
        $csv = tempnam(sys_get_temp_dir(), 'csv_');
        $tipos = tempnam(sys_get_temp_dir(), 'tipos_');
        file_put_contents($csv, CsvDeHoja::BOM_UTF8 . "0,0.5,0,,1,2026-10-21 12:34:56,=ABC\n\n\"\"\n");
        file_put_contents($tipos, "ifsbbds\n\ne\n");

        try {
            foreach ([true, false] as $preservar) {
                $lector = new LecturaDeHojaCsv($csv, $tipos, ['nombre_hoja' => 'Datos'], $preservar);
                try {
                    $filas = iterator_to_array($lector->filas());
                    $this->assertCount($preservar ? 3 : 1, $filas);
                    $valores = $filas[1]->toArray();
                    $this->assertSame([0, 0.5, '0', false, true], array_slice($valores, 0, 5));
                    $this->assertInstanceOf(\DateTime::class, $valores[5]);
                    $this->assertSame('2026-10-21 12:34:56', $valores[5]->format(CsvDeHoja::FORMATO_FECHA));
                    $this->assertSame('=ABC', $valores[6]);
                    if ($preservar) {
                        $this->assertSame([], $filas[2]->toArray());
                        $this->assertSame([''], $filas[3]->toArray());
                    }
                } finally {
                    $lector->cerrar();
                }
            }
        } finally {
            unlink($csv);
            unlink($tipos);
        }
    }

    public function test_lector_xlsx_conserva_huecos_valores_cacheados_y_fecha_mutable()
    {
        $ruta = tempnam(sys_get_temp_dir(), 'xlsx_');
        $writer = OpenSpoutCompatible::createXLSXWriter();
        $writer->openToFile($ruta);
        $writer->addRow(new Row([OpenSpoutCompatible::celda_literal('=ABC')], null));
        $writer->addRow(OpenSpoutCompatible::createRowFromArray(['']));
        $writer->addRow(OpenSpoutCompatible::createRowFromArray([0, false]));
        $writer->close();

        $zip = new \ZipArchive();
        $zip->open($ruta);
        $hoja = 'xl/worksheets/sheet1.xml';
        $xml = $zip->getFromName($hoja);
        $xml = str_replace('</sheetData>', '<row r="4"><c r="A4"><f>1+2</f><v>3</v></c></row><row r="5"><c r="A5" t="d"><v>2026-10-21T12:34:56Z</v></c></row><row r="6"><c r="A6" t="str"><f>IF(1=1,&quot;&quot;,&quot;X&quot;)</f><v></v></c></row><row r="7"><c r="A7" t="e"><f>1/0</f><v>#DIV/0!</v></c></row><row r="8"><c r="A8"><f>2+2</f></c></row></sheetData>', $xml);
        $zip->addFromString($hoja, $xml);
        $zip->close();

        try {
            foreach ([true, false] as $preservar) {
                $reader = OpenSpoutCompatible::createXLSXReader(OpenSpoutCompatible::es_version_cuatro() ? true : $preservar);
                $reader->open($ruta);
                foreach ($reader->getSheetIterator() as $sheet) {
                    $lectura = new LecturaDeHoja($reader, $sheet, $ruta, $preservar);
                    try {
                        $filas = iterator_to_array($lectura->filas());
                        $this->assertCount($preservar ? 8 : 5, $filas);
                        $this->assertSame('=ABC', $filas[1]->toArray()[0]);
                        $this->assertSame([0, false], $filas[$preservar ? 3 : 2]->toArray());
                        $this->assertSame(3, $filas[$preservar ? 4 : 3]->toArray()[0]);
                        $this->assertInstanceOf(\DateTime::class, $filas[$preservar ? 5 : 4]->toArray()[0]);
                        if (!$preservar) {
                            $this->assertSame(null, $filas[5]->toArray()[0]);
                        }
                        if ($preservar) {
                            $this->assertSame('', $filas[6]->toArray()[0]);
                            $this->assertSame(null, $filas[7]->toArray()[0]);
                            $this->assertSame('', $filas[8]->toArray()[0]);
                        }
                    } finally {
                        $lectura->cerrar();
                    }
                    break;
                }
            }
        } finally {
            unlink($ruta);
        }
    }
}
