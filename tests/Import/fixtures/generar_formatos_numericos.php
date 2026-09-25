<?php

/**
 * Genera el fixture 25: columnas numericas con celdas de TEXTO en todos los formatos de
 * separadores que el importador tiene que saber leer (o rechazar).
 *
 * Se corre desde la raiz de empresa-api:
 *
 *     php tests/Import/fixtures/generar_formatos_numericos.php
 *
 * Lo usa FormatosNumericosLecturasTest para ExcelNumericFormatStats::analyze().
 *
 * Igual que generar_hojas_y_fusiones.php, este script es la documentacion viva del fixture:
 * dice que hay en cada celda y, sobre todo, DE QUE TIPO es.
 *
 *   setCellValueExplicit($ref, '12,5', DataType::TYPE_STRING)  -> celda de TEXTO
 *   setCellValue($ref, 99.5)                                   -> celda NUMERICA de verdad
 *   celda que no se escribe                                    -> celda VACIA
 *
 * En PhpSpreadsheet hay que ser EXPLICITO: setCellValue('12,5') adivina y a veces lo guarda
 * como numero. Aca todo lo que dice "texto" se guarda como string de verdad.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promocion de constructor, readonly, enum ni #[...].
 */

require __DIR__ . '/../../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$libro = new Spreadsheet();
$hoja  = $libro->getActiveSheet();
$hoja->setTitle('Hoja1');

/* Espacio de no separacion (U+00A0), como lo escribe Excel en es-AR con formato de miles. */
$nbsp = "\xC2\xA0";

/*
 * Fila 1 = cabecera. Fila 2 en adelante = datos (fila real del Excel = numero de fila).
 * Columnas: A nombre, B costo, C precio, D stock (esta ultima solo con numeros nativos y
 * enteros de texto: no tiene que aparecer en 'lecturas' ni en 'columnas').
 *
 * Columna COSTO (B):
 *   fila  2  '12,5'         texto   -> coma_decimal                 12.5
 *   fila  3  '1.234,56'     texto   -> miles_punto_decimal_coma     1234.56
 *   fila  4  '1,234.56'     texto   -> miles_coma_decimal_punto     1234.56
 *   fila  5  '1,234,567'    texto   -> miles_coma                   1234567
 *   fila  6  '1 234,50'     texto   -> miles_espacio                1234.5
 *   fila  7  '1.5,3'        texto   -> no_interpretable             (error)
 *   fila  8  '2.500'        texto   -> solo punto: NO va a lecturas, si a 'columnas' (miles)
 *   fila  9  99.5           NUMERO  -> no pasa por el parseo
 *   fila 10  '$ 1.000,50'   texto   -> miles_punto_decimal_coma     1000.5
 *   fila 11  '1<NBSP>000'   texto   -> miles_espacio                1000
 *   fila 12  (vacia)
 *   fila 13  'abc'          texto   -> no_interpretable             (error)
 *
 * Columna PRECIO (C): solo punto, para probar que 'columnas' queda como siempre y que
 * 'lecturas' no la menciona.
 *   fila  2  '2.500'   texto   -> miles
 *   fila  3  '3330.95' texto   -> decimal
 *   fila  4  1500.0    NUMERO
 *   filas 5 a 13 vacias
 */
$filas = [
    1  => ['nombre', 'costo', 'precio', 'stock'],
    2  => ['Art 1', '12,5', '2.500', 10.0],
    3  => ['Art 2', '1.234,56', '3330.95', 20.0],
    4  => ['Art 3', '1,234.56', 1500.0, '30'],
    5  => ['Art 4', '1,234,567', null, 40.0],
    6  => ['Art 5', '1 234,50', null, 50.0],
    7  => ['Art 6', '1.5,3', null, 60.0],
    8  => ['Art 7', '2.500', null, 70.0],
    9  => ['Art 8', 99.5, null, 80.0],
    10 => ['Art 9', '$ 1.000,50', null, 90.0],
    11 => ['Art 10', '1' . $nbsp . '000', null, 100.0],
    12 => ['Art 11', null, null, 110.0],
    13 => ['Art 12', 'abc', null, 120.0],
];

foreach ($filas as $numero_fila => $valores) {
    $columna = 1;

    foreach ($valores as $valor) {
        if ($valor !== null) {
            $ref = Coordinate::stringFromColumnIndex($columna) . $numero_fila;

            if (is_string($valor)) {
                $hoja->setCellValueExplicit($ref, $valor, DataType::TYPE_STRING);
            } else {
                $hoja->setCellValue($ref, $valor);
            }
        }

        $columna++;
    }
}

$ruta = __DIR__ . '/25_formatos_numericos.xlsx';
(new Xlsx($libro))->save($ruta);
$libro->disconnectWorksheets();

echo str_pad('25_formatos_numericos.xlsx', 32) . "1 hoja, costo con texto en todos los formatos, precio solo punto\n";
echo "\nListo.\n";
