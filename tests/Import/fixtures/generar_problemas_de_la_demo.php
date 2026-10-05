<?php

/**
 * Genera el fixture 31: el mensaje del resultado de la importación contra lo que de verdad le
 * pasó a cada fila.
 *
 * Se corre desde la raiz de empresa-api:
 *
 *     php tests/Import/fixtures/generar_problemas_de_la_demo.php
 *
 * POR QUE EXISTE (misión importacion-mensaje-de-problemas, 4/10/2026)
 * Importando `multimedia/captura/material/lista-proveedor-demo-con-problemas.xlsx` en demo2 (4.3.6)
 * el aviso decía "La importacion termino con 3 filas que no se pudieron procesar por codigos
 * duplicados o incompletos en el Excel", y era falso: las tres filas se habían importado (una sin
 * costo, una sin códigos, una sin código de barras). De todos los tipos de import_conflicts, el
 * único que seguro deja la fila afuera es 'ambiguo'; con el resto el dato se descarta y la fila
 * sigue, y que después cree o actualice algo depende del resto de la importación. Este fixture
 * tiene un caso de cada situación para que el mensaje no pueda volver a mezclarlas. Lo de "se
 * crea" de abajo vale con "Crear y actualizar"; con "Solo actualizar" las filas 2 a 6 no crean
 * nada y el mensaje tiene que ser el mismo:
 *
 *   F2 - PD-01 con código de barras nuevo, costo 1000 ............... se crea limpio
 *   F3 - PD-03, costo "consultar" Y precio "consultar" ............... 2 numero_invalido, UNA fila;
 *                                                                      se crea sin costo ni precio
 *   F4 - sin ningún código, nombre único, con costo .................. sin_identificador; se crea
 *                                                                      sin códigos
 *   F5 - código de barras "S/N", PD-05 ............................... placeholder_descartado; se
 *                                                                      crea con el de proveedor
 *   F6 - repite PD-01 y su código de barras .......................... fila_sobrescrita F2 -> F6
 *                                                                      (informativo, no cuenta)
 *   F7 - código de barras 7790007 (duplicado en base: A7 y A8 del
 *        seeder), costo "consultar" .................................. ambiguo + numero_invalido;
 *                                                                      la fila NO se importa
 *
 * Esperado sobre el archivo entero: conflicts_count = 6 (los dos de F3, F4, F5 y los dos de F7),
 * 1 fila no importada (F7) y 3 filas con datos para revisar (F3, F4 y F5; la F7 no cuenta acá
 * porque no se importó). Ver MensajeDeResultadoTest.
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

/*
 * Columnas en el orden de la cabecera:
 *   codigo_de_barras, sku, codigo_de_proveedor, nombre, costo, precio, stock_actual, iva
 */
$filas = [
    1 => $cabecera,
    2 => ['7793100000012', null, 'PD-01', 'PROBLEMAS DEMO PINZA UNIVERSAL',          1000.0,      null,        null, 21.0],
    3 => [null,            null, 'PD-03', 'PROBLEMAS DEMO NIVEL SIN COSTO',          'consultar', 'consultar', null, 21.0],
    4 => [null,            null, null,    'PROBLEMAS DEMO MECHA SIN CODIGOS',        1500.0,      null,        null, 21.0],
    5 => ['S/N',           null, 'PD-05', 'PROBLEMAS DEMO PINZA PICO DE LORO',       2000.0,      null,        null, 21.0],
    6 => ['7793100000012', null, 'PD-01', 'PROBLEMAS DEMO PINZA UNIVERSAL (REPOS.)', 1100.0,      null,        null, 21.0],
    7 => ['7790007',       null, null,    'PROBLEMAS DEMO CODIGO REPETIDO EN BASE',  'consultar', null,        null, 21.0],
];

$libro = new Spreadsheet();
$hoja  = $libro->getActiveSheet();
$hoja->setTitle('Lista Agosto');

foreach ($filas as $numero_fila => $valores) {

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

$nombre_archivo = '31_problemas_de_la_lista_de_la_demo.xlsx';

$writer = new Xlsx($libro);
$writer->save(__DIR__ . '/' . $nombre_archivo);
$libro->disconnectWorksheets();

echo str_pad($nombre_archivo, 44) . "F2 limpio, F3 dos numeros invalidos, F4 sin codigos, F5 S/N, F6 sobrescribe a F2, F7 ambiguo\n";
