<?php

/**
 * Genera el fixture 43: lista de proveedor con la fórmula del precio de venta, descuentos negativos
 * encadenados con «+», IVA escrito como texto con comilla y códigos de barras repetidos
 * (misión importacion-formula-precio, 20/11/2026). Reproduce, en chico, la lista real de una
 * ferretería que originó la misión.
 *
 * Se corre desde la raíz de empresa-api:
 *
 *     php tests/Import/fixtures/generar_formula_de_precio.php
 *
 * Lo usa tests/Import/FormulaDePrecio/AnalisisDePrecioTest.php.
 *
 * Columnas (1-based, como las mapean los tests de ArticleController@import):
 *   1 A Codigobarra          texto
 *   2 B Código               texto (código de proveedor)
 *   3 C Descripción          texto
 *   4 D Importe Base         número (costo antes de descuentos)
 *   5 E Descuentos           TEXTO con signo y «+» («-25+-30»)
 *   6 F Importe Compra       número (D con descuentos: columna intermedia)
 *   7 G Beneficio            número (margen %)
 *   8 H Eq.C                 número (unidades individuales del bulto de compra)
 *   9 I Iva.V                TEXTO («'21 %» con la comilla adentro, «10,5 %»)
 *  10 J Import. TOTAL        número (precio de venta final)
 *
 * Import. TOTAL = D × descuentos ÷ H × (1 + G) × (1 + IVA), con los descuentos leídos con signo
 * (−25 baja 25 %, +5 sube 5 %).
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */

require __DIR__ . '/../../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$libro = new Spreadsheet();
$hoja  = $libro->getActiveSheet();
$hoja->setTitle('Articulos');

$encabezados = ['Codigobarra', 'Código', 'Descripción', 'Importe Base', 'Descuentos', 'Importe Compra', 'Beneficio', 'Eq.C', 'Iva.V', 'Import. TOTAL'];
foreach ($encabezados as $indice => $texto) {
    $hoja->setCellValueExplicitByColumnAndRow($indice + 1, 1, $texto, DataType::TYPE_STRING);
}

/*
 * [código de barras, código de proveedor, nombre, base, descuentos, beneficio, eq.c, iva]
 *  - FP-01: el caso de la fila 6 de la lista real (dos descuentos negativos con «+»), IVA con comilla.
 *  - 0054 / 0054_1: mismo código de barras «54», códigos de proveedor distintos (el pack y la unidad).
 *  - FP-04 / FP-05: «111111111» repetido con códigos de proveedor distintos; FP-04 trae un recargo («+5»).
 *  - FP-06: sin descuentos.
 */
$filas = [
    ['7790101',   'FP-01',  'Esmalte sintetico 1 L',  1000, '-25+-30', 30, 1,  "'21 %"],
    ['54',        '0054',   'Arandela grower x mil',  2000, '-10',     50, 20, '21 %'],
    ['54',        '0054_1', 'Arandela grower x uni',  30,   '-10',     50, 1,  '21 %'],
    ['111111111', 'FP-04',  'Tornillo autoperforante', 500, '-20+5',   40, 1,  '10,5 %'],
    ['111111111', 'FP-05',  'Tuerca hexagonal',       800,  '-33',     30, 1,  '21 %'],
    ['7790107',   'FP-06',  'Cinta aisladora',        120,  '',        25, 1,  '21 %'],
];

foreach ($filas as $i => $fila) {
    $numero = $i + 2;
    list($barras, $proveedor, $nombre, $base, $descuentos, $beneficio, $unidades, $iva) = $fila;

    // Multiplicador de los descuentos leídos con signo (−25 baja 25 %, +5 sube 5 %).
    $multiplicador = 1.0;
    foreach (preg_split('/\+/', $descuentos) as $parte) {
        if ($parte !== '') {
            $multiplicador *= 1 + ((float) $parte) / 100;
        }
    }
    $alicuota = (float) str_replace([',', '%', "'", ' '], ['.', '', '', ''], $iva);
    $compra = $base * $multiplicador;
    $total = $compra / $unidades * (1 + $beneficio / 100) * (1 + $alicuota / 100);

    $hoja->setCellValueExplicitByColumnAndRow(1, $numero, $barras, DataType::TYPE_STRING);
    $hoja->setCellValueExplicitByColumnAndRow(2, $numero, $proveedor, DataType::TYPE_STRING);
    $hoja->setCellValueExplicitByColumnAndRow(3, $numero, $nombre, DataType::TYPE_STRING);
    $hoja->setCellValueByColumnAndRow(4, $numero, $base);
    if ($descuentos !== '') {
        $hoja->setCellValueExplicitByColumnAndRow(5, $numero, $descuentos, DataType::TYPE_STRING);
    }
    $hoja->setCellValueByColumnAndRow(6, $numero, round($compra, 6));
    $hoja->setCellValueByColumnAndRow(7, $numero, $beneficio);
    $hoja->setCellValueByColumnAndRow(8, $numero, $unidades);
    $hoja->setCellValueExplicitByColumnAndRow(9, $numero, $iva, DataType::TYPE_STRING);
    $hoja->setCellValueByColumnAndRow(10, $numero, round($total, 6));
}

$destino = __DIR__ . '/43_formula_de_precio.xlsx';
(new Xlsx($libro))->save($destino);

echo 'Generado ' . $destino . PHP_EOL;

/*
 * Fixture 44: costos en celdas NUMÉRICAS con decimales, que el CSV intermedio escribía «74.984» y
 * el parseo leía como 74984. Cabecera común de los tests de importación (8 columnas:
 * bar, sku, código de proveedor, nombre, costo, precio, stock, iva).
 *   TD-01  costo numérico 74.984        (3 decimales: el caso del cliente)
 *   TD-02  costo numérico 0.746
 *   TD-03  costo numérico 141388.5429   (4 decimales: 'siempre_miles' lo rompía)
 *   TD-04  costo TEXTO «2.500»          (texto argentino: sigue la interpretación elegida)
 *   TD-05  costo numérico 2500          (entero: no cambia)
 */
$libro = new Spreadsheet();
$hoja  = $libro->getActiveSheet();
$hoja->setTitle('Hoja1');

foreach (['codigo_barras', 'sku', 'codigo_proveedor', 'nombre', 'costo', 'precio', 'stock', 'iva'] as $indice => $texto) {
    $hoja->setCellValueExplicitByColumnAndRow($indice + 1, 1, $texto, DataType::TYPE_STRING);
}

$costos = [
    ['TD-01', 'Tres decimales',   74.984],
    ['TD-02', 'Menor que uno',    0.746],
    ['TD-03', 'Cuatro decimales', 141388.5429],
    ['TD-04', 'Texto con punto',  '2.500'],
    ['TD-05', 'Entero',           2500],
];

foreach ($costos as $i => $fila) {
    $numero = $i + 2;
    $hoja->setCellValueExplicitByColumnAndRow(3, $numero, $fila[0], DataType::TYPE_STRING);
    $hoja->setCellValueExplicitByColumnAndRow(4, $numero, $fila[1], DataType::TYPE_STRING);
    if (is_string($fila[2])) {
        $hoja->setCellValueExplicitByColumnAndRow(5, $numero, $fila[2], DataType::TYPE_STRING);
    } else {
        $hoja->setCellValueByColumnAndRow(5, $numero, $fila[2]);
    }
}

$destino = __DIR__ . '/44_costos_numericos_con_decimales.xlsx';
(new Xlsx($libro))->save($destino);

echo 'Generado ' . $destino . PHP_EOL;
