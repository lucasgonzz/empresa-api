<?php

namespace App\Http\Controllers\Helpers\import\excel;

/**
 * Inspector de bajo nivel del .xlsx: cantidad de filas declaradas por hoja y rangos
 * de celdas fusionadas, leidos directo del ZIP con XMLReader en streaming.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promocion de constructor, readonly, enum ni #[...].
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * POR QUE ESTO VA POR ZIP Y NO POR PhpSpreadsheet
 *
 * Es tentador borrar todo este archivo y reemplazarlo por
 * `IOFactory::createReader('Xlsx')->listWorksheetInfo($path)`, que devuelve nombre,
 * cantidad de filas y columnas en una linea. NO SE HACE, y el motivo es la memoria:
 * `listWorksheetInfo()` (Reader/Xlsx.php, lineas 233-322 de la version que esta en
 * vendor/) mete la XML COMPLETA de cada hoja en un string y ademas la pasa entera por
 * el XmlScanner — dos copias completas de la hoja en memoria, por hoja, para obtener
 * tres numeros. En este repo `phpunit.xml` ya fuerza `memory_limit=-1` con un comentario
 * largo explicando que sin eso la suite no termina y el XML de junit queda en 0 bytes.
 * No se le agrega presion de memoria a un lugar que ya la tiene.
 *
 * El camino de aca abajo es streaming puro: XMLReader avanza por el zip sin
 * materializar la hoja, y el conteo de filas sale de <dimension>, que es O(1).
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * ESTE HELPER NUNCA LANZA. Ante un zip ilegible (archivo corrupto, .xls BIFF viejo,
 * permisos) devuelve null y el llamador degrada: 'filas' => 0, o "sin fusiones". El
 * unico que lanza en todo el namespace es ExcelWorkbookReader::abrir(), que es el que
 * de verdad no puede seguir.
 */
class ExcelSheetInspector
{
    /** Tipo de relacion OOXML de una hoja de datos (las chartsheets tienen otro). */
    const TIPO_RELACION_WORKSHEET = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet';

    /**
     * Ultima fila declarada por el archivo, por hoja.
     *
     * OJO CON QUE SIGNIFICA ESTE NUMERO: es "hasta que fila dice el archivo que llega
     * la hoja", no un conteo exacto de filas con contenido. Excel declara <dimension>
     * incluyendo filas que quedaron con formato y sin datos, y el fallback toma el
     * atributo `r` del ultimo <row>. Para el selector de hoja del paso 1
     * ("Hoja 'Notas' - 3 filas") alcanza y sobra. NO es lo mismo que
     * AiExcelAnalyzer::count_data_rows(), que si recorre y cuenta filas de datos
     * reales; no los intercambies.
     *
     * @param  string $excel_path
     * @return array|null  [indice_hoja => int ultima fila declarada]; null si el zip no se pudo leer
     */
    public static function filas_por_hoja($excel_path)
    {
        $rutas = self::rutas_de_hojas($excel_path);

        if ($rutas === null) {
            return null;
        }

        $filas = [];

        foreach ($rutas as $indice => $ruta_interna) {
            $filas[$indice] = ($ruta_interna === null)
                ? 0
                : self::ultima_fila_de_la_hoja($excel_path, $ruta_interna);
        }

        return $filas;
    }

    /**
     * Rangos de celdas fusionadas, por hoja.
     *
     * Las filas son 1-based (como las ve el usuario en Excel) y las columnas 0-based
     * (como los indices de celda que devuelve OpenSpout), a proposito: cada numero
     * viaja en la unidad en la que lo va a consumir quien lo usa.
     *
     * @param  string $excel_path
     * @return array|null  [indice_hoja => [['fila_desde'=>1,'fila_hasta'=>1,'col_desde'=>4,'col_hasta'=>5], ...]]
     *                     null si el zip no se pudo leer
     */
    public static function fusiones($excel_path)
    {
        $rutas = self::rutas_de_hojas($excel_path);

        if ($rutas === null) {
            return null;
        }

        $fusiones = [];

        foreach ($rutas as $indice => $ruta_interna) {
            $fusiones[$indice] = ($ruta_interna === null)
                ? []
                : self::fusiones_de_la_hoja($excel_path, $ruta_interna);
        }

        return $fusiones;
    }

    /**
     * Ruta interna en el zip de la hoja de cada indice, en el MISMO orden e indice que
     * usa OpenSpout.
     *
     * Las hojas que no son worksheets (chartsheets, dialogsheets) quedan con null en vez
     * de desaparecer del array: OpenSpout las enumera igual —su indice es la posicion en
     * <sheets> de xl/workbook.xml, sin filtrar nada, verificado en
     * vendor/openspout/.../Manager/SheetManager.php— asi que si aca las salteamos, todos
     * los indices posteriores quedan corridos y se lee la hoja equivocada en silencio.
     * Que es exactamente la clase de defecto que este namespace vino a matar.
     *
     * @param  string $excel_path
     * @return array|null  [indice_hoja => string|null ruta interna]; null si el zip no abrio
     */
    protected static function rutas_de_hojas($excel_path)
    {
        $zip = new \ZipArchive();

        /*
         * ZipArchive::open() devuelve un CODIGO de error, no lanza. Por eso el inspector
         * puede prometer "nunca lanza": un .xls viejo (BIFF, no es un zip) o un archivo
         * cortado a la mitad caen aca y salen como null.
         */
        if ($zip->open($excel_path) !== true) {
            return null;
        }

        $workbook_xml = $zip->getFromName('xl/workbook.xml');
        $rels_xml     = $zip->getFromName('xl/_rels/workbook.xml.rels');

        $zip->close();

        if ($workbook_xml === false || $workbook_xml === '') {
            return null;
        }

        $relaciones = self::parsear_relaciones($rels_xml === false ? '' : $rels_xml);

        $rutas = [];
        $indice = 0;

        $lector = new \XMLReader();

        if (@$lector->XML($workbook_xml, null, LIBXML_NONET) !== true) {
            return null;
        }

        while (@$lector->read()) {
            if ($lector->nodeType !== \XMLReader::ELEMENT || $lector->localName !== 'sheet') {
                continue;
            }

            $r_id = $lector->getAttribute('r:id');

            if ($r_id === null) {
                $r_id = $lector->getAttribute('id');
            }

            $rutas[$indice] = ($r_id !== null && isset($relaciones[$r_id]))
                ? $relaciones[$r_id]
                : null;

            $indice++;
        }

        $lector->close();

        return $rutas;
    }

    /**
     * Mapa rId => ruta interna, sólo para relaciones de tipo worksheet.
     *
     * @param  string $rels_xml
     * @return array  [string rId => string ruta interna dentro del zip]
     */
    protected static function parsear_relaciones($rels_xml)
    {
        $relaciones = [];

        if ($rels_xml === '') {
            return $relaciones;
        }

        $lector = new \XMLReader();

        if (@$lector->XML($rels_xml, null, LIBXML_NONET) !== true) {
            return $relaciones;
        }

        while (@$lector->read()) {
            if ($lector->nodeType !== \XMLReader::ELEMENT || $lector->localName !== 'Relationship') {
                continue;
            }

            $tipo = (string) $lector->getAttribute('Type');

            if ($tipo !== self::TIPO_RELACION_WORKSHEET) {
                continue;
            }

            $id      = $lector->getAttribute('Id');
            $destino = (string) $lector->getAttribute('Target');

            if ($id === null || $destino === '') {
                continue;
            }

            $relaciones[$id] = self::normalizar_ruta_interna($destino);
        }

        $lector->close();

        return $relaciones;
    }

    /**
     * El Target de workbook.xml.rels es relativo a xl/ ("worksheets/sheet1.xml") pero a
     * veces viene absoluto ("/xl/worksheets/sheet1.xml"). Se normaliza a la forma sin
     * barra inicial que entiende el wrapper zip://.
     *
     * @param  string $destino
     * @return string
     */
    protected static function normalizar_ruta_interna($destino)
    {
        $destino = str_replace('\\', '/', $destino);

        if (strpos($destino, '/xl/') === 0) {
            return ltrim($destino, '/');
        }

        if (strpos($destino, 'xl/') === 0) {
            return $destino;
        }

        return 'xl/' . ltrim($destino, '/');
    }

    /**
     * Ultima fila declarada por una hoja.
     *
     * @param  string $excel_path
     * @param  string $ruta_interna
     * @return int
     */
    protected static function ultima_fila_de_la_hoja($excel_path, $ruta_interna)
    {
        $lector = self::abrir_hoja($excel_path, $ruta_interna);

        if ($lector === null) {
            return 0;
        }

        $ultima_fila = 0;

        /*
         * En el esquema OOXML <dimension> va ANTES de <sheetData>, asi que si esta,
         * salimos sin haber tocado una sola celda: O(1) por hoja.
         */
        while (@$lector->read()) {
            if ($lector->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }

            if ($lector->localName === 'dimension') {
                $ref = (string) $lector->getAttribute('ref');
                $fila = self::fila_final_del_rango($ref);

                if ($fila > 0) {
                    $lector->close();

                    return $fila;
                }

                /* <dimension ref="A1"> de una sola celda no dice nada: seguimos al fallback. */
                continue;
            }

            if ($lector->localName === 'row') {
                /*
                 * Fallback en streaming: next('row') salta el subarbol de la fila entera
                 * sin materializar ni una celda, asi que la memoria es O(1) aunque la hoja
                 * tenga 200.000 filas.
                 */
                do {
                    $r = $lector->getAttribute('r');

                    if ($r !== null && ctype_digit((string) $r)) {
                        $ultima_fila = (int) $r;
                    }
                } while (@$lector->next('row'));

                break;
            }
        }

        $lector->close();

        return $ultima_fila;
    }

    /**
     * Rangos fusionados de una hoja.
     *
     * @param  string $excel_path
     * @param  string $ruta_interna
     * @return array
     */
    protected static function fusiones_de_la_hoja($excel_path, $ruta_interna)
    {
        $lector = self::abrir_hoja($excel_path, $ruta_interna);

        if ($lector === null) {
            return [];
        }

        $rangos = [];

        while (@$lector->read()) {
            if ($lector->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }

            /*
             * <mergeCells> va DESPUES de <sheetData>. Atravesar el bloque de datos con
             * read() seria leer nodo por nodo cada celda de la hoja; next() salta el
             * subarbol completo de <sheetData> de un paso.
             */
            if ($lector->localName === 'sheetData') {
                @$lector->next();

                continue;
            }

            if ($lector->localName === 'mergeCell') {
                $rango = self::parsear_rango((string) $lector->getAttribute('ref'));

                if ($rango !== null) {
                    $rangos[] = $rango;
                }
            }
        }

        $lector->close();

        return $rangos;
    }

    /**
     * Abre una hoja del zip con XMLReader.
     *
     * @param  string $excel_path
     * @param  string $ruta_interna
     * @return \XMLReader|null
     */
    protected static function abrir_hoja($excel_path, $ruta_interna)
    {
        $ruta_real = realpath($excel_path);

        if ($ruta_real === false) {
            return null;
        }

        $uri = 'zip://' . $ruta_real . '#' . ltrim($ruta_interna, '/');

        $lector = new \XMLReader();

        /* La @ es a proposito: si la entrada no existe, PHP emite un warning y devuelve false. */
        if (@$lector->open($uri, null, LIBXML_NONET) !== true) {
            return null;
        }

        return $lector;
    }

    /**
     * Fila final de un ref tipo "A1:H1204". Devuelve 0 si el ref no es un rango.
     *
     * @param  string $ref
     * @return int
     */
    protected static function fila_final_del_rango($ref)
    {
        if ($ref === '' || strpos($ref, ':') === false) {
            return 0;
        }

        $partes = explode(':', $ref);
        $fin    = end($partes);

        if (!preg_match('/^\$?[A-Za-z]+\$?(\d+)$/', $fin, $coincidencias)) {
            return 0;
        }

        return (int) $coincidencias[1];
    }

    /**
     * Parsea un ref de <mergeCell> tipo "E1:F3".
     *
     * @param  string $ref
     * @return array|null
     */
    protected static function parsear_rango($ref)
    {
        if ($ref === '' || strpos($ref, ':') === false) {
            return null;
        }

        $partes = explode(':', $ref);

        if (count($partes) !== 2) {
            return null;
        }

        $desde = self::parsear_celda($partes[0]);
        $hasta = self::parsear_celda($partes[1]);

        if ($desde === null || $hasta === null) {
            return null;
        }

        return [
            'fila_desde' => min($desde['fila'], $hasta['fila']),
            'fila_hasta' => max($desde['fila'], $hasta['fila']),
            'col_desde'  => min($desde['col'], $hasta['col']),
            'col_hasta'  => max($desde['col'], $hasta['col']),
        ];
    }

    /**
     * "E12" => ['col' => 4 (0-based), 'fila' => 12 (1-based)].
     *
     * @param  string $celda
     * @return array|null
     */
    protected static function parsear_celda($celda)
    {
        if (!preg_match('/^\$?([A-Za-z]+)\$?(\d+)$/', trim($celda), $coincidencias)) {
            return null;
        }

        $letras = strtoupper($coincidencias[1]);
        $col    = 0;
        $largo  = strlen($letras);

        for ($i = 0; $i < $largo; $i++) {
            $col = $col * 26 + (ord($letras[$i]) - 64);
        }

        return [
            'col'  => $col - 1,
            'fila' => (int) $coincidencias[2],
        ];
    }
}
