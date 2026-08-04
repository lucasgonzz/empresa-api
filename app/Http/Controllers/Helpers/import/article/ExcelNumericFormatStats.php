<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use Illuminate\Support\Facades\Log;
use OpenSpout\Reader\Common\Creator\ReaderEntityFactory;

/**
 * Helper estático para detectar, ANTES de importar, los números con punto
 * de las columnas numéricas de un Excel que pueden interpretarse de dos
 * formas distintas (separador de miles vs. separador decimal), para poder
 * avisarle al usuario en la pantalla de sugerencia de importación.
 *
 * El caso peligroso no es "hay puntos", es "hay puntos interpretados de las
 * dos maneras en la misma columna" (ej: "2.500" junto con "3330.95"): un
 * archivo consistente (todos los puntos son miles, o todos son decimales)
 * no genera alerta, uno mezclado sí.
 *
 * Espejo de ExcelDuplicateStats: estático, lee con ReaderEntityFactory de
 * OpenSpout, no escribe nada en base de datos y ante cualquier error
 * devuelve el resultado vacío en vez de cortar el flujo de la importación.
 */
class ExcelNumericFormatStats
{
    /**
     * Analiza el Excel y devuelve, por columna numérica mapeada, cuántas
     * celdas con punto se interpretan como separador de miles vs. como
     * separador decimal, junto con ejemplos concretos de cada caso.
     *
     * La clasificación de cada celda replica exactamente la lógica que usa
     * ImportHelper::parseNumericValue() (mismo preg_replace de prefijo de
     * moneda y misma regex de miles), y el campo 'resultado' de cada
     * ejemplo se calcula llamando a ese mismo método, nunca reimplementando
     * la conversión, para que la alerta no se desincronice si la regla real
     * cambia en el futuro.
     *
     * @param  string $excel_path         Ruta absoluta al Excel
     * @param  array  $columnas_numericas Mapa campo => índice 0-based. Ej: ['cost' => 4, 'price' => 5]
     * @param  array  $nombres_columnas   Mapa campo => nombre visible en el Excel. Ej: ['cost' => 'COSTO']
     * @param  int    $max_ejemplos       Cantidad máxima de ejemplos por columna
     * @return array  ['columnas' => [campo => [...stats...]], 'hay_ambiguedad' => bool]
     */
    public static function analyze($excel_path, array $columnas_numericas, array $nombres_columnas = [], $max_ejemplos = 6)
    {
        /* Resultado vacío por defecto: se retorna si no hay columnas para analizar o si ocurre un error. */
        $empty_result = [
            'columnas' => [],
            'hay_ambiguedad' => false,
        ];

        /* Sin columnas numéricas mapeadas no hay nada que analizar: ni siquiera abrimos el archivo. */
        if (empty($columnas_numericas)) {
            return $empty_result;
        }

        /*
         * Acumuladores por campo. 'ejemplos_miles' y 'ejemplos_decimal' guardan, en orden de
         * aparición, hasta $max_ejemplos candidatos de cada interpretación (se recorta al final
         * según la regla de reparto del punto (06) del prompt).
         */
        $stats = [];
        foreach ($columnas_numericas as $campo => $indice) {
            $stats[$campo] = [
                'campo' => $campo,
                'nombre_columna_excel' => isset($nombres_columnas[$campo]) ? $nombres_columnas[$campo] : $campo,
                'total_celdas' => 0,
                'celdas_con_punto' => 0,
                'interpretados_miles' => 0,
                'interpretados_decimal' => 0,
                'ejemplos_miles' => [],
                'ejemplos_decimal' => [],
            ];
        }

        try {
            /* Mismo lector XLSX de OpenSpout que usa ExcelDuplicateStats / InitExcelImport. */
            $reader = ReaderEntityFactory::createXLSXReader();
            $reader->setShouldPreserveEmptyRows(false);
            $reader->open($excel_path);

            foreach ($reader->getSheetIterator() as $sheet) {
                /* Bandera para saltar la primera fila (cabecera). */
                $header_skipped = false;

                /* Contador de filas de datos procesadas (sin contar la cabecera). */
                $total_filas = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    if (!$header_skipped) {
                        $header_skipped = true;
                        continue;
                    }

                    $total_filas++;

                    /*
                     * Número de fila real en el Excel (1-based, incluye cabecera).
                     * La primera fila de datos es la fila 2.
                     */
                    $excel_row_number = $total_filas + 1;

                    /*
                     * Extraemos los valores de las celdas preservando su tipo original (sin
                     * castear a string): necesitamos distinguir celdas string de celdas numéricas
                     * reales, porque estas últimas ya vienen como float y no pasan por el parseo
                     * de separadores, así que no hay nada que avisar sobre ellas.
                     */
                    $cells = [];
                    foreach ($row->getCells() as $cell) {
                        $cells[] = $cell->getValue();
                    }

                    /* Analizamos, para esta fila, cada una de las columnas numéricas mapeadas. */
                    foreach ($columnas_numericas as $campo => $indice) {
                        if (!isset($cells[$indice])) {
                            continue;
                        }

                        $valor = $cells[$indice];

                        /* Celda vacía: no aporta al análisis. */
                        if (is_null($valor) || (is_string($valor) && trim($valor) === '')) {
                            continue;
                        }

                        $stats[$campo]['total_celdas']++;

                        /* Celda numérica real del Excel (float/int): no pasa por el parseo de separadores. */
                        if (!is_string($valor)) {
                            continue;
                        }

                        /* Valor original tal como vino del Excel, para mostrarlo en el ejemplo. */
                        $original = trim($valor);

                        /*
                         * Mismo preg_replace de prefijo de moneda que ImportHelper::parseNumericValue,
                         * para que "$ 2.500" clasifique igual que "2.500".
                         */
                        $normalizado = preg_replace('/^(USD|U\$S|\$)\s*/iu', '', $original);
                        $normalizado = trim($normalizado);

                        /* Sin punto: no hay ambigüedad que avisar para esta celda. */
                        if (strpos($normalizado, '.') !== false) {
                            $stats[$campo]['celdas_con_punto']++;

                            /*
                             * Coma y punto juntos: el criterio "el separador de más a la derecha
                             * manda" no deja lugar a interpretación errónea, así que no es ambigua.
                             */
                            if (strpos($normalizado, ',') === false) {
                                /*
                                 * Solo punto: replicamos la misma regex que usa parseNumericValue
                                 * para decidir si el punto es separador de miles o decimal.
                                 */
                                $es_miles = preg_match('/^-?\d{1,3}(\.\d{3})+$/', $normalizado) === 1;
                                $interpretacion = $es_miles ? 'miles' : 'decimal';

                                if ($es_miles) {
                                    $stats[$campo]['interpretados_miles']++;
                                } else {
                                    $stats[$campo]['interpretados_decimal']++;
                                }

                                /*
                                 * El 'resultado' del ejemplo se calcula reusando el parseo real,
                                 * nunca reimplementando la conversión. Si lanza excepción, el
                                 * ejemplo se descarta (pero el conteo de arriba ya quedó registrado).
                                 */
                                try {
                                    $resultado = ImportHelper::parseNumericValue($original);

                                    $ejemplo = [
                                        'fila' => $excel_row_number,
                                        'original' => $original,
                                        'interpretacion' => $interpretacion,
                                        'resultado' => (string) $resultado,
                                    ];

                                    /* Guardamos el ejemplo en el pool de su interpretación, acotado a $max_ejemplos. */
                                    $pool_key = $es_miles ? 'ejemplos_miles' : 'ejemplos_decimal';
                                    if (count($stats[$campo][$pool_key]) < $max_ejemplos) {
                                        $stats[$campo][$pool_key][] = $ejemplo;
                                    }
                                } catch (\Throwable $e) {
                                    /* No se pudo parsear: no se arma un ejemplo confiable para esta celda. */
                                }
                            }
                        }
                    }
                }

                /* Solo procesamos la primera hoja del libro. */
                break;
            }

            $reader->close();

        } catch (\Throwable $th) {
            /* Cualquier error de lectura no debe cortar el flujo de importación: solo se pierde la alerta. */
            Log::warning('ExcelNumericFormatStats: error al leer el Excel', [
                'message' => $th->getMessage(),
                'file' => $excel_path,
            ]);
            return $empty_result;
        }

        /*
         * Armamos el resultado final por columna. Solo entran las columnas que tengan al menos
         * una celda con punto ambigua (interpretados_miles > 0 o interpretados_decimal > 0).
         */
        $columnas_resultado = [];
        foreach ($stats as $campo => $data) {
            if ($data['interpretados_miles'] === 0 && $data['interpretados_decimal'] === 0) {
                continue;
            }

            /* Nivel de riesgo: alto si mezcla las dos interpretaciones, medio/bajo si es consistente. */
            if ($data['interpretados_miles'] > 0 && $data['interpretados_decimal'] > 0) {
                $nivel_de_riesgo = 'alto';
            } elseif ($data['interpretados_miles'] > 0) {
                $nivel_de_riesgo = 'medio';
            } else {
                $nivel_de_riesgo = 'bajo';
            }

            /*
             * Reparto de ejemplos (06 del prompt): primero hasta ceil($max_ejemplos / 2) de cada
             * tipo, y después se completa con lo que haya sobrado del otro tipo, para que el
             * usuario vea siempre las dos caras cuando existen.
             */
            $mitad = (int) ceil($max_ejemplos / 2);
            $desde_miles = array_slice($data['ejemplos_miles'], 0, $mitad);
            $desde_decimal = array_slice($data['ejemplos_decimal'], 0, $mitad);
            $ejemplos_finales = array_merge($desde_miles, $desde_decimal);

            if (count($ejemplos_finales) < $max_ejemplos) {
                /* Sobrante de cada pool que no entró en la primera repartición. */
                $resto_miles = array_slice($data['ejemplos_miles'], count($desde_miles));
                $resto_decimal = array_slice($data['ejemplos_decimal'], count($desde_decimal));
                $resto = array_merge($resto_miles, $resto_decimal);

                $faltan = $max_ejemplos - count($ejemplos_finales);
                $ejemplos_finales = array_merge($ejemplos_finales, array_slice($resto, 0, $faltan));
            }

            /* Orden estable por número de fila para una presentación consistente en el frontend. */
            usort($ejemplos_finales, function ($a, $b) {
                return $a['fila'] - $b['fila'];
            });

            $columnas_resultado[$campo] = [
                'campo' => $data['campo'],
                'nombre_columna_excel' => $data['nombre_columna_excel'],
                'total_celdas' => $data['total_celdas'],
                'celdas_con_punto' => $data['celdas_con_punto'],
                'interpretados_miles' => $data['interpretados_miles'],
                'interpretados_decimal' => $data['interpretados_decimal'],
                'nivel_de_riesgo' => $nivel_de_riesgo,
                'ejemplos' => $ejemplos_finales,
            ];
        }

        return [
            'columnas' => $columnas_resultado,
            'hay_ambiguedad' => !empty($columnas_resultado),
        ];
    }
}
