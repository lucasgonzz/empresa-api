<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * El Excel de la herramienta exportar_excel del agente personal (misión agente-mejoras-uso-real,
 * 29/10/2026, §4 E3.1 del plan): UNA hoja con la tabla que armó el modelo, ya validada por
 * BloquesIaHelper::tabla() (la misma forma que mostrar_tabla: columnas con clave, etiqueta y formato, y
 * filas con esas claves).
 *
 * Lo que hace distinto de "volcar un array":
 *
 *   - 🔴 NINGUNA CELDA DE TEXTO ES UNA FÓRMULA. El binder por defecto de PhpSpreadsheet toma como
 *     fórmula todo texto que empieza con "=", y los textos salen de la base del negocio (el nombre de un
 *     artículo, la razón social de un cliente que cargó cualquiera). Un "=HYPERLINK(...)" en un nombre
 *     se ejecutaría al abrir el archivo. bindValue() escribe los textos SIEMPRE como texto explícito y
 *     los números como número.
 *   - Los números quedan como NÚMEROS (se pueden sumar y ordenar en Excel), con el formato de su
 *     columna: moneda con "$", porcentaje como porcentaje (la tabla los trae en puntos, 15 = 15 %, y acá
 *     se dividen por 100 porque el formato de Excel multiplica) y número sin formato.
 *   - El encabezado va en negrita y el ancho de cada columna se ajusta solo.
 *
 * PHP 7.4: sin match, sin nullsafe, sin argumentos nombrados.
 */
class TablaDelAgenteExport implements FromArray, WithHeadings, WithTitle, WithColumnFormatting, WithStyles, ShouldAutoSize, WithCustomValueBinder
{
    /** Formato de Excel de una columna `moneda`. */
    const FORMATO_MONEDA = '"$"#,##0.00';

    /** Formato de Excel de una columna `porcentaje` (el valor ya viene dividido por 100). */
    const FORMATO_PORCENTAJE = '0.00%';

    /** Formato de Excel de una columna `numero`. */
    const FORMATO_NUMERO = 'General';

    /** Largo máximo del nombre de una hoja de Excel. */
    const MAX_NOMBRE_DE_HOJA = 31;

    /**
     * La tabla normalizada: {titulo, columnas: [{clave, etiqueta, formato}], filas: [{clave: valor}]}.
     *
     * @var array
     */
    protected $tabla;

    /**
     * @param  array  $tabla  El `tabla` del bloque que devuelve BloquesIaHelper::tabla().
     */
    public function __construct(array $tabla)
    {
        $this->tabla = $tabla;
    }

    /**
     * Las filas de datos, en el orden de las columnas. Un porcentaje se divide por 100 acá (ver el
     * docblock de la clase); un null queda como celda vacía.
     *
     * @return array<int, array<int, mixed>>
     */
    public function array(): array
    {
        $filas = [];

        foreach ($this->tabla['filas'] as $fila) {

            $renglon = [];

            foreach ($this->tabla['columnas'] as $columna) {

                $valor = array_key_exists($columna['clave'], $fila) ? $fila[$columna['clave']] : null;

                // Un porcentaje en puntos (15) pasa a fracción (0.15): el formato de Excel lo muestra como 15 %.
                if ($columna['formato'] === 'porcentaje' && (is_int($valor) || is_float($valor))) {
                    $valor = $valor / 100;
                }

                $renglon[] = $valor;
            }

            $filas[] = $renglon;
        }

        return $filas;
    }

    /**
     * El renglón de encabezado: la etiqueta de cada columna.
     *
     * @return array<int, string>
     */
    public function headings(): array
    {
        $encabezado = [];

        foreach ($this->tabla['columnas'] as $columna) {
            $encabezado[] = (string) $columna['etiqueta'];
        }

        return $encabezado;
    }

    /**
     * El nombre de la hoja: el título de la tabla sin los caracteres que Excel no acepta en una hoja
     * (`\ / ? * [ ] :`) y recortado a 31. Sin nada que quede, "Datos".
     *
     * @return string
     */
    public function title(): string
    {
        $titulo = preg_replace('/[\\\\\/\?\*\[\]:]+/u', ' ', (string) $this->tabla['titulo']);

        $titulo = trim(preg_replace('/\s+/u', ' ', (string) $titulo));

        $titulo = mb_substr($titulo, 0, self::MAX_NOMBRE_DE_HOJA);

        return trim($titulo) === '' ? 'Datos' : trim($titulo);
    }

    /**
     * El formato de cada columna numérica, por letra de columna. Las de texto y fecha quedan sin
     * formato (son texto: una fecha viene escrita como la dio el modelo).
     *
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        $formatos = [];

        foreach ($this->tabla['columnas'] as $i => $columna) {

            $letra = Coordinate::stringFromColumnIndex($i + 1);

            if ($columna['formato'] === 'moneda') {
                $formatos[$letra] = self::FORMATO_MONEDA;
            } elseif ($columna['formato'] === 'porcentaje') {
                $formatos[$letra] = self::FORMATO_PORCENTAJE;
            } elseif ($columna['formato'] === 'numero') {
                $formatos[$letra] = self::FORMATO_NUMERO;
            }
        }

        return $formatos;
    }

    /**
     * El encabezado en negrita.
     *
     * @param  Worksheet  $sheet
     * @return array
     */
    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }

    /**
     * 🔴 El binder de cada celda: los números como número y TODO lo demás como texto explícito, nunca
     * como fórmula (ver el docblock de la clase). Un null queda vacío.
     *
     * @param  Cell  $cell
     * @param  mixed  $value
     * @return bool
     */
    public function bindValue(Cell $cell, $value)
    {
        if (is_null($value)) {

            $cell->setValueExplicit(null, DataType::TYPE_NULL);

            return true;
        }

        if (is_int($value) || is_float($value)) {

            $cell->setValueExplicit($value, DataType::TYPE_NUMERIC);

            return true;
        }

        if (is_bool($value)) {

            $cell->setValueExplicit($value ? 'Sí' : 'No', DataType::TYPE_STRING);

            return true;
        }

        $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

        return true;
    }
}
