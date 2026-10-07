<?php

/**
 * Genera el fixture 32: la columna "visible en la tienda para la lista X" de la importación.
 *
 * Se corre desde la raiz de empresa-api:
 *
 *     php tests/Import/fixtures/generar_visible_en_tienda.php
 *
 * POR QUE EXISTE (misión catalogo-por-lista-tienda, 5/10/2026)
 * Ningún fixture anterior trae una columna Sí/No por lista. Este trae las ocho columnas fijas de
 * ImportTestCase::columnas() y dos más:
 *
 *   9  - visible_mayorista  (se mapea como `prop_visible_en_tienda_mayorista`)
 *   10 - margen_mayorista   (se mapea como `prop_%_mayorista`, solo en el test que lo pide)
 *
 * Filas (los artículos existentes son los que siembra ImportTestSeeder, con los MISMOS datos, para
 * que lo único que cambie sea lo de las listas):
 *
 *   2 - A1  existente, "Si"            → el caso central: solo cambia la visibilidad
 *   3 - A2  existente, "No"
 *   4 - A12 existente, celda vacía     → no informado: no se toca
 *   5 - A15 existente, "SÍ" y margen 25 → cambian la visibilidad y el margen
 *   6 - nuevo VIS-1, "sí"
 *   7 - nuevo VIS-2, "no"
 *   8 - nuevo VIS-3, celda vacía
 *
 * Mismo criterio de tipos que generar_filas_del_excel.php:
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

/** Cabecera: las ocho de generar.php (ver ImportTestCase::columnas()) y las dos de las listas. */
$cabecera = [
    'codigo_de_barras',
    'sku',
    'codigo_de_proveedor',
    'nombre',
    'costo',
    'precio',
    'stock_actual',
    'iva',
    'visible_mayorista',
    'margen_mayorista',
];

/** Filas de datos, desde la fila 2 del Excel. */
$filas = [
    ['7790001',    'SKU-001',   'PC-100',   'Art unico prov A',  100.0,  null, 10.0,  21.0, 'Si', null],
    ['7790002',    'SKU-002',   'PC-200',   'Art unico prov B',  200.0,  null, 20.0,  21.0, 'No', null],
    ['7790012',    'SKU-012',   'PC-1200',  'Art sin proveedor', 1200.0, null, 120.0, 21.0, null, null],
    ['7790015',    'SKU-015',   'PC-1500',  'Art solo prov C',   1500.0, null, 150.0, 21.0, 'SÍ', 25.0],
    ['7799900001', 'SKU-VIS-1', 'PC-VIS-1', 'Nuevo visible si',  50.0,   null, 1.0,   21.0, 'sí', null],
    ['7799900002', 'SKU-VIS-2', 'PC-VIS-2', 'Nuevo visible no',  60.0,   null, 1.0,   21.0, 'no', null],
    ['7799900003', 'SKU-VIS-3', 'PC-VIS-3', 'Nuevo sin dato',    70.0,   null, 1.0,   21.0, null, null],
];

$libro = new Spreadsheet();
$hoja  = $libro->getActiveSheet();
$hoja->setTitle('Lista');

$todas = array_merge([$cabecera], $filas);

foreach ($todas as $indice => $valores) {

    // Número de fila del Excel (1-based): la cabecera es la 1.
    $numero_fila = $indice + 1;
    $columna     = 1;

    foreach ($valores as $valor) {
        if (!is_null($valor)) {
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

$writer = new Xlsx($libro);
$writer->save(__DIR__ . '/32_visible_en_tienda.xlsx');
$libro->disconnectWorksheets();

echo str_pad('32_visible_en_tienda.xlsx', 44) . "7 filas: 4 existentes y 3 nuevas, con visible_mayorista y margen_mayorista\n";
