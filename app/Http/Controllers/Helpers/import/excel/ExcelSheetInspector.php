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
 * Las inspecciones de filas y fusiones degradan a null ante un ZIP ilegible. La
 * lectura auxiliar de fórmulas sí exige abrir la hoja: sin ella no se puede mantener
 * el contrato de valores del importador y falla con el mismo mensaje limpio.
 */
class ExcelSheetInspector
{
    /**
     * Coordenadas de fórmulas reales, por fila física y en streaming.
     * OpenSpout 4 representa también el texto literal '=...' como FormulaCell:
     * el XML permite distinguirlo de una fórmula cuyo resultado cacheado es un error.
     */
    public static function formulas_por_fila($excel_path, $indice, $al_abrir = null)
    {
        $rutas = self::rutas_de_hojas($excel_path);
        if ($rutas === null || !array_key_exists($indice, $rutas)) {
            throw new \RuntimeException(ExcelWorkbookReader::MENSAJE_ARCHIVO_ILEGIBLE);
        }
        if ($rutas[$indice] === null) {
            /* Una chartsheet conserva su índice, pero no contiene celdas ni fórmulas. */
            return;
        }

        $lector = self::abrir_hoja($excel_path, $rutas[$indice]);
        if ($lector === null) {
            throw new \RuntimeException(ExcelWorkbookReader::MENSAJE_ARCHIVO_ILEGIBLE);
        }
        if ($al_abrir !== null) {
            $al_abrir($lector);
        }

        $fila = 0;
        $columna = -1;
        $formulas = [];
        $es_formula = false;
        $valor_cache = '';
        try {
            while (@$lector->read()) {
                if ($lector->nodeType === \XMLReader::ELEMENT && $lector->localName === 'row') {
                    $fila = (int) ($lector->getAttribute('r') ?: $fila + 1);
                    $columna = -1;
                    $formulas = [];
                    if ($lector->isEmptyElement) {
                        yield $fila => $formulas;
                    }
                } elseif ($lector->nodeType === \XMLReader::ELEMENT && $lector->localName === 'c') {
                    $posicion = self::parsear_celda((string) $lector->getAttribute('r'));
                    $columna = $posicion === null ? $columna + 1 : $posicion['col'];
                    $es_formula = false;
                    $valor_cache = '';
                } elseif ($lector->nodeType === \XMLReader::ELEMENT && $lector->localName === 'f') {
                    $es_formula = true;
                } elseif ($lector->nodeType === \XMLReader::ELEMENT && $lector->localName === 'v') {
                    $valor_cache = $lector->readString();
                } elseif ($lector->nodeType === \XMLReader::END_ELEMENT && $lector->localName === 'c' && $es_formula) {
                    /* En 3, la falta de <v> o un <v> vacío devuelve '', también para números. */
                    $formulas[$columna] = $valor_cache === '' ? '' : true;
                } elseif ($lector->nodeType === \XMLReader::END_ELEMENT && $lector->localName === 'row') {
                    yield $fila => $formulas;
                }
            }
        } finally {
            $lector->close();
        }
    }

    public static function base_de_fechas($excel_path)
    {
        $zip = new \ZipArchive();
        if ($zip->open($excel_path) !== true) {
            return '1899-12-30';
        }
        $xml = $zip->getFromName('xl/workbook.xml');
        $zip->close();
        $lector = new \XMLReader();
        if ($xml === false || !@$lector->XML($xml, null, LIBXML_NONET)) {
            return '1899-12-30';
        }
        try {
            while (@$lector->read()) {
                if ($lector->nodeType === \XMLReader::ELEMENT && $lector->localName === 'workbookPr') {
                    return filter_var($lector->getAttribute('date1904'), FILTER_VALIDATE_BOOLEAN)
                        ? '1904-01-01' : '1899-12-30';
                }
            }
        } finally {
            $lector->close();
        }

        return '1899-12-30';
    }

    /** Tipo de relacion OOXML de una hoja de datos (las chartsheets tienen otro). */
    const TIPO_RELACION_WORKSHEET = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet';

    /** Tipo de relacion OOXML de la tabla de shared strings. */
    const TIPO_RELACION_SHARED_STRINGS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings';

    /**
     * Tamano (bytes sin comprimir) hasta el cual la hoja se recorre igual para verificar
     * el <dimension>. Ver ultima_fila_de_la_hoja().
     */
    const BYTES_PARA_VERIFICAR_DIMENSION = 65536;

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

        $tamanos = self::tamanos_de_hojas($excel_path, $rutas);

        $filas = [];

        foreach ($rutas as $indice => $ruta_interna) {
            $filas[$indice] = ($ruta_interna === null)
                ? 0
                : self::ultima_fila_de_la_hoja(
                    $excel_path,
                    $ruta_interna,
                    isset($tamanos[$indice]) ? $tamanos[$indice] : 0
                );
        }

        return $filas;
    }

    /**
     * Ultima fila declarada Y ultima fila con contenido, por hoja, en una sola pasada.
     *
     * "Fila con contenido" = al menos una celda cuyo texto, tras trim, no es vacio. Es la
     * misma regla con la que el navegador calcula la ultima fila (celdas con valor no
     * vacio), de modo que este metodo sirve de respaldo cuando SheetJS no puede leer el
     * archivo (por ejemplo un zip64 con tamanos en 0xFFFFFFFF en el local header). Las
     * filas que quedaron con formato, las celdas solo con estilo y los shared strings en
     * blanco NO cuentan: por eso "filas" (declarada) y "ultima_fila_con_contenido" pueden
     * diferir.
     *
     * Nunca lanza: una hoja que no abre da ceros y un zip ilegible da null. No cambia
     * filas_por_hoja(), que sigue siendo la fuente del selector de hojas post-analisis.
     *
     * @param  string $excel_path
     * @return array|null  [indice_hoja => ['filas' => int, 'ultima_fila_con_contenido' => int]];
     *                     null si el zip no se pudo leer
     */
    public static function filas_con_contenido_por_hoja($excel_path)
    {
        try {
            $rutas = self::rutas_de_hojas($excel_path);

            if ($rutas === null) {
                return null;
            }

            /* Set de indices de shared strings en blanco; se arma una sola vez para todas las hojas. */
            $blancos = self::indices_de_shared_strings_en_blanco($excel_path);

            $resultado = [];

            foreach ($rutas as $indice => $ruta_interna) {
                $resultado[$indice] = ($ruta_interna === null)
                    ? ['filas' => 0, 'ultima_fila_con_contenido' => 0]
                    : self::escanear_hoja_con_contenido($excel_path, $ruta_interna, $blancos);
            }

            return $resultado;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Recorre una hoja una sola vez y devuelve su ultima fila declarada y la ultima con
     * contenido. Cuando una fila ya tiene contenido salta el resto de sus celdas con
     * next('row'), lo que mantiene barato el recorrido en hojas de decenas de miles de filas.
     *
     * @param  string $excel_path
     * @param  string $ruta_interna
     * @param  array  $blancos  [indice shared string => true] de los shared strings en blanco
     * @return array  ['filas' => int, 'ultima_fila_con_contenido' => int]
     */
    protected static function escanear_hoja_con_contenido($excel_path, $ruta_interna, array $blancos)
    {
        $vacio = ['filas' => 0, 'ultima_fila_con_contenido' => 0];

        $lector = self::abrir_hoja($excel_path, $ruta_interna);

        if ($lector === null) {
            return $vacio;
        }

        /* Fila final de <dimension> (si declara un rango de mas de una fila). */
        $dimension = 0;
        /* Mayor numero de fila visto en un <row>. */
        $maxima_fila = 0;
        /* Ultima fila que tuvo contenido. */
        $ultima_con_contenido = 0;
        /* Fila fisica del <row> en el que estamos parados. */
        $fila_actual = 0;
        /* Tipo (atributo t) de la celda en curso. */
        $tipo_celda = '';
        /* false cuando next('row') ya nos dejo parados en el proximo <row> sin consumirlo. */
        $hay_que_leer = true;

        try {
            while (true) {
                if ($hay_que_leer && !@$lector->read()) {
                    break;
                }
                $hay_que_leer = true;

                if ($lector->nodeType !== \XMLReader::ELEMENT) {
                    continue;
                }

                $nombre = $lector->localName;

                if ($nombre === 'dimension') {
                    $fila_final = self::fila_final_del_rango((string) $lector->getAttribute('ref'));
                    $dimension = ($fila_final > 1) ? $fila_final : 0;
                } elseif ($nombre === 'row') {
                    $r = $lector->getAttribute('r');
                    $fila_actual = ($r !== null && ctype_digit((string) $r)) ? (int) $r : $fila_actual + 1;
                    if ($fila_actual > $maxima_fila) {
                        $maxima_fila = $fila_actual;
                    }
                } elseif ($nombre === 'c') {
                    $tipo_celda = (string) $lector->getAttribute('t');
                } elseif ($nombre === 'v' || $nombre === 't') {
                    $texto = $lector->readString();

                    /* Un shared string cuyo texto es blanco no es contenido. */
                    $es_blanco_compartido = ($tipo_celda === 's' && $nombre === 'v' && isset($blancos[(int) $texto]));

                    if (trim($texto) !== '' && !$es_blanco_compartido && $fila_actual > $ultima_con_contenido) {
                        $ultima_con_contenido = $fila_actual;

                        /* Con la fila ya marcada, se saltea el resto y se queda parado en el proximo <row>. */
                        if (@$lector->next('row')) {
                            $hay_que_leer = false;
                        } else {
                            break;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            /* Hoja ilegible a mitad de camino: se informa lo que se alcanzo a ver. */
        }

        $lector->close();

        return [
            'filas'                     => ($dimension > $maxima_fila) ? $dimension : $maxima_fila,
            'ultima_fila_con_contenido' => $ultima_con_contenido,
        ];
    }

    /**
     * Indices (0-based, orden de aparicion de <si>) de los shared strings cuyo texto
     * completo es vacio tras trim. Streaming; la memoria es proporcional a la cantidad de
     * blancos, no al tamano de la tabla. Sin tabla de strings devuelve un set vacio.
     *
     * @param  string $excel_path
     * @return array  [int indice => true]
     */
    protected static function indices_de_shared_strings_en_blanco($excel_path)
    {
        $blancos = [];

        $lector = self::abrir_hoja($excel_path, self::ruta_de_shared_strings($excel_path));

        if ($lector === null) {
            return $blancos;
        }

        /* Indice del <si> en curso (arranca en -1: el primero es el 0). */
        $indice = -1;

        while (@$lector->read()) {
            if ($lector->nodeType === \XMLReader::ELEMENT && $lector->localName === 'si') {
                $indice++;

                if (trim($lector->readString()) === '') {
                    $blancos[$indice] = true;
                }
            }
        }

        $lector->close();

        return $blancos;
    }

    /**
     * Ruta interna de la parte de shared strings: la que declara workbook.xml.rels con la
     * relacion de tipo sharedStrings, o xl/sharedStrings.xml si no hay relacion.
     *
     * @param  string $excel_path
     * @return string
     */
    protected static function ruta_de_shared_strings($excel_path)
    {
        $por_defecto = 'xl/sharedStrings.xml';

        $zip = new \ZipArchive();

        if ($zip->open($excel_path) !== true) {
            return $por_defecto;
        }

        $rels_xml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        $zip->close();

        if ($rels_xml === false || $rels_xml === '') {
            return $por_defecto;
        }

        $lector = new \XMLReader();

        if (@$lector->XML($rels_xml, null, LIBXML_NONET) !== true) {
            return $por_defecto;
        }

        $ruta = $por_defecto;

        while (@$lector->read()) {
            if ($lector->nodeType !== \XMLReader::ELEMENT || $lector->localName !== 'Relationship') {
                continue;
            }

            $destino = (string) $lector->getAttribute('Target');

            if ((string) $lector->getAttribute('Type') === self::TIPO_RELACION_SHARED_STRINGS && $destino !== '') {
                $ruta = self::normalizar_ruta_interna($destino);
                break;
            }
        }

        $lector->close();

        return $ruta;
    }

    /**
     * Tamano sin comprimir de la XML de cada hoja, en una sola apertura del zip.
     *
     * @param  string $excel_path
     * @param  array  $rutas  [indice_hoja => string|null]
     * @return array  [indice_hoja => int bytes]
     */
    protected static function tamanos_de_hojas($excel_path, array $rutas)
    {
        $tamanos = [];

        $zip = new \ZipArchive();

        if ($zip->open($excel_path) !== true) {
            return $tamanos;
        }

        foreach ($rutas as $indice => $ruta_interna) {
            if ($ruta_interna === null) {
                continue;
            }

            $dato = $zip->statName($ruta_interna);

            $tamanos[$indice] = ($dato !== false && isset($dato['size'])) ? (int) $dato['size'] : 0;
        }

        $zip->close();

        return $tamanos;
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
     * @param  int    $tamano  bytes sin comprimir de la XML de la hoja
     * @return int
     */
    protected static function ultima_fila_de_la_hoja($excel_path, $ruta_interna, $tamano = 0)
    {
        $lector = self::abrir_hoja($excel_path, $ruta_interna);

        if ($lector === null) {
            return 0;
        }

        $declarada   = 0;
        $ultima_fila = 0;

        /*
         * En el esquema OOXML <dimension> va ANTES de <sheetData>, asi que si esta y le
         * creemos, salimos sin haber tocado una sola celda: O(1) por hoja.
         */
        while (@$lector->read()) {
            if ($lector->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }

            if ($lector->localName === 'dimension') {
                $ref = (string) $lector->getAttribute('ref');
                $fila = self::fila_final_del_rango($ref);

                /*
                 * 🔴 AL <dimension> NO SE LE CREE SIEMPRE, Y NO ES DESCONFIANZA GRATIS.
                 * Esta cantidad de filas es el UNICO dato numerico que el usuario tiene para
                 * reconocer su lista en el selector de hoja: si le decimos "Lista (1 filas)"
                 * y "Notas (3 filas)", elige mal y ni se entera.
                 *
                 * Dos formas medidas de mentir:
                 *   - `ref="A1:A1"` (y `ref="A1"`): una sola celda. Es lo que escriben varios
                 *     generadores que no llevan la cuenta. Devolvia 1 sobre una hoja de 6
                 *     filas. Por eso el corte es `> 1` y no `> 0`: una hoja de una sola fila
                 *     igual sale bien del recorrido de abajo, y una hoja VACIA sale 0 en vez
                 *     de "1 filas".
                 *   - declarar de MENOS (`ref="A1:D2"` con 6 filas reales). Eso solo se
                 *     detecta recorriendo, y recorrer no es gratis: medido, una hoja de
                 *     20.000 filas tarda ~800 ms. Asi que se verifica solo cuando la hoja es
                 *     chica (BYTES_PARA_VERIFICAR_DIMENSION), que es donde recorrer cuesta
                 *     milisegundos. En una hoja grande se le cree al archivo. Si algun dia
                 *     aparece un generador que declara de menos en hojas grandes, el que lo
                 *     encuentre va a leer esto y va a saber que fue una decision, no un
                 *     olvido.
                 */
                if ($fila > 1 && $tamano > self::BYTES_PARA_VERIFICAR_DIMENSION) {
                    $lector->close();

                    return $fila;
                }

                $declarada = ($fila > 1) ? $fila : 0;

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

        /* Gana el mas grande: si el <dimension> declaro de menos, manda lo que se recorrio. */
        return ($declarada > $ultima_fila) ? $declarada : $ultima_fila;
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
