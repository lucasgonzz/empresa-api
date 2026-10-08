<?php

/**
 * Genera los fixtures 33 a 38: los nombres parecidos de las relaciones de la importación (misión
 * importacion-relaciones-parecidas, 8/10/2026).
 *
 * Se corre desde la raiz de empresa-api:
 *
 *     php tests/Import/fixtures/generar_relaciones_parecidas.php
 *
 * POR QUE EXISTE
 * Ningún fixture anterior trae columnas de proveedor, categoría, subcategoría ni marca: la
 * resolución de relaciones de la importación no tenía un solo test. Estos cuatro traen los casos
 * del pedido de Lucas ("DEL NORTE" vs "Del norte", "Pinturería" vs "Pintureria", "ACME" vs
 * "Acme", "CAÑERÍA" vs "cañería") para artículos, clientes y proveedores.
 *
 *   33_relaciones_parecidas_articulos.xlsx      cabecera común de 8 columnas + proveedor (9),
 *                                               categoria (10), sub_categoria (11), marca (12).
 *                                               Nueve filas (2 a 10); "Del norte" en la 2 y la 9 y
 *                                               "Pintureria" en la 6 y la 10, para que con lotes
 *                                               de 2 filas las decisiones crucen lotes.
 *   34_relaciones_parecidas_reimportacion.xlsx  la misma cabecera, una fila (RP-21) con los
 *                                               mismos valores que la fila 2 del 33: la segunda
 *                                               importación SIN decisiones.
 *   35_relaciones_parecidas_clientes.xlsx       nombre, provincia, localidad, vendedor,
 *                                               tipo_de_precio.
 *   36_relaciones_parecidas_proveedores.xlsx    nombre, localidad.
 *   37_relaciones_parecidas_localidades.xlsx    nombre, provincia, localidad: la misma localidad bajo
 *                                               dos escrituras de la misma provincia.
 *
 * Mismo criterio de tipos que generar_visible_en_tienda.php:
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
 * @param  string $archivo
 * @param  array  $cabecera
 * @param  array  $filas
 * @return void
 */
function escribir_fixture_relaciones($archivo, array $cabecera, array $filas)
{
    $libro = new Spreadsheet();
    $hoja  = $libro->getActiveSheet();
    $hoja->setTitle('Datos');

    $todas = array_merge([$cabecera], $filas);

    foreach ($todas as $indice => $valores) {
        $numero_fila = $indice + 1;

        foreach ($valores as $posicion => $valor) {
            if (is_null($valor)) {
                continue;
            }

            $ref = Coordinate::stringFromColumnIndex($posicion + 1) . $numero_fila;

            if (is_string($valor)) {
                $hoja->setCellValueExplicit($ref, $valor, DataType::TYPE_STRING);
            } else {
                $hoja->setCellValue($ref, $valor);
            }
        }
    }

    $destino = __DIR__ . '/' . $archivo;

    (new Xlsx($libro))->save($destino);

    echo 'Generado ' . $archivo . PHP_EOL;
}

$cabecera_articulos = [
    'codigo_de_barras',
    'sku',
    'codigo_de_proveedor',
    'nombre',
    'costo',
    'precio',
    'stock_actual',
    'iva',
    'proveedor',
    'categoria',
    'sub_categoria',
    'marca',
];

escribir_fixture_relaciones('33_relaciones_parecidas_articulos.xlsx', $cabecera_articulos, [
    ['7798800001', 'SKU-RP-01', 'RP-01', 'Art RP 01', 100.0, null, null, 21.0, 'ACME',              'Del norte',    'Martillo',  'Bosh'],
    ['7798800002', 'SKU-RP-02', 'RP-02', 'Art RP 02', 110.0, null, null, 21.0, 'Acme',              'DEL NORTE',    'Martillos', 'Bosch'],
    ['7798800003', 'SKU-RP-03', 'RP-03', 'Art RP 03', 120.0, null, null, 21.0, 'ACME',              'CAÑERÍA',      null,        null],
    ['7798800004', 'SKU-RP-04', 'RP-04', 'Art RP 04', 130.0, null, null, 21.0, null,                'Pinturería',   null,        'BOSCH'],
    ['7798800005', 'SKU-RP-05', 'RP-05', 'Art RP 05', 140.0, null, null, 21.0, null,                'Pintureria',   'Látex',     null],
    ['7798800006', 'SKU-RP-06', 'RP-06', 'Art RP 06', 150.0, null, null, 21.0, null,                'Pinturería',   'Latex',     null],
    ['7798800007', 'SKU-RP-07', 'RP-07', 'Art RP 07', 160.0, null, null, 21.0, 'Ferret. del Norte', 'Herramientas', 'martillos', null],
    ['7798800008', 'SKU-RP-08', 'RP-08', 'Art RP 08', 170.0, null, null, 21.0, null,                'Del norte',    null,        null],
    ['7798800009', 'SKU-RP-09', 'RP-09', 'Art RP 09', 180.0, null, null, 21.0, null,                'Pintureria',   null,        null],
]);

escribir_fixture_relaciones('34_relaciones_parecidas_reimportacion.xlsx', $cabecera_articulos, [
    ['7798800021', 'SKU-RP-21', 'RP-21', 'Art RP 21', 200.0, null, null, 21.0, 'ACME', 'Del norte', 'Martillo', 'Bosh'],
]);

escribir_fixture_relaciones('35_relaciones_parecidas_clientes.xlsx', ['nombre', 'provincia', 'localidad', 'vendedor', 'tipo_de_precio'], [
    ['Cliente RP 1', 'Buenos Aires', 'La Plata', 'Juan Perez', 'Mayorista'],
    ['Cliente RP 2', 'BUENOS AIRES', 'LA PLATA', 'JUAN PEREZ', 'MAYORISTA'],
    ['Cliente RP 3', 'Bs As',        'Quilmes',  'Juan Pérez', 'Minorista'],
    ['Cliente RP 4', null,           'Rosario',  null,         null],
]);

escribir_fixture_relaciones('36_relaciones_parecidas_proveedores.xlsx', ['nombre', 'localidad'], [
    ['Proveedor RP 1', 'Rosario'],
    ['Proveedor RP 2', 'ROSARIO'],
    ['Proveedor RP 3', 'Funes'],
]);

/*
 * Localidades bajo dos escrituras de la misma provincia: "Villa Gdor Galvez" bajo "Santa Fe." tiene
 * que compararse con "Villa Gdor. Galvez" bajo "Santa Fe" (las dos provincias se unen). Con o sin
 * punto, para que el WHERE de MySQL NO los junte solo: la prueba es que el paso 3 lo pregunte.
 */
escribir_fixture_relaciones('37_relaciones_parecidas_localidades.xlsx', ['nombre', 'provincia', 'localidad'], [
    ['Cliente LP 1', 'Santa Fe',  'Villa Gdor. Galvez'],
    ['Cliente LP 2', 'Santa Fe.', 'Villa Gdor Galvez'],
    ['Cliente LP 3', 'Santa Fe',  'Villa Gdor. Galvez'],
]);

/*
 * Los hijos de un padre en cada una de sus resoluciones posibles (`padre_ref`): "Pintureria Sur" (3
 * filas, la canónica) y "PINTURERIA SUR" (2 filas, su variante), con subcategorías que se parecen
 * DENTRO del mismo padre ("Látex"/"Latex", "Esmalte"/"ESMALTE"/"Esmaltes"). Con una categoría
 * "Pinturería del Sur" cargada, el padre es dudoso (usar o crear); sin nada cargado, las dos
 * escrituras son nuevas y la variante se une a la canónica (unir o crear aparte).
 */
escribir_fixture_relaciones('38_relaciones_parecidas_padres.xlsx', $cabecera_articulos, [
    ['7798800031', 'SKU-RP-31', 'RP-31', 'Art RP 31', 310.0, null, null, 21.0, null, 'Pintureria Sur', 'Látex',    null],
    ['7798800032', 'SKU-RP-32', 'RP-32', 'Art RP 32', 320.0, null, null, 21.0, null, 'Pintureria Sur', 'Latex',    null],
    ['7798800033', 'SKU-RP-33', 'RP-33', 'Art RP 33', 330.0, null, null, 21.0, null, 'Pintureria Sur', 'Esmalte',  null],
    ['7798800034', 'SKU-RP-34', 'RP-34', 'Art RP 34', 340.0, null, null, 21.0, null, 'PINTURERIA SUR', 'Esmaltes', null],
    ['7798800035', 'SKU-RP-35', 'RP-35', 'Art RP 35', 350.0, null, null, 21.0, null, 'PINTURERIA SUR', 'ESMALTE',  null],
]);
