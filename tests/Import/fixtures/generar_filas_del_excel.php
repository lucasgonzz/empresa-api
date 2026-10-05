<?php

/**
 * Genera los fixtures 26 y 27: números de fila de los conflictos contra la fila REAL del Excel.
 *
 * Se corre desde la raiz de empresa-api:
 *
 *     php tests/Import/fixtures/generar_filas_del_excel.php
 *
 * POR QUE EXISTEN (misión fila-sobrescrita-corrida, 4/10/2026)
 * Todos los fixtures anteriores tienen el encabezado en la fila 1 y ninguna fila vacía entre los
 * datos. Con esa forma, "fila del Excel − 1" (lo que ProcessRow reportaba en import_conflicts) y
 * el "índice de fila de datos" (lo que asertaban los tests) daban el mismo número, así que nadie
 * veía el corrimiento. En demo2, con la lista de la demo (encabezado en la fila 7), el modal de
 * resultado decía "La fila 8 fue sobrescrita por la fila 15" para las filas 9 y 16 del Excel.
 *
 *   26 - réplica de `multimedia/captura/material/lista-proveedor-demo.xlsx`, hoja "Lista Agosto":
 *        membrete en las filas 1 a 5, la 6 vacía, encabezado en la 7 y datos de la 8 a la 17, con
 *        los mismos repetidos (FR-1002 en las filas 9 y 16, FR-1003 en las 10 y 14). Columnas en el
 *        orden fijo de ImportTestCase::columnas(), no en el de la lista real.
 *   27 - encabezado en la fila 3 y DOS filas vacías (6 y 7) en el medio de los datos, con un
 *        repetido que las cruza (fila 5 y fila 9) y un costo no numérico después de ellas
 *        (fila 8). ArticleImport saltea las filas vacías sin pasarlas por ProcessRow, y antes
 *        cada una corría un lugar todos los números que venían después.
 *
 * Mismo criterio de tipos que generar_hojas_y_fusiones.php:
 *
 *   string de PHP  -> celda de TEXTO (setCellValueExplicit, TYPE_STRING)
 *   int|float      -> celda NUMERICA
 *   null           -> celda que no se escribe (VACIA en el XML, como en un Excel real)
 *
 * Una fila sin ninguna celda escrita no existe en el XML: es exactamente como Excel guarda una
 * fila vacía, y es lo que el lector convierte en una línea vacía del CSV.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promocion de constructor, readonly, enum ni #[...].
 */

require __DIR__ . '/../../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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

/**
 * Escribe una fila en la hoja respetando el tipo de cada valor.
 *
 * @param  \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $hoja
 * @param  int   $numero_fila  1-based
 * @param  array $valores      string => texto, int|float => numero, null => celda vacia
 * @return void
 */
function escribir_fila_del_excel($hoja, $numero_fila, array $valores)
{
    $columna = 1;

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

/**
 * Arma un libro de una hoja con las filas indicadas, cada una en su número de fila exacto.
 *
 * @param  string $nombre_archivo
 * @param  string $nombre_hoja
 * @param  array  $filas  [numero_fila => valores]; los números que no están quedan vacíos
 * @param  string $detalle
 * @return void
 */
function guardar_filas_del_excel($nombre_archivo, $nombre_hoja, array $filas, $detalle)
{
    $libro = new Spreadsheet();
    $hoja  = $libro->getActiveSheet();
    $hoja->setTitle($nombre_hoja);

    foreach ($filas as $numero_fila => $valores) {
        escribir_fila_del_excel($hoja, $numero_fila, $valores);
    }

    $writer = new Xlsx($libro);
    $writer->save(__DIR__ . '/' . $nombre_archivo);
    $libro->disconnectWorksheets();

    echo str_pad($nombre_archivo, 44) . $detalle . "\n";
}

/* --------------------------------------------------------------------------
 * 26 - La lista de la demo: encabezado en la fila 7, repetidos en 9/16 y 10/14.
 * ------------------------------------------------------------------------ */

guardar_filas_del_excel('26_repetidos_con_encabezado_corrido.xlsx', 'Lista Agosto', [
    1  => ['LISTA DE PRECIOS - AGOSTO 2026'],
    2  => ['Distribuidora Bianchi S.A.', null, 'CUIT:', '30-71234567-9'],
    3  => ['Vigencia desde:', null, '01/08/2026'],
    4  => ['Contacto: ventas@bianchi.com.ar', null, 'Tel: 341 456-7890'],
    5  => ['*** Precios sujetos a modificacion sin previo aviso ***'],
    /* 6 vacía */
    7  => $cabecera,
    8  => ['7791234500011', null, 'FR-1001', 'PINZA UNIVERSAL 8 MANGO AISLADO',          12450.50, null, 5.0, 21.0],
    9  => ['7791234500028', null, 'FR-1002', 'DESTORNILLADOR PHILLIPS 6x150',            3890.75,  null, 5.0, 21.0],
    10 => ['7791234500035', null, 'FR-1003', 'LLAVE AJUSTABLE 10 CROMO VANADIO',         18200.00, null, 5.0, 21.0],
    11 => ['7791234500042', null, 'FR-1004', 'MARTILLO CARPINTERO 27mm MANGO MADERA',    9750.25,  null, 5.0, 21.0],
    12 => ['7791234500059', null, 'FR-1005', 'CINTA METRICA 5m x 19mm CARCASA ABS',      4320.00,  null, 5.0, 21.0],
    13 => ['7791234500066', null, 'FR-1006', 'NIVEL DE ALUMINIO 60cm 3 BURBUJAS',        15680.00, null, 5.0, 21.0],
    14 => ['7791234500035', null, 'FR-1003', 'LLAVE AJUSTABLE 10 (REPOSICION)',          18200.00, null, 5.0, 21.0],
    15 => ['7791234500073', null, 'FR-1007', 'ALICATE DE CORTE DIAGONAL 6',              7140.55,  null, 5.0, 21.0],
    16 => ['7791234500028', null, 'FR-1002', 'DESTORNILLADOR PHILLIPS 6x150 (BLISTER)',  3890.75,  null, 5.0, 21.0],
    17 => ['7791234500080', null, 'FR-1008', 'ARCO DE SIERRA 12 TENSOR RAPIDO',          11900.40, null, 5.0, 21.0],
], 'encabezado en la fila 7, datos 8 a 17, repetidos 9/16 y 10/14');

/* --------------------------------------------------------------------------
 * 27 - Filas vacías en el medio: el número de fila no puede correrse.
 * ------------------------------------------------------------------------ */

guardar_filas_del_excel('27_filas_vacias_entre_los_datos.xlsx', 'Lista', [
    1  => ['LISTA CON HUECOS'],
    /* 2 vacía */
    3  => $cabecera,
    4  => ['7792000000011', null, 'EX-01', 'PRIMERO',                  100.0, null, 1.0, 21.0],
    5  => ['7792000000028', null, 'EX-02', 'SEGUNDO',                  200.0, null, 1.0, 21.0],
    /* 6 y 7 vacías */
    8  => ['7792000000035', null, 'EX-03', 'COSTO NO NUMERICO',        'consultar', null, 1.0, 21.0],
    9  => ['7792000000028', null, 'EX-02', 'SEGUNDO (REPOSICION)',     210.0, null, 1.0, 21.0],
    10 => ['7792000000042', null, 'EX-04', 'CUARTO',                   400.0, null, 1.0, 21.0],
], 'encabezado en la fila 3, filas 6 y 7 vacias, repetido 5/9, costo invalido en la 8');
