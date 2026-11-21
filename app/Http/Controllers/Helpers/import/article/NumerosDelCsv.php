<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Http\Controllers\Helpers\import\excel\CsvDeHoja;

/**
 * Las celdas NUMÉRICAS del Excel con decimales, escritas en el CSV de la importación de forma que
 * el parseo de números no las pueda leer como miles (misión importacion-formula-precio, 20/11/2026).
 *
 * El problema: el CSV intermedio guarda todo como texto. Una celda numérica 74,984 del Excel se
 * escribe «74.984», y ImportHelper::parseNumericValue() —pensado para texto argentino como
 * «2.500»— la lee como 74984 en el modo 'auto' (y en 'siempre_miles' rompe además cualquier
 * decimal: «141388.5429» → 1413885429). Medido en la lista real de un cliente: 18.374 costos con
 * tres decimales quedaban mil veces más caros, sin ningún aviso, porque ExcelNumericFormatStats
 * saltea las celdas numéricas (no son ambiguas en el Excel).
 *
 * La solución: el volcado de la hoja deja al lado del CSV un archivo .tipos (CsvDeHoja) con el
 * tipo de cada celda. Con él, en las columnas numéricas mapeadas, una celda que era un flotante se
 * reescribe con coma decimal («74,984»), que el parseo lee igual en los tres modos de
 * interpretación del punto. Las celdas de texto no se tocan: siguen pasando por la interpretación
 * que eligió el usuario, que es para lo que existe.
 *
 * Solo se reescriben los registros que cambian; el resto del CSV se copia byte a byte (mismo
 * criterio que EncabezadosRepetidos::vaciar_en_csv()).
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promocion de constructor, readonly, enum ni #[...].
 */
class NumerosDelCsv
{
    /**
     * Columnas del mapeo que son texto o identificadores: nunca se reescriben, aunque el Excel
     * tenga un número ahí (un SKU 12.5 tiene que seguir siendo «12.5»).
     */
    const COLUMNAS_DE_TEXTO = [
        'numero', 'codigo_de_barras', 'sku', 'codigo_de_proveedor', 'nombre', 'descripcion',
        'categoria', 'sub_categoria', 'marca', 'proveedor', 'contenido', 'unidad_medida',
        'moneda', 'costo_en_dolares', 'aplicar_iva', 'in_offer', 'online', 'precio_pausado',
        'disponible_tienda_nube',
    ];

    /**
     * Índices 0-based de las columnas numéricas del mapeo (todo lo mapeado que no es texto).
     *
     * @param  array $columnas  [propiedad => índice] del mapeo de la importación.
     * @return int[]
     */
    public static function indices_numericos(array $columnas)
    {
        $indices = [];

        foreach ($columnas as $propiedad => $indice) {
            if (in_array($propiedad, self::COLUMNAS_DE_TEXTO, true)) {
                continue;
            }
            if (!is_numeric($indice) || (int) $indice < 0) {
                continue;
            }
            $indices[(int) $indice] = true;
        }

        return array_keys($indices);
    }

    /**
     * Texto de una celda flotante con coma decimal: «74.984» → «74,984»; «1.0E-5» → «0,00001».
     * Un flotante sin parte decimal («2500») queda igual.
     *
     * @param  string $texto  Como lo dejó el volcado: (string) del float.
     * @return string
     */
    public static function con_coma_decimal($texto)
    {
        $texto = trim((string) $texto);

        // Notación científica: se expande antes de cambiar el separador.
        if (stripos($texto, 'e') !== false && is_numeric($texto)) {
            $texto = rtrim(rtrim(sprintf('%.12F', (float) $texto), '0'), '.');
        }

        if (!preg_match('/^-?\d+\.\d+$/', $texto)) {
            return $texto;
        }

        return str_replace('.', ',', $texto);
    }

    /**
     * Reescribe el CSV de la importación.
     *
     * @param  string $csv          CSV de la importación (copia en imported_files).
     * @param  string $ruta_tipos   El .tipos del volcado de la misma hoja (CsvDeHoja::ruta_tipos()).
     * @param  int[]  $indices      Columnas numéricas (indices_numericos()).
     * @return bool                 false si no se pudo reescribir (el CSV queda como estaba).
     */
    public static function reescribir($csv, $ruta_tipos, array $indices)
    {
        if (empty($indices) || !is_file($ruta_tipos)) {
            return true;
        }

        $lector   = @fopen($csv, 'rb');
        $crudo    = @fopen($csv, 'rb');
        $tipos    = @fopen($ruta_tipos, 'rb');
        $temporal = $csv . '.' . uniqid('', true) . '.tmp';
        $salida   = @fopen($temporal, 'wb');

        if ($lector === false || $crudo === false || $tipos === false || $salida === false) {
            self::cerrar([$lector, $crudo, $tipos, $salida]);
            @unlink($temporal);
            return false;
        }

        if (fread($lector, 3) !== CsvDeHoja::BOM_UTF8) {
            rewind($lector);
        }

        // Hasta dónde ya se copió el original, y dónde arranca el registro actual.
        $pendiente = 0;
        $inicio = ftell($lector);
        $cambios = 0;

        while (($celdas = fgetcsv($lector, 0, ',', '"', '')) !== false) {
            $fin = ftell($lector);
            $linea_de_tipos = fgets($tipos);
            $codigos = $linea_de_tipos === false ? '' : rtrim($linea_de_tipos, "\r\n");

            $cambio = false;
            foreach ($indices as $indice) {
                if (substr($codigos, $indice, 1) !== CsvDeHoja::TIPO_FLOTANTE || !isset($celdas[$indice])) {
                    continue;
                }
                $nuevo = self::con_coma_decimal($celdas[$indice]);
                if ($nuevo !== $celdas[$indice]) {
                    $celdas[$indice] = $nuevo;
                    $cambio = true;
                }
            }

            if ($cambio) {
                // Lo anterior sin cambios, byte a byte.
                fseek($crudo, $pendiente);
                stream_copy_to_stream($crudo, $salida, $inicio - $pendiente);

                // El mismo fin de línea que tenía el registro.
                fseek($crudo, max(0, $fin - 2));
                $cola = (string) fread($crudo, 2);
                $eol = substr($cola, -2) === "\r\n" ? "\r\n" : (substr($cola, -1) === "\n" ? "\n" : '');

                fwrite($salida, self::registro($celdas) . $eol);

                $pendiente = $fin;
                $cambios++;
            }

            $inicio = $fin;
        }

        fseek($crudo, $pendiente);
        stream_copy_to_stream($crudo, $salida);

        self::cerrar([$lector, $crudo, $tipos, $salida]);

        if ($cambios === 0) {
            @unlink($temporal);
            return true;
        }

        if (!@rename($temporal, $csv)) {
            @unlink($temporal);
            return false;
        }

        return true;
    }

    /**
     * Un registro CSV con el mismo dialecto que leen los lectores (coma, comillas dobles, escape
     * vacío), sin el fin de línea.
     *
     * @param  array $celdas
     * @return string
     */
    protected static function registro(array $celdas)
    {
        $memoria = fopen('php://memory', 'r+b');
        fputcsv($memoria, $celdas, ',', '"', '');
        rewind($memoria);
        $texto = (string) stream_get_contents($memoria);
        fclose($memoria);

        return rtrim($texto, "\r\n");
    }

    /**
     * Cierra los handles abiertos.
     *
     * @param  array $handles
     * @return void
     */
    protected static function cerrar(array $handles)
    {
        foreach ($handles as $handle) {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }
}
