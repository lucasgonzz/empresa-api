<?php

namespace Tests\Feature\IaApp;

use App\Http\Controllers\Helpers\asistente_ia\ArchivosAdjuntosIaHelper;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;
use Tests\TestCase;

/**
 * Misión app-ia-y-memoria — los lectores de adjuntos no cargan el archivo entero (corrección de la
 * revisión, 29/10/2026).
 *
 * El texto de un PDF, un Excel o un CSV se saca en el worker de la cola, que comparte la memoria con las
 * importaciones, y un "Out of memory" no se puede atrapar: mata el job y el dueño ve un error. Lo que
 * protege este archivo:
 *   1. 🔴 Un .xlsx de 50.000 renglones se lee en streaming: el pico de memoria del proceso que lo lee
 *      queda muy por debajo de lo que costaría cargarlo entero (se mide en un PROCESO APARTE, porque el
 *      pico de PHP no se puede reiniciar y el de este proceso ya trae los tests anteriores).
 *   2. Los topes cortan la LECTURA: 3 hojas, 200 filas, 30 columnas; un CSV largo igual; un PDF largo
 *      hasta MAX_PAGINAS_PDF páginas, con el aviso.
 */
class Topes_de_lectura_de_adjuntos_Test extends TestCase
{
    /** Techo del pico de memoria del proceso que lee el .xlsx grande. Cargado entero pasa de 300 MB. */
    const TECHO_DE_MEMORIA = 48 * 1024 * 1024;

    /** @var array<int, string> */
    protected $temporales = [];

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }

        parent::tearDown();
    }

    /**
     * Una ruta temporal con su extensión, que se borra al terminar.
     *
     * @param  string  $extension
     * @return string
     */
    protected function temporal($extension)
    {
        $ruta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ia-app-' . uniqid() . '.' . $extension;

        $this->temporales[] = $ruta;

        return $ruta;
    }

    /**
     * Lee el archivo en un proceso PHP aparte y devuelve el texto y el pico de memoria de ese proceso.
     *
     * @param  string  $ruta
     * @param  string  $tipo
     * @return array{texto: string, pico: int}
     */
    protected function leer_en_otro_proceso($ruta, $tipo)
    {
        $script = $this->temporal('php');

        file_put_contents($script, '<?php
require $argv[1];
$texto = \\App\\Http\\Controllers\\Helpers\\asistente_ia\\ArchivosAdjuntosIaHelper::texto_de_un_archivo($argv[2], $argv[3]);
echo json_encode(["pico" => memory_get_peak_usage(true), "texto" => $texto]);
');

        $comando = escapeshellarg(PHP_BINARY) . ' -d memory_limit=1024M -d xdebug.mode=off '
            . escapeshellarg($script) . ' '
            . escapeshellarg(base_path('vendor/autoload.php')) . ' '
            . escapeshellarg($ruta) . ' ' . escapeshellarg($tipo);

        $salida = shell_exec($comando);
        $datos  = json_decode((string) $salida, true);

        $this->assertIsArray($datos, 'El proceso que lee el archivo no terminó bien: ' . mb_substr((string) $salida, 0, 500));

        return ['texto' => (string) $datos['texto'], 'pico' => (int) $datos['pico']];
    }

    public function test_un_xlsx_de_50000_renglones_se_lee_en_streaming_y_con_los_topes()
    {
        $ruta = $this->temporal('xlsx');

        $escritor = WriterEntityFactory::createXLSXWriter();
        $escritor->openToFile($ruta);

        $escritor->addRow(WriterEntityFactory::createRowFromArray(array_map(function ($i) {
            return 'Columna ' . $i;
        }, range(1, 35))));

        for ($i = 1; $i <= 50000; $i++) {
            $escritor->addRow(WriterEntityFactory::createRowFromArray(array_merge(
                ['Fila ' . $i . ' ', 'Artículo número ' . $i, $i * 1.5],
                array_fill(0, 32, 'relleno ' . $i)
            )));
        }

        foreach (['Segunda', 'Tercera', 'Cuarta'] as $nombre) {
            $escritor->addNewSheetAndMakeItCurrent()->setName($nombre);
            $escritor->addRow(WriterEntityFactory::createRowFromArray(['Hoja ' . $nombre]));
        }

        $escritor->close();

        $leido = $this->leer_en_otro_proceso($ruta, 'xlsx');

        $this->assertLessThan(self::TECHO_DE_MEMORIA, $leido['pico'],
            'El pico fue de ' . round($leido['pico'] / 1048576, 1) . ' MB: la hoja se está cargando entera.');

        $texto = $leido['texto'];

        $this->assertStringContainsString('(se leyeron las primeras 200 filas)', $texto);
        $this->assertStringContainsString('Fila 199 ', $texto, 'El encabezado es la fila 1: entran hasta la 199 de datos.');
        $this->assertStringNotContainsString('Fila 200 ', $texto);
        $this->assertStringNotContainsString('Columna 31', $texto, 'Hasta 30 columnas.');
        $this->assertStringContainsString('Columna 30', $texto);
        $this->assertStringContainsString('Hoja «Tercera»', $texto);
        $this->assertStringNotContainsString('Hoja Cuarta', $texto, 'Hasta 3 hojas.');
        $this->assertStringContainsString('[La planilla tiene más hojas', $texto);
    }

    public function test_un_csv_largo_se_lee_en_streaming_hasta_200_filas()
    {
        $ruta = $this->temporal('csv');

        $flujo = fopen($ruta, 'w');
        fwrite($flujo, "\xEF\xBB\xBF" . "codigo;articulo\n");

        for ($i = 1; $i <= 10000; $i++) {
            fwrite($flujo, 'C' . $i . ';Artículo ' . $i . "\n");
        }

        fclose($flujo);

        $texto = ArchivosAdjuntosIaHelper::texto_de_un_archivo($ruta, 'csv');

        $this->assertStringStartsWith('codigo | articulo', $texto, 'Sin el BOM.');
        $this->assertStringContainsString('C199 | Artículo 199', $texto);
        $this->assertStringNotContainsString('C200 |', $texto);
        $this->assertStringContainsString('[El archivo tiene más filas', $texto);
    }

    public function test_un_pdf_largo_se_lee_hasta_el_tope_de_paginas()
    {
        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetFont('helvetica', '', 12);

        for ($i = 1; $i <= ArchivosAdjuntosIaHelper::MAX_PAGINAS_PDF + 5; $i++) {
            $pdf->AddPage();
            $pdf->Write(0, 'Pagina numero ' . $i . ' de la lista');
        }

        $ruta = $this->temporal('pdf');
        file_put_contents($ruta, $pdf->Output('', 'S'));

        $texto = ArchivosAdjuntosIaHelper::texto_de_un_archivo($ruta, 'pdf');

        $this->assertStringContainsString('Pagina numero ' . ArchivosAdjuntosIaHelper::MAX_PAGINAS_PDF . ' de', $texto);
        $this->assertStringNotContainsString('Pagina numero ' . (ArchivosAdjuntosIaHelper::MAX_PAGINAS_PDF + 1) . ' de', $texto);
        $this->assertStringContainsString('se leyeron las primeras ' . ArchivosAdjuntosIaHelper::MAX_PAGINAS_PDF, $texto);
    }
}
