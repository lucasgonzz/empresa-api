<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Http\Controllers\Helpers\import\excel\EncabezadosRepetidos;
use App\Http\Controllers\Helpers\import\excel\ExcelWorkbookReader;
use Illuminate\Support\Facades\Log;

/**
 * Análisis algorítmico (sin IA) del Excel de artículos para el paso 3 de la importación
 * (misión importacion-formula-precio, 20/11/2026). En un solo recorrido del archivo arma:
 *
 *  - 'formula':   cómo se llega al precio de venta (DetectorDeFormulaDePrecio), sobre una muestra.
 *  - 'descuentos': cómo están escritos los descuentos (negativos, encadenados con «+»), sobre
 *                  todo el archivo, para ofrecer el tilde «los descuentos negativos descuentan».
 *  - 'codigos_de_barra': códigos de barras repetidos y cuántos se pueden resolver por código de
 *                  proveedor (CodigosDeBarraRepetidos), sobre todo el archivo.
 *
 * Nunca rompe el análisis: ante cualquier error devuelve null y lo deja en el log.
 */
class AnalisisDePrecioDelExcel
{
    /** Tamaño de la muestra de filas para el detector de fórmula. */
    const TAMANIO_MUESTRA = 300;

    /**
     * @param  string $excel_path      Ruta absoluta del Excel.
     * @param  array  $column_mapping  Mapeo confirmado [{system_property, excel_column_index, excel_column}].
     * @param  array  $opciones        ['hoja' => int, 'fila_encabezado' => int|null]
     * @return array|null
     */
    public static function analizar($excel_path, array $column_mapping, array $opciones = [])
    {
        try {
            return self::recorrer($excel_path, $column_mapping, $opciones);
        } catch (\Throwable $e) {
            Log::warning('AnalisisDePrecioDelExcel: no se pudo analizar el precio del Excel', [
                'excel'   => $excel_path,
                'mensaje' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Recorre el archivo una vez y arma los tres análisis.
     *
     * @param  string $excel_path
     * @param  array  $column_mapping
     * @param  array  $opciones
     * @return array
     */
    protected static function recorrer($excel_path, array $column_mapping, array $opciones)
    {
        $indice_hoja = isset($opciones['hoja']) ? (int) $opciones['hoja'] : 0;
        $fila_encabezado = isset($opciones['fila_encabezado']) && $opciones['fila_encabezado']
            ? (int) $opciones['fila_encabezado']
            : 1;

        // [system_property => índice 0-based] y encabezados [índice => nombre visible].
        $mapeo = [];
        $encabezados = [];
        foreach ($column_mapping as $columna) {
            if (!isset($columna['excel_column_index'])) {
                continue;
            }
            $indice = (int) $columna['excel_column_index'];
            $encabezados[$indice] = isset($columna['excel_column']) ? (string) $columna['excel_column'] : '';
            if (!empty($columna['system_property'])) {
                $mapeo[$columna['system_property']] = $indice;
            }
        }

        $indice_descuentos = $mapeo['descuentos'] ?? null;
        $indice_barras = $mapeo['codigo_de_barras'] ?? null;
        $indice_proveedor = $mapeo['codigo_de_proveedor'] ?? null;
        $indice_nombre = $mapeo['nombre'] ?? ($mapeo['descripcion'] ?? null);

        $descuentos = self::descuentos_vacios();
        $barras = new CodigosDeBarraRepetidos();

        // Muestra con paso creciente: cuando se llena, se queda con una de cada dos filas y
        // duplica el paso. Así cubre todo el archivo sin saber de antemano cuántas filas tiene.
        $muestra = [];
        $paso = 1;
        $contador = 0;

        $regla_de_encabezados = EncabezadosRepetidos::leer($excel_path, $indice_hoja);
        $lectura = ExcelWorkbookReader::abrir($excel_path, $indice_hoja, true);
        $numero_fila = 0;

        foreach ($lectura->filas() as $row) {
            $numero_fila++;

            $celdas = [];
            foreach ($row->getCells() as $cell) {
                $valor = $cell->getValue();
                // Las fechas no intervienen en el precio y no se pueden tratar como texto.
                $celdas[] = $valor instanceof \DateTimeInterface ? null : $valor;
            }

            // Encabezados de respaldo, si el mapeo no trajo el nombre de alguna columna.
            if ($numero_fila === $fila_encabezado) {
                foreach ($celdas as $indice => $valor) {
                    if (!isset($encabezados[$indice]) || $encabezados[$indice] === '') {
                        $encabezados[$indice] = is_null($valor) ? '' : (string) $valor;
                    }
                }
                continue;
            }

            if ($numero_fila < $fila_encabezado || self::fila_vacia($celdas)) {
                // (una fila con solo fechas también cuenta como vacía: no tiene nada de precio)
                continue;
            }

            if (EncabezadosRepetidos::es_encabezado($celdas, $numero_fila, $regla_de_encabezados)) {
                continue;
            }

            if (!is_null($indice_descuentos)) {
                self::sumar_descuento($descuentos, $celdas[$indice_descuentos] ?? null, $numero_fila);
            }

            if (!is_null($indice_barras)) {
                $barras->agregar(
                    $celdas[$indice_barras] ?? null,
                    is_null($indice_proveedor) ? null : ($celdas[$indice_proveedor] ?? null),
                    $numero_fila,
                    is_null($indice_nombre) ? null : ($celdas[$indice_nombre] ?? null)
                );
            }

            if ($contador % $paso === 0) {
                $muestra[$numero_fila] = $celdas;
                if (count($muestra) >= self::TAMANIO_MUESTRA * 2) {
                    $muestra = self::una_de_cada_dos($muestra);
                    $paso *= 2;
                }
            }
            $contador++;
        }

        $lectura->cerrar();

        $formula = (new DetectorDeFormulaDePrecio($encabezados, $muestra, $mapeo))->detectar();

        return [
            'formula'          => $formula,
            'descuentos'       => is_null($indice_descuentos) ? null : self::cerrar_descuentos($descuentos, $formula),
            'codigos_de_barra' => is_null($indice_barras) ? null : $barras->resumen() + [
                'hay_codigo_de_proveedor' => !is_null($indice_proveedor),
            ],
        ];
    }

    /**
     * Acumulador vacío de la estadística de descuentos.
     *
     * @return array
     */
    protected static function descuentos_vacios()
    {
        return [
            'celdas'               => 0,
            'con_negativos'        => 0,
            'solo_negativos'       => 0,
            'con_positivos'        => 0,
            'encadenados'          => 0,
            'encadenados_con_mas'  => 0,
            'no_interpretables'    => 0,
            'ejemplos_negativos'   => [],
            'ejemplos_encadenados' => [],
            'ejemplos_no_interpretables' => [],
        ];
    }

    /**
     * Suma una celda de descuentos a la estadística.
     *
     * @param  array $stats
     * @param  mixed $celda
     * @param  int   $fila
     * @return void
     */
    protected static function sumar_descuento(array &$stats, $celda, $fila)
    {
        $partes = DescuentosDelExcel::separar($celda);
        if (empty($partes)) {
            return;
        }

        $stats['celdas']++;
        $texto = trim((string) $celda);

        $negativos = 0;
        $positivos = 0;
        foreach ($partes as $parte) {
            $valor = DescuentosDelExcel::valor_aproximado($parte);
            if (is_null($valor)) {
                $stats['no_interpretables']++;
                if (count($stats['ejemplos_no_interpretables']) < 3) {
                    $stats['ejemplos_no_interpretables'][] = ['fila' => $fila, 'valor' => $texto];
                }
                return;
            }
            if ($valor < 0) {
                $negativos++;
            } else if ($valor > 0) {
                $positivos++;
            }
        }

        if ($negativos > 0) {
            $stats['con_negativos']++;
            if (count($stats['ejemplos_negativos']) < 3) {
                $stats['ejemplos_negativos'][] = ['fila' => $fila, 'valor' => $texto];
            }
        }
        if ($negativos > 0 && $positivos === 0) {
            $stats['solo_negativos']++;
        }
        if ($positivos > 0) {
            $stats['con_positivos']++;
        }
        if (count($partes) > 1) {
            $stats['encadenados']++;
            if (strpos($texto, '+') !== false || strpos($texto, ';') !== false) {
                $stats['encadenados_con_mas']++;
                if (count($stats['ejemplos_encadenados']) < 3) {
                    $stats['ejemplos_encadenados'][] = [
                        'fila'  => $fila,
                        'valor' => $texto,
                        'se_lee_como' => DescuentosDelExcel::normalizar_celda($celda, false),
                    ];
                }
            }
        }
    }

    /**
     * Cierra la estadística de descuentos con la recomendación del tilde.
     * Se recomienda «los negativos descuentan» si la mayoría de las celdas trae negativos, o si
     * la fórmula del precio solo cierra leyendo los descuentos con signo.
     *
     * @param  array      $stats
     * @param  array|null $formula
     * @return array
     */
    protected static function cerrar_descuentos(array $stats, $formula)
    {
        $mayoria_negativa = $stats['celdas'] > 0 && $stats['con_negativos'] / $stats['celdas'] >= 0.5;
        $la_formula_lo_confirma = is_array($formula)
            && !empty($formula['encontrada'])
            && ($formula['lectura_de_descuentos'] ?? null) === 'con_signo';

        $stats['recomendar_negativos_descuentan'] = $stats['con_negativos'] > 0
            && ($mayoria_negativa || $la_formula_lo_confirma);
        $stats['la_formula_lo_confirma'] = $la_formula_lo_confirma;

        return $stats;
    }

    /**
     * Se queda con una de cada dos filas de la muestra (conserva las claves = número de fila).
     *
     * @param  array $muestra
     * @return array
     */
    protected static function una_de_cada_dos(array $muestra)
    {
        $resultado = [];
        $i = 0;
        foreach ($muestra as $fila => $celdas) {
            if ($i % 2 === 0) {
                $resultado[$fila] = $celdas;
            }
            $i++;
        }
        return $resultado;
    }

    /**
     * ¿La fila no trae ningún valor?
     *
     * @param  array $celdas
     * @return bool
     */
    protected static function fila_vacia(array $celdas)
    {
        foreach ($celdas as $celda) {
            if ($celda instanceof \DateTimeInterface) {
                return false;
            }
            if (!is_null($celda) && trim((string) $celda) !== '') {
                return false;
            }
        }
        return true;
    }
}
