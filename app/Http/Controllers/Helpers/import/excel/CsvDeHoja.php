<?php

namespace App\Http\Controllers\Helpers\import\excel;

use Illuminate\Support\Facades\Log;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;

/**
 * El "sidecar" CSV de una hoja de un libro de Excel (misión
 * `importacion-excel-motor-rapido`, 24/9/2026).
 *
 * Por qué existe: el análisis con IA recorría el XLSX ENTERO cinco veces (contar filas,
 * duplicados, cadena de identificación, formatos numéricos, y otra vez al confirmar el
 * paso 2), y la importación lo volcaba a CSV una sexta vez adentro del request HTTP.
 * OpenSpout parsea ~1.600 filas por segundo en esta máquina: con 100.000 filas cada
 * recorrido son 35 a 90 segundos, y leer el mismo contenido desde un CSV con fgetcsv()
 * es 30 veces más rápido. Entonces la hoja se vuelca UNA vez, y todos los recorridos
 * posteriores (y la importación misma) leen el volcado.
 *
 * Son tres archivos al lado del XLSX, para la hoja $indice (0-based):
 *
 *   <excel>.hoja<indice>.csv    la hoja, UNA LÍNEA POR FILA FÍSICA del Excel, con el mismo
 *                               código y las mismas reglas que tenía
 *                               InitExcelImport::armar_archivo_csv(): filas vacías
 *                               preservadas, \DateTime como 'Y-m-d H:i:s', null como '',
 *                               una fila sin celdas como una única celda vacía. Es el MISMO
 *                               archivo que después consume la importación por lotes (que lo
 *                               navega por número de línea), así que línea = fila no se
 *                               negocia.
 *   <excel>.hoja<indice>.tipos  una línea por fila, un carácter por celda con el tipo PHP que
 *                               devolvió OpenSpout: s texto, i entero, f flotante, d fecha,
 *                               b booleano, e vacía/null. Una línea vacía es una fila SIN
 *                               celdas (hueco del Excel con preservar_filas_vacias). Existe
 *                               porque ExcelNumericFormatStats distingue una celda de texto
 *                               "2.500" de una celda numérica 2500 con is_string(): en el CSV
 *                               las dos son texto, y sin este archivo el analizador contaría
 *                               como ambiguas celdas que el Excel tiene como números.
 *   <excel>.hoja<indice>.json   filas_fisicas, ultima_fila_con_contenido, nombre_hoja,
 *                               xlsx_mtime e indice. El mtime es lo que vuelve idempotente al
 *                               volcado: si el XLSX cambió, el sidecar deja de valer.
 *
 * Los tres se escriben en archivos temporales y se renombran al final (el .json último),
 * para que un lector concurrente nunca vea un sidecar a medias: sin .json no hay sidecar.
 *
 * Lo que NO reproduce byte a byte, y por qué no importa: una celda de error de OpenSpout
 * (getValue() = null) vuelve como '' (todos los lectores tratan null y '' igual), y un
 * flotante con más de 14 dígitos significativos pierde lo que ya perdía al castearse a
 * string en el CSV (los lectores lo castean a string igual).
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promocion de constructor, readonly, enum ni #[...].
 */
class CsvDeHoja
{
    /** Códigos de tipo del archivo .tipos (un carácter por celda). */
    const TIPO_TEXTO    = 's';
    const TIPO_ENTERO   = 'i';
    const TIPO_FLOTANTE = 'f';
    const TIPO_FECHA    = 'd';
    const TIPO_BOOLEANO = 'b';
    const TIPO_VACIA    = 'e';

    /** Formato con el que el CSV guarda una celda de fecha (el de siempre en el volcado). */
    const FORMATO_FECHA = 'Y-m-d H:i:s';

    /** El writer CSV de OpenSpout arranca el archivo con este BOM; el lector lo saca. */
    const BOM_UTF8 = "\xEF\xBB\xBF";

    /** Versión del formato del sidecar: si cambia lo que se escribe, se bumpea y los viejos no valen. */
    const VERSION = 1;

    /**
     * @param  string $excel_path
     * @param  int    $indice
     * @return string
     */
    public static function ruta_csv($excel_path, $indice)
    {
        return self::prefijo($excel_path, $indice) . '.csv';
    }

    /**
     * @param  string $excel_path
     * @param  int    $indice
     * @return string
     */
    public static function ruta_tipos($excel_path, $indice)
    {
        return self::prefijo($excel_path, $indice) . '.tipos';
    }

    /**
     * @param  string $excel_path
     * @param  int    $indice
     * @return string
     */
    public static function ruta_meta($excel_path, $indice)
    {
        return self::prefijo($excel_path, $indice) . '.json';
    }

    /**
     * @param  string $excel_path
     * @param  int    $indice
     * @return string
     */
    protected static function prefijo($excel_path, $indice)
    {
        return $excel_path . '.hoja' . (int) $indice;
    }

    /**
     * Meta del sidecar de esa hoja si existe, está completo y corresponde al XLSX actual;
     * null si hay que volcar (o si no hay XLSX).
     *
     * "Corresponde" = el xlsx_mtime guardado coincide con el filemtime() de hoy. Un XLSX
     * reemplazado en el mismo path (misma ruta, otro contenido) invalida el sidecar solo.
     *
     * @param  string $excel_path
     * @param  int    $indice
     * @return array|null
     */
    public static function meta_vigente($excel_path, $indice)
    {
        $ruta_meta = self::ruta_meta($excel_path, $indice);

        if (!is_file($excel_path) || !is_file($ruta_meta)) {
            return null;
        }

        if (!is_file(self::ruta_csv($excel_path, $indice)) || !is_file(self::ruta_tipos($excel_path, $indice))) {
            return null;
        }

        $meta = json_decode((string) @file_get_contents($ruta_meta), true);

        if (!is_array($meta) || !isset($meta['xlsx_mtime'], $meta['nombre_hoja'], $meta['ultima_fila_con_contenido'])) {
            return null;
        }

        if ((int) ($meta['version'] ?? 0) !== self::VERSION) {
            return null;
        }

        if ((int) $meta['xlsx_mtime'] !== (int) @filemtime($excel_path)) {
            return null;
        }

        return $meta;
    }

    /**
     * Vuelca la hoja ya abierta con OpenSpout a los tres archivos del sidecar y devuelve el
     * meta. El llamador (ExcelWorkbookReader::asegurar_csv()) es quien abre y cierra la hoja.
     *
     * 🔴 El cuerpo del loop es el de InitExcelImport::armar_archivo_csv() de siempre, movido
     * acá con el agregado del .tipos. Todo lo que se cambie en cómo se escribe una celda
     * cambia el CSV que después lee ProcessArticleChunk::get_row_from_csv() por fgetcsv, y
     * los tests de HojaElegidaEnImportacionTest lo miran byte a byte.
     *
     * @param  string        $excel_path
     * @param  int           $indice   índice REAL de la hoja abierta (el que devolvió OpenSpout)
     * @param  LecturaDeHoja $lectura  hoja abierta con preservar_filas_vacias = true
     * @return array meta escrito en el .json
     *
     * @throws \RuntimeException si no se puede escribir al lado del XLSX
     */
    public static function volcar($excel_path, $indice, LecturaDeHoja $lectura)
    {
        $indice = (int) $indice;

        $ruta_csv   = self::ruta_csv($excel_path, $indice);
        $ruta_tipos = self::ruta_tipos($excel_path, $indice);
        $ruta_meta  = self::ruta_meta($excel_path, $indice);

        /* Temporales con sufijo propio del proceso: dos volcados a la vez no se pisan a medias. */
        $sufijo_tmp = '.tmp' . getmypid() . '.' . uniqid();
        $tmp_csv    = $ruta_csv . $sufijo_tmp;
        $tmp_tipos  = $ruta_tipos . $sufijo_tmp;
        $tmp_meta   = $ruta_meta . $sufijo_tmp;

        $inicio = microtime(true);

        $handle_tipos = @fopen($tmp_tipos, 'w');

        if ($handle_tipos === false) {
            throw new \RuntimeException('No se pudo escribir el volcado de la hoja al lado del archivo Excel.');
        }

        $writer = WriterEntityFactory::createCSVWriter();

        try {
            $writer->openToFile($tmp_csv);

            /* Número de fila actual en el Excel (1-based) y última fila con al menos una celda con datos. */
            $fila = 1;
            $ultima_fila_con_contenido = 1;

            foreach ($lectura->filas() as $row) {
                $cells = [];
                $codigos = '';
                $fila_tiene_contenido = false;

                foreach ($row->getCells() as $cell) {
                    $value = $cell->getValue();

                    $codigos .= self::tipo_de($value);

                    if ($value instanceof \DateTime) {
                        $value = $value->format(self::FORMATO_FECHA);
                    }

                    if ($value === null) {
                        $value = '';
                    }

                    $text_value = trim((string) $value);
                    if ($text_value !== '') {
                        $fila_tiene_contenido = true;
                    }

                    $cells[] = new Cell((string) $value);
                }

                /*
                 * Fila sin celdas (hueco del Excel): una celda vacía en el CSV, como siempre, y la
                 * línea del .tipos queda VACÍA. Eso es lo que le permite a LecturaDeHojaCsv
                 * devolver una fila sin celdas, igual que OpenSpout, y no una con una celda ''.
                 */
                if (count($cells) === 0) {
                    $cells[] = new Cell('');
                }

                if ($fila_tiene_contenido) {
                    $ultima_fila_con_contenido = $fila;
                }

                $writer->addRow(new Row($cells, null));
                fwrite($handle_tipos, $codigos . "\n");

                $fila++;
            }

            $writer->close();
            fclose($handle_tipos);

            $meta = [
                'version'                   => self::VERSION,
                'indice'                    => $indice,
                'nombre_hoja'               => $lectura->nombre(),
                'filas_fisicas'             => $fila - 1,
                'ultima_fila_con_contenido' => $ultima_fila_con_contenido,
                'xlsx_mtime'                => (int) @filemtime($excel_path),
                'generado_en'               => date('c'),
                'duracion_seg'              => round(microtime(true) - $inicio, 3),
            ];

            if (@file_put_contents($tmp_meta, json_encode($meta)) === false) {
                throw new \RuntimeException('No se pudo escribir el volcado de la hoja al lado del archivo Excel.');
            }

            /* El .json va último: sin él, meta_vigente() no reconoce el sidecar. */
            if (!@rename($tmp_csv, $ruta_csv) || !@rename($tmp_tipos, $ruta_tipos) || !@rename($tmp_meta, $ruta_meta)) {
                throw new \RuntimeException('No se pudo dejar el volcado de la hoja al lado del archivo Excel.');
            }
        } catch (\Throwable $e) {
            @unlink($tmp_csv);
            @unlink($tmp_tipos);
            @unlink($tmp_meta);

            throw $e;
        }

        Log::info('CsvDeHoja: hoja volcada a CSV', [
            'excel_path'                => $excel_path,
            'hoja'                      => $indice,
            'filas_fisicas'             => $meta['filas_fisicas'],
            'ultima_fila_con_contenido' => $meta['ultima_fila_con_contenido'],
            'duracion_seg'              => $meta['duracion_seg'],
        ]);

        return $meta;
    }

    /**
     * Código de tipo de una celda tal como la devolvió OpenSpout (Cell::getValue()).
     *
     * @param  mixed $value
     * @return string
     */
    public static function tipo_de($value)
    {
        if ($value === null || $value === '') {
            return self::TIPO_VACIA;
        }

        if (is_bool($value)) {
            return self::TIPO_BOOLEANO;
        }

        if (is_int($value)) {
            return self::TIPO_ENTERO;
        }

        if (is_float($value)) {
            return self::TIPO_FLOTANTE;
        }

        if ($value instanceof \DateTime) {
            return self::TIPO_FECHA;
        }

        return self::TIPO_TEXTO;
    }

    /**
     * Reconstruye el valor tipado de una celda a partir de su texto en el CSV y su código
     * de tipo. Es la inversa exacta de lo que escribe volcar().
     *
     * @param  string $texto
     * @param  string $tipo
     * @return mixed
     */
    public static function tipar($texto, $tipo)
    {
        switch ($tipo) {
            case self::TIPO_VACIA:
                return '';

            case self::TIPO_ENTERO:
                return (int) $texto;

            case self::TIPO_FLOTANTE:
                return (float) $texto;

            case self::TIPO_BOOLEANO:
                /* (string) true es '1' y (string) false es '': así lo dejó el volcado. */
                return $texto !== '';

            case self::TIPO_FECHA:
                /*
                 * El '!' resetea los campos que el formato no trae (microsegundos) a cero,
                 * igual que el '|' con el que OpenSpout arma sus \DateTime. Sin él,
                 * createFromFormat() les pone la hora actual.
                 */
                $fecha = \DateTime::createFromFormat('!' . self::FORMATO_FECHA, $texto);

                if ($fecha instanceof \DateTime) {
                    return $fecha;
                }

                try {
                    return new \DateTime($texto);
                } catch (\Throwable $e) {
                    return $texto;
                }

            default:
                return $texto;
        }
    }
}
