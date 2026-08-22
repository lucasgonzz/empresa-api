<?php

/**
 * Genera los fixtures 12 a 16: varias hojas, cabecera fusionada, encabezado corrido,
 * libro de una sola hoja y un .xls viejo.
 *
 * Se corre desde la raiz de empresa-api:
 *
 *     php tests/Import/fixtures/generar_hojas_y_fusiones.php
 *
 * POR QUE ESTE SCRIPT ESTA SEPARADO DE generar.php
 * `generar.php` escribe con OpenSpout y es la documentacion viva de los fixtures 01 a 11.
 * OpenSpout NO sabe escribir celdas fusionadas ni el formato .xls viejo (BIFF), que es
 * justamente lo que estos cinco fixtures necesitan. Mezclar los dos generadores en un solo
 * archivo obligaria a cambiar la libreria de los once fixtures viejos y arruinaria el valor
 * de `generar.php` como documentacion de lo que ya funciona. Por eso: dos generadores, cada
 * uno con su libreria, y ninguno toca los archivos del otro.
 *
 * Igual que `generar.php`, este script es la documentacion viva de sus fixtures: dice que
 * hay en cada celda y, sobre todo, DE QUE TIPO es cada celda.
 *
 *   setCellValueExplicit($ref, '7790101', DataType::TYPE_STRING)  -> celda de TEXTO
 *   setCellValue($ref, 111.0)                                     -> celda NUMERICA
 *   celda que no se escribe                                       -> celda VACIA
 *
 * El tipo decide el comportamiento del parseo: un codigo de barras escrito como numero
 * vuelve como float y saltea todo el parseo de texto. En PhpSpreadsheet hay que ser
 * EXPLICITO: setCellValue('7790101') adivina y lo guarda como numero.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promocion de constructor, readonly, enum ni #[...].
 */

require __DIR__ . '/../../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
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
function escribir_fila($hoja, $numero_fila, array $valores)
{
    $columna = 1;

    foreach ($valores as $valor) {
        if ($valor === null) {
            /* No se escribe la celda: asi queda vacia en el XML, como en un Excel real. */
            $columna++;

            continue;
        }

        $ref = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columna) . $numero_fila;

        if (is_string($valor)) {
            $hoja->setCellValueExplicit($ref, $valor, DataType::TYPE_STRING);
        } else {
            $hoja->setCellValue($ref, $valor);
        }

        $columna++;
    }
}

/**
 * Guarda el libro y avisa por consola.
 *
 * @param  \PhpOffice\PhpSpreadsheet\Spreadsheet $libro
 * @param  string $nombre_archivo
 * @param  string $detalle
 * @return void
 */
function guardar($libro, $nombre_archivo, $detalle)
{
    $ruta = __DIR__ . '/' . $nombre_archivo;

    if (substr($nombre_archivo, -4) === '.xls') {
        $writer = new Xls($libro);
    } else {
        $writer = new Xlsx($libro);
    }

    $writer->save($ruta);
    $libro->disconnectWorksheets();

    echo str_pad($nombre_archivo, 32) . $detalle . "\n";
}

/* --------------------------------------------------------------------------
 * 15 - Un libro de UNA SOLA HOJA, celda por celda igual que
 * 01_codigos_de_proveedor.xlsx.
 *
 * Es el fixture de NO REGRESION, el mas importante de la tanda: prueba que un libro
 * normal (una hoja, encabezado en la fila 1) se sigue leyendo exactamente igual que
 * antes de existir todo este namespace. Que este escrito con PhpSpreadsheet y el 01 con
 * OpenSpout es a proposito: si los dos se leen identico, la lectura no depende de quien
 * escribio el archivo.
 *
 * Hoja "Hoja1": fila 1 = cabecera comun (8 columnas de texto),
 * filas 2 a 8 = las 7 filas de datos del 01, con los mismos tipos.
 * -------------------------------------------------------------------------- */
$libro = new Spreadsheet();
$hoja  = $libro->getActiveSheet();
$hoja->setTitle('Hoja1');

escribir_fila($hoja, 1, $cabecera);

$datos_01 = [
    [null, 'SKU-NUEVO-UNICO', 'PC-100',   'Art unico prov A EDITADO',  111.0,  222.0, 11.0, '21'],
    [null, 'SKU-NUEVO-DUP',   'PC-DUP',   'Art PC repetido EDITADO',   333.0,  444.0, 33.0, '21'],
    [null, null,              'PC-CROSS', 'Art PC cruzado EDITADO',    555.0,  666.0, 55.0, '21'],
    [null, null,              'PC-NUEVO', 'Articulo nuevo por PC',     777.0,  888.0, 77.0, '21'],
    [null, null,              'PC-1500',  'Art solo prov C EDITADO',   999.0, 1110.0, 99.0, '21'],
    [null, null,              'S/N',      'Placeholder SN sin codigo', 100.0,  200.0,  5.0, '21'],
    [null, null,              '-',        'Placeholder guion sin cod', 120.0,  240.0,  6.0, '21'],
];

$numero_fila = 2;

foreach ($datos_01 as $fila) {
    escribir_fila($hoja, $numero_fila, $fila);
    $numero_fila++;
}

guardar($libro, '15_una_sola_hoja.xlsx', '1 hoja, cabecera fila 1, 7 filas de datos');

/* --------------------------------------------------------------------------
 * 12 - Tres hojas.
 *
 * Hoja 0 "Lista de precios": cabecera comun + 7 filas de datos = 8 filas.
 * Hoja 1 "Notas":            3 filas de TEXTO LIBRE, sin forma de tabla de articulos.
 * Hoja 2 "Resumen":          2 filas.
 *
 * "Notas" no tiene forma de tabla A PROPOSITO: si el sistema elige la hoja equivocada,
 * tiene que notarse. Con tres hojas todas parecidas, importar la que no era pasa
 * desapercibido, que es exactamente el defecto que este fixture viene a pinchar.
 *
 * Las cantidades de filas (8 / 3 / 2) son lo que el selector de hoja del paso 1 le
 * muestra al usuario, y por eso el test las asierta una por una.
 * -------------------------------------------------------------------------- */
$libro = new Spreadsheet();

$hoja = $libro->getActiveSheet();
$hoja->setTitle('Lista de precios');

escribir_fila($hoja, 1, $cabecera);

$numero_fila = 2;

foreach ($datos_01 as $fila) {
    escribir_fila($hoja, $numero_fila, $fila);
    $numero_fila++;
}

$notas = $libro->createSheet();
$notas->setTitle('Notas');

escribir_fila($notas, 1, ['Estas son notas internas del vendedor']);
escribir_fila($notas, 2, ['Los precios de la lista no incluyen IVA']);
escribir_fila($notas, 3, ['Actualizado por Marcela el 12 de agosto']);

$resumen = $libro->createSheet();
$resumen->setTitle('Resumen');

escribir_fila($resumen, 1, ['Resumen de la lista']);
escribir_fila($resumen, 2, ['Total de articulos', 7.0]);

guardar($libro, '12_tres_hojas.xlsx', '3 hojas: 8 / 3 / 2 filas');

/* --------------------------------------------------------------------------
 * 13 - Cabecera fusionada sobre dos columnas.
 *
 * Una hoja. Fila 1 = cabecera de 6 columnas, con E1:F1 FUSIONADAS y "PRECIOS" en E1.
 * F1 queda VACIA en el XML (Excel no escribe la celda cubierta por una fusion): sin
 * propagar la fusion, la columna F llega al mapeo sin nombre.
 *
 * Filas 2 a 5 = 4 filas de datos con E (costo) y F (precio) LLENAS. Ese es el caso caro:
 * dos propiedades del sistema distintas debajo de un unico nombre de columna. Si el mapeo
 * les da el mismo indice, costo y precio salen de la misma celda y el catalogo del cliente
 * queda mal sin un solo error en pantalla.
 * -------------------------------------------------------------------------- */
$libro = new Spreadsheet();
$hoja  = $libro->getActiveSheet();
$hoja->setTitle('Lista');

escribir_fila($hoja, 1, [
    'codigo_de_barras',
    'sku',
    'codigo_de_proveedor',
    'nombre',
    'PRECIOS',
    null,          // F1 vacia: la cubre la fusion E1:F1
]);

$hoja->mergeCells('E1:F1');

escribir_fila($hoja, 2, ['7799401', 'SKU-F-1', 'PC-F-1', 'Articulo fusion 1', 100.0, 200.0]);
escribir_fila($hoja, 3, ['7799402', 'SKU-F-2', 'PC-F-2', 'Articulo fusion 2', 300.0, 600.0]);
escribir_fila($hoja, 4, ['7799403', 'SKU-F-3', 'PC-F-3', 'Articulo fusion 3', 500.0, 900.0]);
escribir_fila($hoja, 5, ['7799404', 'SKU-F-4', 'PC-F-4', 'Articulo fusion 4', 700.0, 1400.0]);

guardar($libro, '13_cabecera_fusionada.xlsx', '1 hoja, E1:F1 fusionada, 4 filas de datos');

/* --------------------------------------------------------------------------
 * 14 - Encabezado corrido (el defecto 3).
 *
 * Una hoja, tal como sale de la imprenta de cualquier distribuidora:
 *
 *   F1  A1 = "LISTA DE PRECIOS AGOSTO 2026"   (titulo, una sola celda)
 *   F2  A2 = "Distribuidora Bianchi S.A."     (razon social, una sola celda)
 *   F3  vacia
 *   F4  cabecera comun de 8 columnas          <- EL ENCABEZADO DE VERDAD
 *   F5-F9  5 filas de datos
 *
 * La regla vieja tomaba la F1 como encabezado: mapeaba las 8 columnas contra una sola
 * celda de titulo y contaba titulo y razon social como filas de datos. Los tres numeros
 * que se le muestran al usuario (columnas detectadas, filas a importar, fila de inicio)
 * salian mal a la vez, y ninguno con un error.
 *
 * Las 5 filas de datos son lo que hace que count_data_rows() tenga que dar 5 y no 8.
 * -------------------------------------------------------------------------- */
$libro = new Spreadsheet();
$hoja  = $libro->getActiveSheet();
$hoja->setTitle('Lista');

escribir_fila($hoja, 1, ['LISTA DE PRECIOS AGOSTO 2026']);
escribir_fila($hoja, 2, ['Distribuidora Bianchi S.A.']);
/* La fila 3 se deja vacia a proposito: no se escribe ninguna celda. */
escribir_fila($hoja, 4, $cabecera);

escribir_fila($hoja, 5, ['7799501', 'SKU-C-1', 'PC-C-1', 'Articulo corrido 1', 100.0, 200.0, 10.0, '21']);
escribir_fila($hoja, 6, ['7799502', 'SKU-C-2', 'PC-C-2', 'Articulo corrido 2', 200.0, 400.0, 20.0, '21']);
escribir_fila($hoja, 7, ['7799503', 'SKU-C-3', 'PC-C-3', 'Articulo corrido 3', 300.0, 600.0, 30.0, '21']);
escribir_fila($hoja, 8, ['7799504', 'SKU-C-4', 'PC-C-4', 'Articulo corrido 4', 400.0, 800.0, 40.0, '21']);
escribir_fila($hoja, 9, ['7799505', 'SKU-C-5', 'PC-C-5', 'Articulo corrido 5', 500.0, 1000.0, 50.0, '21']);

guardar($libro, '14_encabezado_corrido.xlsx', '1 hoja, encabezado en la fila 4, 5 filas de datos');

/* --------------------------------------------------------------------------
 * 16 - Un .xls VIEJO de verdad (BIFF, no es un zip).
 *
 * Se escribe con el writer Xls de PhpSpreadsheet, no renombrando un .xlsx: el punto del
 * fixture es que NO sea un zip, para que ZipArchive::open() falle de verdad y el usuario
 * reciba el mensaje limpio en vez de la ruta absoluta del servidor.
 *
 * Una hoja, cabecera + 2 filas de datos. El contenido no importa: nadie lo va a leer.
 * -------------------------------------------------------------------------- */
$libro = new Spreadsheet();
$hoja  = $libro->getActiveSheet();
$hoja->setTitle('Hoja1');

escribir_fila($hoja, 1, $cabecera);
escribir_fila($hoja, 2, ['7799601', 'SKU-V-1', 'PC-V-1', 'Articulo viejo 1', 100.0, 200.0, 10.0, '21']);
escribir_fila($hoja, 3, ['7799602', 'SKU-V-2', 'PC-V-2', 'Articulo viejo 2', 200.0, 400.0, 20.0, '21']);

guardar($libro, '16_viejo.xls', '1 hoja BIFF, cabecera + 2 filas');

echo "\nListo.\n";
