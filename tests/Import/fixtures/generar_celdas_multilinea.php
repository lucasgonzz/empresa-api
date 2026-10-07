<?php

/**
 * Genera los fixtures 28, 29 y 30: celdas con salto de línea (Alt+Enter) en una importación de
 * varios lotes.
 *
 * Se corre desde la raiz de empresa-api:
 *
 *     php tests/Import/fixtures/generar_celdas_multilinea.php
 *
 * POR QUE EXISTEN (misión importacion-celda-multilinea, 4/10/2026)
 * El volcado XLSX -> CSV (CsvDeHoja::volcar(), writer CSV de OpenSpout) escribe UN REGISTRO CSV
 * por fila del Excel, pero una celda con salto de línea sale entrecomillada y ocupa VARIAS
 * líneas físicas del archivo. InitExcelImport::build_csv_chunk_offsets() contaba líneas
 * físicas (fgets) para guardar el byte de inicio de cada lote, mientras que
 * ProcessArticleChunk::get_row_from_csv() cuenta registros (fgetcsv): con N saltos de línea
 * antes de un lote, ese lote arrancaba N filas antes, leía filas dos veces y las últimas N no
 * se leían nunca. Ningún fixture anterior tenía una celda multilínea, y el único de varios
 * lotes (06_incidente_servian.xlsx) no tiene ninguna.
 *
 *   28 - cabecera común en la fila 1 y datos de la 2 a la 26 (25 filas, códigos únicos). El
 *        nombre de la fila 5 tiene UN salto y el de la fila 8 tiene DOS: son tres líneas
 *        físicas de más antes del lote 2. Costo no numérico ("consultar") en la fila 15
 *        (lote 2) y en la 24 (lote 3). Con lotes de 10: [2-11], [12-21], [22-26].
 *        El nombre de la fila 3 TERMINA EN BARRA INVERTIDA a propósito: es la guarda del escape
 *        vacío de build_csv_chunk_offsets(). Con el escape por defecto de fgetcsv() (la barra),
 *        esa celda entrecomillada no cierra y se come los registros siguientes, así que un
 *        arreglo que contara registros con fgetcsv($h) a secas volvería a correr los lotes
 *        (lo encontró el chequeo independiente: sin esta celda, ese arreglo a medias pasaba).
 *   30 - SIN encabezado (start_row = 1) y la PRIMERA celda (A1) multilínea. Columnas propias:
 *        nombre, codigo_de_proveedor, costo, iva. Datos de la 1 a la 12. Con lotes de 10:
 *        [1-10], [11-12]. Es el lote que arranca en la fila 1: si su offset fuera el byte 0, el
 *        BOM quedaría pegado a la comilla de A1, fgetcsv() partiría ese registro en dos y la
 *        fila 10 no la leería nadie.
 *   29 - el encabezado (fila 1) con saltos de línea en DOS celdas, que es el caso más común en
 *        las listas de proveedor ("Precio\nsin IVA"), y datos de la 2 a la 13. Con start_row = 2
 *        el lote 1 SIEMPRE tiene offset, y con el conteo por líneas físicas caía en el medio del
 *        registro del encabezado. Con lotes de 10: [2-11], [12-13].
 *        Una de las dos celdas es la PRIMERA (A1) a propósito: el CSV arranca con el BOM pegado
 *        a esa celda entrecomillada, y fgetcsv() leyendo desde el byte 0 no la reconoce como
 *        entrecomillada (corta el registro en el primer salto de línea; medido con el PHP 7.4 de
 *        esta máquina). Un arreglo que contara registros sin saltear el BOM seguiría corrido
 *        con este encabezado.
 *
 * El salto es "\n", que es lo que Excel guarda cuando se aprieta Alt+Enter en una celda. Las
 * celdas multilínea llevan además "ajustar texto" (wrap text), como quedan en un Excel real
 * cuando alguien escribe así: no cambia lo que se lee, pero el fixture se parece al original.
 *
 * Mismo criterio de tipos que generar_hojas_y_fusiones.php:
 *
 *   string de PHP  -> celda de TEXTO (setCellValueExplicit, TYPE_STRING)
 *   int|float      -> celda NUMERICA
 *   null           -> celda que no se escribe (VACIA en el XML, como en un Excel real)
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promocion de constructor, readonly, enum ni #[...].
 */

require __DIR__ . '/../../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Escribe una fila en la hoja respetando el tipo de cada valor. Una celda de texto con un salto
 * de línea adentro queda además con "ajustar texto", como la deja Excel.
 *
 * @param  \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $hoja
 * @param  int   $numero_fila  1-based
 * @param  array $valores      string => texto, int|float => numero, null => celda vacia
 * @return void
 */
function escribir_fila_multilinea($hoja, $numero_fila, array $valores)
{
    $columna = 1;

    foreach ($valores as $valor) {
        if (!is_null($valor)) {
            $ref = Coordinate::stringFromColumnIndex($columna) . $numero_fila;

            if (is_string($valor)) {
                $hoja->setCellValueExplicit($ref, $valor, DataType::TYPE_STRING);

                if (strpos($valor, "\n") !== false) {
                    $hoja->getStyle($ref)->getAlignment()->setWrapText(true);
                }
            } else {
                $hoja->setCellValue($ref, $valor);
            }
        }

        $columna++;
    }
}

/**
 * Arma un libro de una hoja con las filas indicadas, cada una en su número de fila exacto.
 *
 * @param  string $nombre_archivo
 * @param  array  $filas    [numero_fila => valores]
 * @param  string $detalle
 * @return void
 */
function guardar_libro_multilinea($nombre_archivo, array $filas, $detalle)
{
    $libro = new Spreadsheet();
    $hoja  = $libro->getActiveSheet();
    $hoja->setTitle('Lista');

    foreach ($filas as $numero_fila => $valores) {
        escribir_fila_multilinea($hoja, $numero_fila, $valores);
    }

    $writer = new Xlsx($libro);
    $writer->save(__DIR__ . '/' . $nombre_archivo);
    $libro->disconnectWorksheets();

    echo str_pad($nombre_archivo, 44) . $detalle . "\n";
}

/** Cabecera comun, la misma de generar.php (ver ImportTestCase::columnas()). */
$cabecera = [
    'codigo_de_barras',
    'sku',
    'codigo_de_proveedor',
    'nombre',
    'costo',
    'precio',
    'stock_actual',
    'iva',
];

/* --------------------------------------------------------------------------
 * 28 - Celdas multilínea en los datos: 3 líneas físicas de más antes del lote 2.
 *
 * Fila r: bar_code 77942800000rr, provider_code ML-rr, costo 1000 + r. El costo distinto por
 * fila es lo que permite ver que cada artículo quedó con el de SU fila.
 * ------------------------------------------------------------------------ */

$nombres_multilinea_28 = [
    /* No es multilínea: termina en barra invertida (ver el docblock del archivo). */
    3 => 'PERFIL ALUMINIO 20x20 \\',
    5 => "TORNILLO AUTOPERFORANTE 8x1\nCAJA X 100",
    8 => "MECHA ACERO RAPIDO 8mm\nPARA METAL\nBLISTER X 2",
];

$costos_no_numericos_28 = [15, 24];

$filas_28 = [1 => $cabecera];

for ($fila = 2; $fila <= 26; $fila++) {
    $rr = str_pad((string) $fila, 2, '0', STR_PAD_LEFT);

    $filas_28[$fila] = [
        '77942800000' . $rr,
        null,
        'ML-' . $rr,
        isset($nombres_multilinea_28[$fila]) ? $nombres_multilinea_28[$fila] : 'ARTICULO MULTILINEA FILA ' . $rr,
        in_array($fila, $costos_no_numericos_28, true) ? 'consultar' : (float) (1000 + $fila),
        null,
        null,
        21.0,
    ];
}

guardar_libro_multilinea(
    '28_celda_multilinea_en_los_datos.xlsx',
    $filas_28,
    'datos 2 a 26, barra final en la 3, un salto en la 5 y dos en la 8, costo invalido en 15 y 24'
);

/* --------------------------------------------------------------------------
 * 29 - Encabezado multilínea: el offset del lote 1 caía en el medio del encabezado.
 *
 * Fila r: bar_code 77942900000rr, provider_code EM-rr, costo 2000 + r.
 * ------------------------------------------------------------------------ */

$filas_29 = [
    1 => [
        "codigo\nde barras",
        'sku',
        'codigo_de_proveedor',
        'nombre',
        "costo\nsin IVA",
        'precio',
        'stock_actual',
        'iva',
    ],
];

for ($fila = 2; $fila <= 13; $fila++) {
    $rr = str_pad((string) $fila, 2, '0', STR_PAD_LEFT);

    $filas_29[$fila] = [
        '77942900000' . $rr,
        null,
        'EM-' . $rr,
        'ARTICULO ENCABEZADO FILA ' . $rr,
        (float) (2000 + $fila),
        null,
        null,
        21.0,
    ];
}

guardar_libro_multilinea(
    '29_encabezado_multilinea.xlsx',
    $filas_29,
    'encabezado con saltos en A1 ("codigo") y E1 ("costo"), datos 2 a 13'
);

/* --------------------------------------------------------------------------
 * 30 - Sin encabezado y A1 multilínea: el lote que arranca en la fila 1.
 *
 * Fila r: nombre "ARTICULO SIN ENCABEZADO FILA rr", provider_code SE-rr, costo 3000 + r.
 * Columnas: A nombre, B codigo_de_proveedor, C costo, D iva (el test las mapea así).
 * ------------------------------------------------------------------------ */

$filas_30 = [];

for ($fila = 1; $fila <= 12; $fila++) {
    $rr = str_pad((string) $fila, 2, '0', STR_PAD_LEFT);

    $filas_30[$fila] = [
        $fila === 1 ? "LISTA SIN ENCABEZADO\nARTICULO SIN ENCABEZADO FILA 01" : 'ARTICULO SIN ENCABEZADO FILA ' . $rr,
        'SE-' . $rr,
        (float) (3000 + $fila),
        21.0,
    ];
}

guardar_libro_multilinea(
    '30_sin_encabezado_a1_multilinea.xlsx',
    $filas_30,
    'sin encabezado, A1 con un salto, datos 1 a 12'
);
