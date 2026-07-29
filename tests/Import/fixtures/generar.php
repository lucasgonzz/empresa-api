<?php

/**
 * Genera los archivos Excel de fixture de los tests de importacion.
 *
 * Se corre desde la raiz de empresa-api:
 *
 *     php tests/Import/fixtures/generar.php
 *
 * Este script es la documentacion viva de los fixtures: dice que hay en cada celda
 * y, sobre todo, DE QUE TIPO es cada celda. El tipo decide el comportamiento:
 *
 *   - string de PHP  -> celda de TEXTO   -> llega como string y pasa por parseNumericValue()
 *   - float/int      -> celda NUMERICA   -> llega como float y saltea todo el parseo
 *
 * Orden de columnas (FIJO). No cambiarlo sin actualizar ImportTestCase::columnas():
 *
 *   1 codigo_de_barras
 *   2 sku
 *   3 codigo_de_proveedor
 *   4 nombre
 *   5 costo
 *   6 precio
 *   7 stock_actual
 *   8 iva
 */

require __DIR__ . '/../../../vendor/autoload.php';

use OpenSpout\Writer\Common\Creator\WriterEntityFactory;

/** Cabecera comun a los cinco archivos. */
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
 * Escribe un archivo xlsx con la cabecera comun y las filas indicadas.
 *
 * @param  string $nombre_archivo
 * @param  array  $filas
 * @param  array  $cabecera
 * @return void
 */
function escribir($nombre_archivo, array $filas, array $cabecera)
{
    $ruta = __DIR__ . '/' . $nombre_archivo;

    $writer = WriterEntityFactory::createXLSXWriter();
    $writer->openToFile($ruta);

    $writer->addRow(WriterEntityFactory::createRowFromArray($cabecera));

    foreach ($filas as $fila) {
        $writer->addRow(WriterEntityFactory::createRowFromArray($fila));
    }

    $writer->close();

    echo str_pad($nombre_archivo, 40) . count($filas) . " filas de datos\n";
}

/* --------------------------------------------------------------------------
 * 01 - Codigos de proveedor: la matriz de configuraciones.
 *
 * Contra el escenario sembrado por ImportTestSeeder:
 *   PC-100   existe 1 vez en el proveedor A
 *   PC-DUP   existe 2 veces en el proveedor A
 *   PC-CROSS existe en B y en C, no en A
 *   PC-NUEVO no existe
 *   PC-1500  existe solo en C
 *   "S/N" y "-" son placeholders que IdentifierNormalizer descarta
 * -------------------------------------------------------------------------- */
escribir('01_codigos_de_proveedor.xlsx', [
    [null, null, 'PC-100',   'Art unico prov A EDITADO',  111.0,  222.0, 11.0, '21'],
    [null, null, 'PC-DUP',   'Art PC repetido EDITADO',   333.0,  444.0, 33.0, '21'],
    [null, null, 'PC-CROSS', 'Art PC cruzado EDITADO',    555.0,  666.0, 55.0, '21'],
    [null, null, 'PC-NUEVO', 'Articulo nuevo por PC',     777.0,  888.0, 77.0, '21'],
    [null, null, 'PC-1500',  'Art solo prov C EDITADO',   999.0, 1110.0, 99.0, '21'],
    [null, null, 'S/N',      'Placeholder SN sin codigo', 100.0,  200.0,  5.0, '21'],
    [null, null, '-',        'Placeholder guion sin cod', 120.0,  240.0,  6.0, '21'],
], $cabecera);

/* --------------------------------------------------------------------------
 * 02 - Codigos de barra repetidos.
 *
 *   7799001 NO existe en base y se repite 3 veces => tiene que quedar 1 SOLO articulo
 *   7790001 existe (A1) y se repite 2 veces       => tiene que actualizar 1 SOLO articulo
 *   7790007 existe DUPLICADO en base (A7 y A8)    => ambiguo, no se toca nada
 * -------------------------------------------------------------------------- */
escribir('02_codigos_de_barra_repetidos.xlsx', [
    ['7799001', null, 'PCB-1', 'Barcode repetido nuevo',     100.0, 150.0, 10.0, '21'],
    ['7799001', null, 'PCB-2', 'Barcode repetido nuevo v2',  200.0, 250.0, 20.0, '21'],
    ['7799001', null, 'PCB-3', 'Barcode repetido nuevo v3',  300.0, 350.0, 30.0, '21'],
    ['7790001', null, null,    'Art unico prov A por bc',    150.0, 300.0, 15.0, '21'],
    ['7790001', null, null,    'Art unico prov A por bc v2', 250.0, 400.0, 25.0, '21'],
    ['7790007', null, null,    'Barcode ambiguo en BD',      500.0, 600.0, 50.0, '21'],
    ['7790007', null, null,    'Barcode ambiguo en BD v2',   600.0, 700.0, 60.0, '21'],
], $cabecera);

/* --------------------------------------------------------------------------
 * 03 - Numeros y costos.
 *
 * Todos los provider_codes NUM-xx son inexistentes, asi que cada fila crea un
 * articulo nuevo y el costo resultante se lee directo de ese articulo.
 * Notar la mezcla deliberada de celdas de texto y una celda numerica (NUM-07).
 * -------------------------------------------------------------------------- */
escribir('03_numeros_y_costos.xlsx', [
    [null, null, 'NUM-01', 'Costo formato AR',       '1.234,56',      null, 1.0, '21'],
    [null, null, 'NUM-02', 'Costo formato US',       '12,345.67',     null, 1.0, '21'],
    [null, null, 'NUM-03', 'Costo con simbolo',      '$ 37.468,24',   null, 1.0, '21'],
    [null, null, 'NUM-04', 'Costo punto decimal',    '3330.95',       null, 1.0, '21'],
    [null, null, 'NUM-05', 'Costo punto miles',      '2.500',         null, 1.0, '21'],
    [null, null, 'NUM-06', 'Costo muchos decimales', '1234,5678912',  null, 1.0, '21'],
    [null, null, 'NUM-07', 'Costo numerico real',    8888.25,         null, 1.0, '21'],
    [null, null, 'NUM-08', 'Costo no numerico',      'consultar',     null, 1.0, '21'],
    [null, null, 'NUM-09', 'Costo punto corto',      '2.5',           null, 1.0, '21'],
    [null, null, 'NUM-10', 'Costo miles multiple',   '1.234.567,89',  null, 1.0, '21'],
    [null, null, 'NUM-11', 'Costo fuera de rango',   str_repeat('9', 46), null, 1.0, '21'],
    [null, null, 'NUM-12', 'Costo cero',             '0',             null, 1.0, '21'],
], $cabecera);

/* --------------------------------------------------------------------------
 * 04 - Stock. Se importa con provider_id = null.
 *
 *   PC-100     A1 stock 10 -> 25   (delta +15)
 *   PC-200     A2 stock 20 -> 5    (delta -15)
 *   PC-700     A7 stock 70 -> 70   (sin cambio, sin movimiento)
 *   PC-STK-NEW nuevo, stock "1.500" como TEXTO -> 1500
 *   PC-800     A8 costo 850, columna stock vacia -> stock intacto (80)
 *   PC-1200    A12 tiene provider_code pero provider_id null en base: NO se indexa
 * -------------------------------------------------------------------------- */
escribir('04_stock.xlsx', [
    [null, null, 'PC-100',     'Art unico prov A',         null,  null, 25.0,    '21'],
    [null, null, 'PC-200',     'Art unico prov B',         null,  null,  5.0,    '21'],
    [null, null, 'PC-700',     'Art bar code repetido 1',  null,  null, 70.0,    '21'],
    [null, null, 'PC-STK-NEW', 'Articulo nuevo con stock', 100.0, 200.0, '1.500', '21'],
    [null, null, 'PC-800',     'Art bar code repetido 2',  850.0, null, null,    '21'],
    [null, null, 'PC-1200',    'Art sin proveedor',        null,  null, 999.0,   '21'],
], $cabecera);

/* --------------------------------------------------------------------------
 * 05 - Rollback. Toca costo, precio, stock y nombre de tres articulos existentes
 * y crea dos nuevos, para que el snapshot tenga de donde agarrarse.
 * -------------------------------------------------------------------------- */
escribir('05_rollback.xlsx', [
    [null, null, 'PC-100',  'Art unico prov A ROLLBACK',   1111.0, 2222.0, 111.0, '21'],
    [null, null, 'PC-200',  'Art unico prov B ROLLBACK',   2222.0, 3333.0, 222.0, '21'],
    [null, null, 'PC-1200', 'Art sin proveedor ROLLBACK',  3333.0, 4444.0, 333.0, '21'],
    [null, null, 'PC-RB-1', 'Articulo creado en rollback',  444.0,  555.0,  44.0, '21'],
    [null, null, 'PC-RB-2', 'Otro creado en rollback',      555.0,  666.0,  55.0, '21'],
], $cabecera);

/* --------------------------------------------------------------------------
 * 06 - Regresion del incidente de Servian (24/07/2026).
 *
 * 50 filas de datos. El test lo importa con ARTICLE_EXCEL_CHUNK_SIZE = 10,
 * asi que quedan 5 lotes de 10 filas. Eso es lo que importa: el bug original
 * solo aparece cuando el mismo codigo cae en LOTES DISTINTOS.
 *
 * Distribucion deliberada (fila del Excel = indice + 2 por la cabecera):
 *
 *   lote 1  filas  2-11   PC-GUION (fila 3), PC-SN (fila 6), THOMPSON (fila 9)
 *   lote 2  filas 12-21   PC-GUION (fila 14)
 *   lote 3  filas 22-31   PC-GUION (fila 24), PC-SN (fila 28)
 *   lote 4  filas 32-41   PC-GUION (fila 35), FILTRO-A (fila 38)
 *   lote 5  filas 42-51   PC-GUION (fila 44), THOMPSON (fila 47),
 *                         FILTRO-B (fila 48), NOMBRE-DUP x2 (filas 50 y 51)
 *
 * Los codigos de barras numericos y los costos con muchos decimales van
 * repartidos para que el parseo tambien se ejercite en varios lotes.
 *
 * IMPORTANTE: los tipos PHP definen el tipo de celda.
 *   'ABC123'  string -> celda de TEXTO
 *   504346    int    -> celda NUMERICA
 *   1234.56   float  -> celda NUMERICA
 *   null             -> celda VACIA
 * -------------------------------------------------------------------------- */

/**
 * Fila de relleno: producto sano, con los tres codigos propios y distintos.
 *
 * @param  int $n
 * @return array
 */
function fila_sana($n)
{
    return [
        'BC-SANO-' . $n,          // codigo_de_barras (texto)
        'SKU-SANO-' . $n,         // sku (texto)
        'PC-SANO-' . $n,          // codigo_de_proveedor (texto)
        'PRODUCTO SANO ' . $n,    // nombre
        1000.0 + $n,              // costo (numerico)
        2000.0 + $n,              // precio (numerico)
        10,                       // stock_actual (numerico)
        21.0,                     // iva
    ];
}

$filas_servian = [];

for ($i = 1; $i <= 50; $i++) {
    $filas_servian[$i] = fila_sana($i);
}

/*
 * PC-GUION: cinco filas con el placeholder "-" como codigo de proveedor y SIN
 * codigo de barras. Es el caso de las 120 filas del archivo real.
 * Cada una cae en un lote distinto, con stock distinto: si el sistema las
 * fusiona, un mismo articulo recibe 5 movimientos de stock.
 */
$filas_servian[2]  = [null, 'SKU-G-01', '-', 'CORREA POLY V 5PK698 (HUTCHINSON)', 10244.36, 20488.72, 1,  21.0];
$filas_servian[13] = [null, 'SKU-G-02', '-', 'CORREA POLY V 6PK1550 (SKF)',       11500.00, 23000.00, 3,  21.0];
$filas_servian[23] = [null, 'SKU-G-03', '-', 'CORREA POLY V CONTINENTAL 4PK775',   9800.00, 19600.00, 5,  21.0];
$filas_servian[34] = [null, 'SKU-G-04', '-', 'CORREA POLY V DAYCO 5PK1065',       12300.00, 24600.00, 2,  21.0];
$filas_servian[43] = [null, 'SKU-G-05', '-', 'CORREA 7PK-1535 HUTCHINSON',        13750.00, 27500.00, 7,  21.0];

/*
 * PC-SN: dos filas con "S/N", tambien sin codigo de barras, en lotes distintos.
 * Es el caso de las 56 filas del archivo real.
 */
$filas_servian[5]  = [null, 'SKU-SN-01', 'S/N', 'KIT EMBRAGUE RENAULT MECANICA', 45000.00, 90000.00, 4, 21.0];
$filas_servian[27] = [null, 'SKU-SN-02', 'S/N', 'KIT EMBRAGUE FIAT MECANICA',    47500.00, 95000.00, 6, 21.0];

/*
 * THOMPSON: dos productos DISTINTOS (variantes I y W) que comparten codigo de
 * barras Y codigo de proveedor, en el lote 1 y en el lote 5.
 * En el incidente real esto creo DOS articulos con el mismo bar_code.
 */
$filas_servian[8]  = ['THOMPSON 6221', '3520G80I', '3520G8', 'PARRILLA INFERIOR IZQUIERDA PEUGEOT 206-207', 33000.00, 66000.00, 7, 21.0];
$filas_servian[46] = ['THOMPSON 6221', '3520G80W', '3520G8', 'PARRILLA INFERIOR IZQUIERDA PEUGEOT 206-207 (TRW)', 34000.00, 68000.00, 2, 21.0];

/*
 * FILTRO: dos productos distintos que comparten SOLO el codigo de proveedor,
 * con codigos de barras y skus propios, en lotes distintos.
 */
$filas_servian[37] = ['FAP 3288',      '04C129620D-E', '04C129620D', 'FILTRO AIRE VW UP 2014',        11290.28, 22580.56, 1, 21.0];
$filas_servian[47] = ['4047026011999', '0986B02533',   '04C129620D', 'FILTRO AIRE VOLKSWAGEN (BOSCH)', 19566.87, 39133.74, 2, 21.0];

/*
 * NOMBRE-DUP: dos productos con el nombre EXACTAMENTE igual y sin ningun
 * codigo. Tienen que llegar al escalon 5 y ser ambiguos entre si.
 */
$filas_servian[49] = [null, null, null, 'AMORTIGUADOR DELANTERO RENAULT (SACHS)', 28000.00, 56000.00, 3, 21.0];
$filas_servian[50] = [null, null, null, 'AMORTIGUADOR DELANTERO RENAULT (SACHS)', 28500.00, 57000.00, 4, 21.0];

/*
 * Codigos NUMERICOS: la celda es int, no string. Sin el casteo literal del
 * grupo 229 prompt 07, el sku queda guardado como "504346.0".
 */
$filas_servian[11] = [7790001234567, 504346, 'PC-NUM-01', 'BUJIA NGK BKR6E', 3500.0, 7000.0, 12, 21.0];
$filas_servian[31] = ['BC-NUM-02', 12345678901234, 'PC-NUM-02', 'BUJIA BOSCH FR7DC', 3800.0, 7600.0, 8, 21.0];

/*
 * Costos con muchos decimales: el mismo valor como TEXTO y como NUMERO.
 * Los dos tienen que dar exactamente lo mismo.
 */
$filas_servian[17] = ['BC-DEC-01', 'SKU-DEC-01', 'PC-DEC-01', 'ACEITE 10W40 4L', '123123.34324', 250000.0, 5, 21.0];
$filas_servian[39] = ['BC-DEC-02', 'SKU-DEC-02', 'PC-DEC-02', 'ACEITE 15W40 4L',  123123.34324,  250000.0, 5, 21.0];

/* Separador de miles y simbolo de moneda, como texto. */
$filas_servian[21] = ['BC-DEC-03', 'SKU-DEC-03', 'PC-DEC-03', 'LIQUIDO FRENOS DOT4', '1.234', 5000.0, 9, 21.0];
$filas_servian[29] = ['BC-DEC-04', 'SKU-DEC-04', 'PC-DEC-04', 'REFRIGERANTE VERDE', '$ 37468,24', 80000.0, 6, 21.0];

escribir('06_incidente_servian.xlsx', array_values($filas_servian), $cabecera);

/* --------------------------------------------------------------------------
 * 07 - Cadena de conflictos sobre un articulo YA EXISTENTE (grupo 265, prompt 03).
 *
 * Tres filas repiten el bar_code de A1 (7790001), que YA existe en base. Cada
 * repeticion tiene que reportar un conflicto 'fila_sobrescrita' encadenado
 * contra la fila INMEDIATAMENTE anterior, no siempre contra la primera:
 *
 *   fila 1 (F2) procesa A1 por primera vez (match real, sin conflicto)
 *   fila 2 (F3) pisa a la fila 1  -> conflicto fila=1, fila_ganadora=2
 *   fila 3 (F4) pisa a la fila 2  -> conflicto fila=2, fila_ganadora=3
 *
 * Antes del fix esto daba fila=1,fila_ganadora=2 y fila=1,fila_ganadora=3 (la
 * fila 2 nunca aparecia como perdedora): buscar_fila_origen_repetida() volvia
 * la PRIMERA entrada que matcheaba en la cola, no la ULTIMA.
 * -------------------------------------------------------------------------- */
escribir('07_cadena_sobre_articulo_existente.xlsx', [
    ['7790001', null, null, 'Cadena existente v1', 110.0, 220.0, 11.0, '21'],
    ['7790001', null, null, 'Cadena existente v2', 120.0, 240.0, 12.0, '21'],
    ['7790001', null, null, 'Cadena existente v3', 130.0, 260.0, 13.0, '21'],
], $cabecera);

echo "\nListo.\n";
