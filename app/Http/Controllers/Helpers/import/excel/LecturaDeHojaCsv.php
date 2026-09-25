<?php

namespace App\Http\Controllers\Helpers\import\excel;

use Illuminate\Support\Facades\Log;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;

/**
 * La misma interfaz que LecturaDeHoja, pero leyendo el sidecar CSV de la hoja (ver
 * CsvDeHoja) en vez del XLSX con OpenSpout.
 *
 * La devuelve ExcelWorkbookReader::abrir() cuando el sidecar de esa hoja existe y
 * coincide con el XLSX, y ningún consumidor tiene que enterarse: filas() itera filas con
 * getCells(), cada celda contesta getValue() con el valor TIPADO según el .tipos (int,
 * float, \DateTime, bool, string), nombre() da el nombre de la hoja y cerrar() cierra.
 * Las filas y las celdas son las entidades de OpenSpout (Row y Cell), así que cualquier
 * otro método que un lector use sobre ellas (isEmpty(), toArray(), getNumCells()) contesta
 * lo mismo que con el XLSX.
 *
 * preservar_filas_vacias = false reproduce lo que hace OpenSpout con ese flag: saltea las
 * filas cuyas celdas son TODAS vacías (Cell::isEmpty(): valor null o ''; un 0 numérico o
 * un texto con espacios NO son vacíos), y la clave del iterador pasa a ser el número de
 * filas leídas en vez del número físico de fila.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promocion de constructor, readonly, enum ni #[...].
 */
class LecturaDeHojaCsv
{
    /** @var string */
    protected $ruta_csv;

    /** @var string */
    protected $ruta_tipos;

    /** @var array meta del .json del sidecar */
    protected $meta;

    /** @var bool */
    protected $preservar_filas_vacias;

    /** @var array handles abiertos por filas(), para que cerrar() los cierre aunque el loop haya cortado con break */
    protected $handles = [];

    /** @var bool */
    protected $cerrada = false;

    /** @var bool para avisar una sola vez si el .tipos quedó más corto que el .csv */
    protected $aviso_tipos_incompletos = false;

    /**
     * @param string $ruta_csv
     * @param string $ruta_tipos
     * @param array  $meta
     * @param bool   $preservar_filas_vacias
     */
    public function __construct($ruta_csv, $ruta_tipos, array $meta, $preservar_filas_vacias = true)
    {
        $this->ruta_csv               = $ruta_csv;
        $this->ruta_tipos             = $ruta_tipos;
        $this->meta                   = $meta;
        $this->preservar_filas_vacias = (bool) $preservar_filas_vacias;
    }

    /**
     * Sólo por paridad de interfaz con LecturaDeHoja: acá no hay hoja de OpenSpout.
     * Ningún lector del import la usa (verificado con grep antes de escribir esta clase).
     *
     * @return null
     */
    public function sheet()
    {
        return null;
    }

    /**
     * Iterador de filas. Cada llamada arranca desde la primera fila (un generador nuevo),
     * igual que getRowIterator() vuelve al principio con rewind().
     *
     * @return \Generator<int, \OpenSpout\Common\Entity\Row>
     */
    public function filas()
    {
        return $this->generar_filas();
    }

    /**
     * @return string
     */
    public function nombre()
    {
        return (string) $this->meta['nombre_hoja'];
    }

    /**
     * Meta del sidecar (filas_fisicas, ultima_fila_con_contenido, xlsx_mtime...).
     *
     * @return array
     */
    public function meta()
    {
        return $this->meta;
    }

    /**
     * Cierra los archivos. Idempotente y seguro después de un break temprano del loop.
     *
     * @return void
     */
    public function cerrar()
    {
        if ($this->cerrada) {
            return;
        }

        $this->cerrada = true;

        foreach ($this->handles as $handle) {
            if (is_resource($handle)) {
                @fclose($handle);
            }
        }

        $this->handles = [];
    }

    /**
     * @return \Generator
     */
    protected function generar_filas()
    {
        $csv   = @fopen($this->ruta_csv, 'r');
        $tipos = @fopen($this->ruta_tipos, 'r');

        if ($csv === false || $tipos === false) {
            if (is_resource($csv)) {
                fclose($csv);
            }
            if (is_resource($tipos)) {
                fclose($tipos);
            }

            throw new \RuntimeException(ExcelWorkbookReader::MENSAJE_ARCHIVO_ILEGIBLE);
        }

        $this->handles[] = $csv;
        $this->handles[] = $tipos;

        try {
            /*
             * El BOM se saltea ANTES de que fgetcsv() parsee la primera línea. Si se lo dejara
             * y se lo recortara después, fgetcsv() no reconocería el cierre de un primer campo
             * entrecomillado ("LISTA DE PRECIOS 2026", que lleva comillas por los espacios) y
             * devolvería el campo con las comillas adentro.
             */
            if (fread($csv, 3) !== CsvDeHoja::BOM_UTF8) {
                rewind($csv);
            }

            $fila_fisica = 0;
            $leidas      = 0;

            /*
             * Escape VACÍO, el mismo con el que escribe el writer CSV de OpenSpout (RFC 4180,
             * ver GlobalFunctionsHelper::fputcsv() de OpenSpout). Con el escape por defecto de
             * PHP, la barra invertida, una celda que termina en barra se escribe con la barra
             * pegada a la comilla de cierre y el lector toma esa comilla como literal: el campo
             * no cierra y el resto del archivo entra en esa celda (chequeo 3 de la misión,
             * 24/9/2026; LecturaDesdeCsvSidecarTest lo cubre).
             */
            while (($campos = fgetcsv($csv, 0, ',', '"', '')) !== false) {
                $fila_fisica++;

                $linea_tipos = fgets($tipos);
                $codigos     = ($linea_tipos === false) ? null : rtrim($linea_tipos, "\r\n");

                /* fgetcsv() devuelve [null] para una línea en blanco: es la celda vacía única de siempre. */
                if (count($campos) === 1 && $campos[0] === null) {
                    $campos = [''];
                }

                $celdas = [];

                if ($codigos !== '') {
                    if ($codigos === null || strlen($codigos) < count($campos)) {
                        $this->avisar_tipos_incompletos($fila_fisica);
                    }

                    foreach ($campos as $i => $texto) {
                        $tipo = ($codigos !== null && isset($codigos[$i])) ? $codigos[$i] : CsvDeHoja::TIPO_TEXTO;

                        $celdas[] = new Cell(CsvDeHoja::tipar((string) $texto, $tipo));
                    }
                }
                /* $codigos === '': fila sin celdas en el XLSX (hueco preservado); queda sin celdas acá también. */

                if (!$this->preservar_filas_vacias && self::todas_vacias($celdas)) {
                    continue;
                }

                $leidas++;

                yield ($this->preservar_filas_vacias ? $fila_fisica : $leidas) => new Row($celdas, null);
            }
        } finally {
            if (is_resource($csv)) {
                fclose($csv);
            }
            if (is_resource($tipos)) {
                fclose($tipos);
            }

            $this->handles = array_values(array_filter($this->handles, function ($h) {
                return is_resource($h);
            }));
        }
    }

    /**
     * Lo que OpenSpout llama fila vacía (RowManager::isEmpty): todas sus celdas son
     * Cell::isEmpty(). Una fila sin celdas también cuenta como vacía.
     *
     * @param  \OpenSpout\Common\Entity\Cell[] $celdas
     * @return bool
     */
    protected static function todas_vacias(array $celdas)
    {
        foreach ($celdas as $celda) {
            if (!$celda->isEmpty()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  int $fila_fisica
     * @return void
     */
    protected function avisar_tipos_incompletos($fila_fisica)
    {
        if ($this->aviso_tipos_incompletos) {
            return;
        }

        $this->aviso_tipos_incompletos = true;

        Log::warning('LecturaDeHojaCsv: el .tipos del sidecar no cubre todas las celdas; se asumen texto', [
            'csv'         => $this->ruta_csv,
            'fila_fisica' => $fila_fisica,
        ]);
    }
}
