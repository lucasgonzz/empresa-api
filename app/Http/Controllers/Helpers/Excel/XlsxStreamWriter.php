<?php

namespace App\Http\Controllers\Helpers\Excel;

use PhpOffice\PhpSpreadsheet\Shared\StringHelper;
use RuntimeException;
use ZipArchive;

/**
 * Escritor de .xlsx de una sola hoja que vuelca cada fila al disco apenas la recibe.
 *
 * Por qué uno propio y no OpenSpout (que ya está en el composer.lock): medido el 28/9/2026,
 * OpenSpout 3 escribe ~55 mil celdas por segundo porque registra y serializa el estilo de cada
 * celda. El Excel de artículos de Servian son ~26 millones de celdas: solo escribir llevaba más de
 * ocho minutos. Esto arma el XML de la hoja a mano (~14 veces más rápido) y al cerrar lo empaqueta
 * con ZipArchive, la misma extensión que ya usan PhpSpreadsheet y OpenSpout para escribir.
 *
 * Qué escribe: una hoja con celdas numéricas, booleanas y de texto (texto en línea, sin tabla de
 * cadenas compartidas, igual que OpenSpout), un styles.xml mínimo y nada más. Las celdas vacías no
 * se escriben. Cada celda lleva su referencia (A1, B1...): los lectores la usan para ubicarla.
 */
class XlsxStreamWriter
{
    const TIPO_NUMERO = 'n';
    const TIPO_TEXTO = 's';
    const TIPO_BOOLEANO = 'b';

    /**
     * @var string Ruta final del .xlsx.
     */
    protected $ruta;

    /**
     * @var string Nombre de la hoja.
     */
    protected $nombre_hoja;

    /**
     * @var string XML de la hoja mientras se escribe.
     */
    protected $ruta_hoja;

    /**
     * @var resource|null
     */
    protected $hoja;

    /**
     * @var int Número de la próxima fila (1 = primera).
     */
    protected $fila = 1;

    /**
     * @var array Letras de columna ya calculadas, por índice (0 => A).
     */
    protected $letras = [];

    /**
     * @var int Columnas de la fila más ancha (hasta su última celda con dato).
     */
    protected $max_columnas = 0;

    /**
     * @var int Posición en el XML de la hoja donde va <dimension>, que se completa al cerrar.
     */
    protected $posicion_dimension = 0;

    /**
     * Largo reservado para <dimension ref="A1:XFD1048576"/> (32 caracteres) más relleno.
     */
    const LARGO_DIMENSION = 40;

    /**
     * @param string $ruta           Ruta final del .xlsx.
     * @param string $carpeta_temporal Donde se arma el XML de la hoja antes de empaquetarlo.
     * @param string $nombre_hoja
     */
    public function __construct($ruta, $carpeta_temporal, $nombre_hoja = 'Worksheet')
    {
        $this->ruta = $ruta;
        $this->nombre_hoja = $nombre_hoja;
        $this->ruta_hoja = rtrim($carpeta_temporal, '/\\') . DIRECTORY_SEPARATOR . 'hoja-' . uniqid('', true) . '.xml';

        $this->hoja = fopen($this->ruta_hoja, 'w+b');
        if ($this->hoja === false) {
            throw new RuntimeException('No se pudo crear el archivo temporal de la hoja: ' . $this->ruta_hoja);
        }

        $this->escribir(
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        );

        /*
         * <dimension> dice el rango usado de la hoja y va ANTES de las filas, cuando todavía no se
         * sabe cuántas hay: se reserva el lugar y se completa al cerrar. No es decorativo: el
         * lector de OpenSpout que usa el importador rellena cada fila hasta ese ancho, como pasaba
         * con los Excel de PhpSpreadsheet; sin él, una fila termina en su última celda con dato.
         */
        $this->posicion_dimension = ftell($this->hoja);
        $this->escribir(str_repeat(' ', self::LARGO_DIMENSION));

        $this->escribir('<sheetData>');
    }

    /**
     * Agrega una fila. Cada celda es null (vacía) o [tipo, valor], con tipo TIPO_NUMERO
     * (int|float), TIPO_TEXTO (string en UTF-8 válido) o TIPO_BOOLEANO.
     *
     * @param array $celdas
     * @return void
     */
    public function agregar_fila(array $celdas)
    {
        $numero = $this->fila++;
        $xml = '<row r="' . $numero . '">';

        $columna = 0;
        foreach ($celdas as $celda) {

            if (!is_null($celda)) {

                $ref = $this->letra($columna) . $numero;
                $valor = $celda[1];

                if ($celda[0] === self::TIPO_NUMERO) {
                    $xml .= '<c r="' . $ref . '"><v>' . $this->numero($valor) . '</v></c>';
                } else if ($celda[0] === self::TIPO_BOOLEANO) {
                    $xml .= '<c r="' . $ref . '" t="b"><v>' . ($valor ? '1' : '0') . '</v></c>';
                } else {
                    $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $this->texto($valor) . '</t></is></c>';
                }
            }

            $columna++;

            if (!is_null($celda) && $columna > $this->max_columnas) {
                $this->max_columnas = $columna;
            }
        }

        $this->escribir($xml . '</row>');
    }

    /**
     * Cierra la hoja y arma el .xlsx. Borra el XML temporal pase lo que pase.
     *
     * @return void
     */
    public function cerrar()
    {
        try {
            $this->escribir('</sheetData></worksheet>');

            $ultima = $this->letra(max(0, $this->max_columnas - 1)) . max(1, $this->fila - 1);
            fseek($this->hoja, $this->posicion_dimension);
            $this->escribir(str_pad('<dimension ref="A1:' . $ultima . '"/>', self::LARGO_DIMENSION, ' '));

            fclose($this->hoja);
            $this->hoja = null;

            $zip = new ZipArchive();
            if ($zip->open($this->ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('No se pudo crear el archivo ' . $this->ruta);
            }

            $zip->addFromString('[Content_Types].xml', $this->content_types());
            $zip->addFromString('_rels/.rels', $this->rels());
            $zip->addFromString('xl/workbook.xml', $this->workbook());
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbook_rels());
            $zip->addFromString('xl/styles.xml', $this->styles());
            $zip->addFile($this->ruta_hoja, 'xl/worksheets/sheet1.xml');

            if (!$zip->close()) {
                throw new RuntimeException('No se pudo terminar de escribir ' . $this->ruta);
            }
        } finally {
            $this->descartar();
        }
    }

    /**
     * Suelta el archivo temporal sin generar el .xlsx (se usa si algo falló a mitad).
     *
     * @return void
     */
    public function descartar()
    {
        if (is_resource($this->hoja)) {
            fclose($this->hoja);
        }
        $this->hoja = null;

        if (file_exists($this->ruta_hoja)) {
            @unlink($this->ruta_hoja);
        }
    }

    /**
     * @param string $xml
     * @return void
     */
    protected function escribir($xml)
    {
        if (fwrite($this->hoja, $xml) === false) {
            throw new RuntimeException('No se pudo escribir la hoja (¿disco lleno?): ' . $this->ruta_hoja);
        }
    }

    /**
     * Letra de columna para un índice base 0 (0 => A, 25 => Z, 26 => AA).
     *
     * @param int $indice
     * @return string
     */
    protected function letra($indice)
    {
        if (!isset($this->letras[$indice])) {
            $letra = '';
            $n = $indice + 1;
            while ($n > 0) {
                $resto = ($n - 1) % 26;
                $letra = chr(65 + $resto) . $letra;
                $n = (int) (($n - $resto - 1) / 26);
            }
            $this->letras[$indice] = $letra;
        }

        return $this->letras[$indice];
    }

    /**
     * Número como lo escribe PhpSpreadsheet (Writer/Xlsx/Worksheet::writeCellNumeric): la
     * conversión a string de PHP, con punto decimal aunque el locale use coma, y un float entero
     * con ".0" al final (725.0 y no 725), que al releerlo vuelve como float y no como int.
     *
     * @param int|float $valor
     * @return string
     */
    protected function numero($valor)
    {
        if (is_int($valor)) {
            return (string) $valor;
        }

        $texto = str_replace(',', '.', (string) $valor);
        if (strpos($texto, '.') === false) {
            $texto .= '.0';
        }

        return $texto;
    }

    /**
     * Texto escapado para XML. Los caracteres de control que XML no admite se escriben como
     * _xHHHH_, igual que PhpSpreadsheet (y Excel los vuelve a leer como el carácter original).
     *
     * @param string $valor
     * @return string
     */
    protected function texto($valor)
    {
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]|_x[0-9A-Fa-f]{4}_/', $valor)) {
            $valor = StringHelper::controlCharacterPHP2OOXML($valor);
        }

        return htmlspecialchars($valor, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    protected function content_types()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    protected function rels()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    protected function workbook()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . htmlspecialchars($this->nombre_hoja, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    protected function workbook_rels()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    protected function styles()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }
}
