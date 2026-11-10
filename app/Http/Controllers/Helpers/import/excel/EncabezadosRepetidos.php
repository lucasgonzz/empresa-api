<?php

namespace App\Http\Controllers\Helpers\import\excel;

use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Encabezados que se repiten adentro de una misma planilla (misión
 * importacion-ia-mapeo-y-recomendacion, 10/11/2026).
 *
 * Hay listas que traen varias tablas pegadas en la misma hoja: cada tanto vuelve a aparecer
 * una fila de títulos. El caso que originó esto («mayorista pesca.xlsx») tiene 98 filas con
 * `Articulo` en la columna del código, el nombre de la sección al lado y el resto vacío. El
 * sistema las leía como artículos: «el código de proveedor Articulo se repite 98 veces».
 *
 * La IA solo ve una muestra del archivo, así que no puede ser ella la que recorra la planilla.
 * El trabajo se reparte en tres:
 *
 *   1. candidatas(): sobre el archivo ENTERO, sin saber todavía el mapeo, junta los valores
 *      que solo aparecen en filas casi vacías.
 *   2. La IA, en la misma llamada del análisis, dice cuáles son encabezados y cuáles un dato real.
 *   3. confirmar(): arma la regla con lo que confirmó la IA, más los valores que aunque la IA no
 *      los confirme no pueden ser un artículo (ver el comentario del método).
 *
 * La regla queda en un archivo al lado del Excel, con el mismo prefijo que el volcado de la
 * hoja, y por eso FinalizeArticleImport la limpia con el resto. Así la leen el análisis, la
 * recomendación, la importación del modal, AdminSync y el circuito de WhatsApp sin que la SPA
 * tenga que hacerla viajar.
 *
 * La regla no depende del mapeo: el usuario puede cambiar una columna en el paso 2 y las
 * filas ignoradas siguen siendo las mismas.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
class EncabezadosRepetidos
{
    /** Cuántos grupos, los de más repeticiones, se le muestran a la IA. */
    const MAX_GRUPOS_EN_PROMPT = 5;

    /** Tope de grupos que se conservan después de ordenar. */
    const MAX_GRUPOS = 50;

    /** Tope de valores distintos en memoria: una lista muy despareja no puede inflar el proceso. */
    const MAX_VALORES_DISTINTOS = 5000;

    /** Una etiqueta de encabezado es corta. */
    const LARGO_MAXIMO_ETIQUETA = 60;

    /** Repeticiones desde las que un valor en una columna de código entra aunque la IA no lo confirme. */
    const REPETICIONES_SIN_IA = 3;

    /** Cuántos números de fila se le muestran al usuario como ejemplo. */
    const EJEMPLOS = 3;

    /** Columnas donde un texto repetido en filas casi vacías no puede ser el código de un artículo. */
    const PROPIEDADES_IDENTIFICADORAS = ['codigo_de_proveedor', 'codigo_de_barras', 'sku'];

    /**
     * Junta, sobre la hoja entera, lo que puede ser un encabezado repetido.
     *
     * Nunca lanza: si no se puede leer el archivo no hay candidatas y el análisis sigue.
     *
     * @param  string $excel_path
     * @param  int    $indice_hoja
     * @param  int    $fila_encabezado
     * @param  array  $encabezados     títulos de la fila de encabezado, por posición 0-based
     * @return array
     */
    public static function candidatas($excel_path, $indice_hoja, $fila_encabezado, array $encabezados)
    {
        $encabezado = self::encabezado_normalizado($encabezados);
        $vacio      = self::candidatas_vacias($fila_encabezado, $encabezado);

        try {
            $hoja = ['excel' => $excel_path, 'indice' => (int) $indice_hoja];

            $vacio['hoja'] = $hoja;

            return self::medir($hoja, (int) $fila_encabezado, $encabezado, $vacio);
        } catch (\Throwable $e) {
            Log::warning('EncabezadosRepetidos: no se pudieron buscar candidatas', [
                'message' => $e->getMessage(),
                'hoja'    => $indice_hoja,
            ]);

            return $vacio;
        }
    }

    /**
     * Las pasadas sobre el CSV de la hoja.
     *
     * Son tres porque cada una necesita lo que midió la anterior: la mediana define qué es una
     * fila «delgada», las delgadas definen los grupos, y recién con los grupos se sabe qué
     * valores hay que buscar en las filas completas.
     *
     * @param  array  $hoja
     * @param  int    $fila_encabezado
     * @param  array  $encabezado
     * @param  array  $resultado
     * @return array
     */
    protected static function medir(array $hoja, $fila_encabezado, array $encabezado, array $resultado)
    {
        $histograma = [];
        $exactas    = 0;

        foreach (self::filas_de_la_hoja($hoja) as $numero => $celdas) {
            if ($numero <= $fila_encabezado) {
                continue;
            }

            if (self::repite_el_encabezado($celdas, $encabezado)) {
                $exactas++;
                continue;
            }

            $con_dato = self::celdas_con_dato($celdas);

            if ($con_dato > 0) {
                $histograma[$con_dato] = ($histograma[$con_dato] ?? 0) + 1;
            }
        }

        $mediana = self::mediana($histograma);

        /* Con tablas de una o dos columnas no se distingue una fila «delgada» de una normal. */
        if ($mediana < 3) {
            return $resultado;
        }

        $resultado['exactas'] = $exactas;

        $limite = max(2, (int) ceil($mediana * 0.5));
        $grupos = self::grupos_de_filas_delgadas($hoja, $fila_encabezado, $encabezado, $limite);

        if (!empty($grupos)) {
            $grupos = self::sin_los_que_aparecen_en_filas_completas($grupos, $hoja, $fila_encabezado, $encabezado, $limite);
        }

        usort($grupos, function ($a, $b) {
            return $b['repeticiones'] - $a['repeticiones'];
        });

        $resultado['grupos'] = array_slice($grupos, 0, self::MAX_GRUPOS);

        return $resultado;
    }

    /**
     * Agrupa por (columna, valor) las celdas sin dígitos de las filas casi vacías.
     *
     * @param  array  $hoja
     * @param  int    $fila_encabezado
     * @param  array  $encabezado
     * @param  int    $limite           máximo de celdas con dato de una fila delgada
     * @return array
     */
    protected static function grupos_de_filas_delgadas(array $hoja, $fila_encabezado, array $encabezado, $limite)
    {
        $grupos = [];

        foreach (self::filas_de_la_hoja($hoja) as $numero => $celdas) {
            if ($numero <= $fila_encabezado || self::repite_el_encabezado($celdas, $encabezado)) {
                continue;
            }

            $con_dato = self::celdas_con_dato($celdas);

            if ($con_dato === 0 || $con_dato > $limite) {
                continue;
            }

            foreach ($celdas as $columna => $valor) {
                $clave = self::normalizar($valor);

                if (!self::puede_ser_etiqueta($valor, $clave)) {
                    continue;
                }

                $grupo = $columna . '|' . $clave;

                if (!isset($grupos[$grupo])) {
                    if (count($grupos) >= self::MAX_VALORES_DISTINTOS) {
                        continue;
                    }

                    $grupos[$grupo] = [
                        'columna'      => (int) $columna,
                        'valor'        => trim((string) $valor),
                        'clave'        => $clave,
                        'repeticiones' => 0,
                        'max_celdas'   => 0,
                        'ejemplos'     => [],
                        'muestra'      => self::muestra($celdas),
                    ];
                }

                $grupos[$grupo]['repeticiones']++;
                $grupos[$grupo]['max_celdas'] = max($grupos[$grupo]['max_celdas'], $con_dato);

                if (count($grupos[$grupo]['ejemplos']) < self::EJEMPLOS) {
                    $grupos[$grupo]['ejemplos'][] = (int) $numero;
                }
            }
        }

        return array_filter($grupos, function ($grupo) {
            return $grupo['repeticiones'] >= 2;
        });
    }

    /**
     * Saca los valores que también aparecen en filas completas: una marca o una categoría
     * repetidas en filas incompletas siguen siendo datos, porque el resto de los artículos las usa.
     *
     * @param  array  $grupos
     * @param  array  $hoja
     * @param  int    $fila_encabezado
     * @param  array  $encabezado
     * @param  int    $limite
     * @return array  lista sin claves
     */
    protected static function sin_los_que_aparecen_en_filas_completas(array $grupos, array $hoja, $fila_encabezado, array $encabezado, $limite)
    {
        $por_columna = [];

        foreach ($grupos as $clave_grupo => $grupo) {
            $por_columna[$grupo['columna']][$grupo['clave']] = $clave_grupo;
        }

        foreach (self::filas_de_la_hoja($hoja) as $numero => $celdas) {
            if ($numero <= $fila_encabezado || self::celdas_con_dato($celdas) <= $limite) {
                continue;
            }

            if (self::repite_el_encabezado($celdas, $encabezado)) {
                continue;
            }

            foreach ($por_columna as $columna => $valores) {
                if (!isset($celdas[$columna])) {
                    continue;
                }

                $clave = self::normalizar($celdas[$columna]);

                if (isset($valores[$clave])) {
                    unset($grupos[$valores[$clave]]);
                }
            }
        }

        return array_values($grupos);
    }

    /**
     * La sección del prompt con las candidatas. '' si no hay ninguna.
     *
     * @param  array  $candidatas
     * @return string
     */
    public static function seccion_para_prompt(array $candidatas)
    {
        $grupos = array_slice($candidatas['grupos'] ?? [], 0, self::MAX_GRUPOS_EN_PROMPT);

        if (empty($grupos)) {
            return '';
        }

        $lineas = '';

        foreach ($grupos as $grupo) {
            $lineas .= '- columna ' . $grupo['columna']
                . ' (' . Coordinate::stringFromColumnIndex($grupo['columna'] + 1) . '), valor «' . $grupo['valor'] . '»: '
                . $grupo['repeticiones'] . ' filas. Ejemplo, fila ' . $grupo['ejemplos'][0] . ': ' . $grupo['muestra'] . "\n";
        }

        return "## Posibles encabezados repetidos adentro de la planilla\n"
            . "A veces una misma hoja trae varias tablas pegadas y cada tanto vuelve a aparecer una fila de títulos o de sección. "
            . "Estos valores solo aparecen en filas casi vacías (columna = posición desde 0):\n"
            . $lineas
            . "Devolvé en \"encabezados_repetidos\" los que sean títulos o rótulos de una tabla y no datos de un artículo. "
            . "Dejá afuera los que sean un dato real (una marca, una categoría, un código).\n";
    }

    /**
     * Arma la regla final.
     *
     * Entran los grupos que confirmó la IA (solo si eran candidatos: no se le acepta un valor
     * que la medición no encontró) y, sin depender de ella, los de una columna de código que se
     * repiten tres veces o más. Un texto sin dígitos, repetido, que nunca viene con el resto de
     * los datos, no identifica a ningún artículo. Si esa parte dependiera solo de la IA, una
     * respuesta cortada volvería a mostrarle al usuario el falso repetido.
     *
     * @param  array      $candidatas
     * @param  array|null $respuesta_ia    [['columna' => 1, 'valor' => 'Articulo'], ...]
     * @param  array      $column_mapping  mapeo enriquecido con excel_column_index
     * @return array
     */
    public static function confirmar(array $candidatas, $respuesta_ia, array $column_mapping)
    {
        $confirmadas      = self::confirmadas_por_la_ia($respuesta_ia);
        $identificadoras  = self::columnas_identificadoras($column_mapping);
        $etiquetas        = [];
        $valores          = [];

        foreach ($candidatas['grupos'] ?? [] as $grupo) {
            $por_la_ia = isset($confirmadas[$grupo['columna'] . '|' . $grupo['clave']])
                || isset($confirmadas['*|' . $grupo['clave']]);

            $sin_ia = isset($identificadoras[$grupo['columna']])
                && $grupo['repeticiones'] >= self::REPETICIONES_SIN_IA;

            if (!$por_la_ia && !$sin_ia) {
                continue;
            }

            $etiquetas[] = [
                'columna'    => $grupo['columna'],
                'valor'      => $grupo['clave'],
                'max_celdas' => $grupo['max_celdas'],
            ];

            $valores[$grupo['valor']] = true;
        }

        $regla = [
            'fila_encabezado' => (int) ($candidatas['fila_encabezado'] ?? 1),
            'encabezado'      => (int) ($candidatas['exactas'] ?? 0) > 0 ? ($candidatas['encabezado'] ?? []) : [],
            'etiquetas'       => $etiquetas,
            'filas'           => 0,
            'ejemplos'        => [],
            'valores'         => array_map('strval', array_keys($valores)),
        ];

        if (empty($regla['encabezado']) && empty($regla['etiquetas'])) {
            return $regla;
        }

        return self::contar($regla, $candidatas['hoja'] ?? null);
    }

    /**
     * Cuenta las filas que la regla deja afuera, para no sumar dos veces una fila que
     * coincide con dos etiquetas.
     *
     * @param  array      $regla
     * @param  array|null $hoja
     * @return array
     */
    protected static function contar(array $regla, $hoja)
    {
        if (empty($hoja)) {
            return $regla;
        }

        try {
            foreach (self::filas_de_la_hoja($hoja) as $numero => $celdas) {
                if (!self::es_encabezado($celdas, $numero, $regla)) {
                    continue;
                }

                $regla['filas']++;

                if (count($regla['ejemplos']) < self::EJEMPLOS) {
                    $regla['ejemplos'][] = (int) $numero;
                }
            }
        } catch (\Throwable $e) {
            /* Sin poder contar no hay regla confiable: con filas en 0, guardar() no deja nada. */
            Log::warning('EncabezadosRepetidos: no se pudieron contar las filas', ['message' => $e->getMessage()]);

            $regla['filas']    = 0;
            $regla['ejemplos'] = [];
        }

        return $regla;
    }

    /**
     * Guarda la regla al lado del Excel. Sin filas para ignorar, borra la anterior.
     *
     * @param  string $excel_path
     * @param  int    $indice_hoja
     * @param  array  $regla
     * @return void
     */
    public static function guardar($excel_path, $indice_hoja, array $regla)
    {
        $ruta = self::ruta($excel_path, $indice_hoja);

        if ((int) ($regla['filas'] ?? 0) === 0) {
            @unlink($ruta);

            return;
        }

        $regla['xlsx_huella'] = CsvDeHoja::huella_del_xlsx($excel_path);

        $temporal = $ruta . '.' . uniqid('', true) . '.tmp';

        if (@file_put_contents($temporal, json_encode($regla, JSON_UNESCAPED_UNICODE)) === false || !@rename($temporal, $ruta)) {
            @unlink($temporal);

            Log::warning('EncabezadosRepetidos: no se pudo guardar la regla', ['ruta' => $ruta]);
        }
    }

    /**
     * La regla vigente de esa hoja, o null si no hay, está corrupta o es de otro archivo con
     * la misma ruta. Nunca lanza.
     *
     * @param  string $excel_path
     * @param  int    $indice_hoja
     * @return array|null
     */
    public static function leer($excel_path, $indice_hoja)
    {
        try {
            $ruta = self::ruta($excel_path, $indice_hoja);

            if (!is_file($ruta)) {
                return null;
            }

            $regla = json_decode((string) @file_get_contents($ruta), true);

            if (!is_array($regla) || !isset($regla['etiquetas'], $regla['encabezado'], $regla['fila_encabezado'])) {
                return null;
            }

            if (!is_array($regla['etiquetas']) || !is_array($regla['encabezado'])) {
                return null;
            }

            if ((string) ($regla['xlsx_huella'] ?? '') !== CsvDeHoja::huella_del_xlsx($excel_path)) {
                return null;
            }

            return $regla;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Resumen para el resultado del análisis. Siempre con las tres claves.
     *
     * @param  array|null $regla
     * @return array
     */
    public static function resumen($regla)
    {
        return [
            'filas'    => (int) ($regla['filas'] ?? 0),
            'ejemplos' => array_values($regla['ejemplos'] ?? []),
            'valores'  => array_values($regla['valores'] ?? []),
        ];
    }

    /**
     * True si la fila es un encabezado repetido según la regla.
     *
     * @param  array    $celdas
     * @param  int|null $numero_fila  número físico; null cuando quien lee no numera así
     * @param  array|null $regla
     * @return bool
     */
    public static function es_encabezado(array $celdas, $numero_fila, $regla)
    {
        if (empty($regla)) {
            return false;
        }

        if (!is_null($numero_fila) && (int) $numero_fila <= (int) $regla['fila_encabezado']) {
            return false;
        }

        foreach ($regla['etiquetas'] as $etiqueta) {
            if (!isset($celdas[$etiqueta['columna']])) {
                continue;
            }

            if (self::normalizar($celdas[$etiqueta['columna']]) !== $etiqueta['valor']) {
                continue;
            }

            /* max_celdas es lo que evita saltear un artículo real que justo usa ese código. */
            if (self::celdas_con_dato($celdas) <= (int) $etiqueta['max_celdas']) {
                return true;
            }
        }

        return !empty($regla['encabezado']) && self::repite_el_encabezado($celdas, $regla['encabezado']);
    }

    /**
     * Vacía en el CSV de una importación las filas de la regla.
     *
     * 🔴 El registro no se borra, se deja con las celdas vacías: los lotes navegan el archivo
     * por número de registro y registro N = fila N del Excel es lo que hace que los conflictos
     * reporten la fila correcta. El resto del archivo se copia byte por byte.
     *
     * @param  string     $csv
     * @param  array|null $regla
     * @return bool       true si el archivo quedó como corresponde
     */
    public static function vaciar_en_csv($csv, $regla)
    {
        if (empty($regla)) {
            return true;
        }

        $lector   = @fopen($csv, 'rb');
        $crudo    = @fopen($csv, 'rb');
        $temporal = $csv . '.' . uniqid('', true) . '.tmp';
        $salida   = @fopen($temporal, 'wb');

        if ($lector === false || $crudo === false || $salida === false) {
            self::cerrar([$lector, $crudo, $salida]);
            @unlink($temporal);

            return false;
        }

        if (fread($lector, 3) !== CsvDeHoja::BOM_UTF8) {
            rewind($lector);
        }

        $numero      = 0;
        $pendiente   = 0;
        $inicio      = ftell($lector);

        while (($celdas = fgetcsv($lector, 0, ',', '"', '')) !== false) {
            $numero++;
            $fin = ftell($lector);

            if (self::es_encabezado($celdas, $numero, $regla)) {
                fseek($crudo, $pendiente);
                stream_copy_to_stream($crudo, $salida, $inicio - $pendiente);

                /* Mismo fin de línea que el registro original. */
                fseek($crudo, $fin - 2);
                $cola = (string) fread($crudo, 2);
                $eol  = substr($cola, -2) === "\r\n" ? "\r\n" : (substr($cola, -1) === "\n" ? "\n" : '');

                fwrite($salida, str_repeat(',', max(0, count($celdas) - 1)) . $eol);

                $pendiente = $fin;
            }

            $inicio = $fin;
        }

        fseek($crudo, $pendiente);
        stream_copy_to_stream($crudo, $salida);

        self::cerrar([$lector, $crudo, $salida]);

        if (!@rename($temporal, $csv)) {
            @unlink($temporal);

            return false;
        }

        return true;
    }

    /**
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

    /**
     * Ruta de la regla: el prefijo `.hoja` hace que FinalizeArticleImport la limpie con el resto.
     *
     * @param  string $excel_path
     * @param  int    $indice_hoja
     * @return string
     */
    protected static function ruta($excel_path, $indice_hoja)
    {
        return $excel_path . '.hoja' . (int) $indice_hoja . '.encabezados.json';
    }

    /**
     * Filas de la hoja: número de fila física => celdas.
     *
     * Va por ExcelWorkbookReader::abrir(), que lee el CSV lateral si ya existe y si no el XLSX.
     * No lo genera acá a propósito: quien analiza sin pasar por el job no tiene por qué dejar
     * archivos al lado del Excel.
     *
     * @param  array $hoja ['excel' => ruta, 'indice' => int]
     * @return \Generator
     */
    protected static function filas_de_la_hoja(array $hoja)
    {
        $lectura = ExcelWorkbookReader::abrir($hoja['excel'], $hoja['indice'], true);

        try {
            $numero = 0;

            foreach ($lectura->filas() as $row) {
                $numero++;

                $celdas = [];

                foreach ($row->getCells() as $cell) {
                    $valor    = $cell->getValue();
                    $celdas[] = $valor instanceof \DateTime ? $valor->format('Y-m-d') : (string) ($valor ?? '');
                }

                yield $numero => $celdas;
            }
        } finally {
            $lectura->cerrar();
        }
    }

    /**
     * @param  array $encabezados
     * @return array posición => título normalizado, solo las columnas con título
     */
    protected static function encabezado_normalizado(array $encabezados)
    {
        $resultado = [];

        foreach ($encabezados as $posicion => $titulo) {
            $normalizado = self::normalizar($titulo);

            if ($normalizado !== '') {
                $resultado[(int) $posicion] = $normalizado;
            }
        }

        return $resultado;
    }

    /**
     * @param  int   $fila_encabezado
     * @param  array $encabezado
     * @return array
     */
    protected static function candidatas_vacias($fila_encabezado, array $encabezado)
    {
        return [
            'hoja'            => null,
            'fila_encabezado' => (int) $fila_encabezado,
            'encabezado'      => $encabezado,
            'exactas'         => 0,
            'grupos'          => [],
        ];
    }

    /**
     * @param  array $celdas
     * @param  array $encabezado
     * @return bool
     */
    protected static function repite_el_encabezado(array $celdas, array $encabezado)
    {
        $total = count($encabezado);

        if ($total < 2) {
            return false;
        }

        $coinciden = 0;

        foreach ($encabezado as $posicion => $titulo) {
            if (isset($celdas[$posicion]) && self::normalizar($celdas[$posicion]) === $titulo) {
                $coinciden++;
            }
        }

        return $coinciden >= 2 && ($coinciden / $total) >= 0.7;
    }

    /**
     * @param  mixed  $valor  celda original
     * @param  string $clave  celda normalizada
     * @return bool
     */
    protected static function puede_ser_etiqueta($valor, $clave)
    {
        return $clave !== ''
            && mb_strlen($clave) <= self::LARGO_MAXIMO_ETIQUETA
            && !preg_match('/\d/', (string) $valor);
    }

    /**
     * @param  array $celdas
     * @return int
     */
    protected static function celdas_con_dato(array $celdas)
    {
        $total = 0;

        foreach ($celdas as $celda) {
            if (self::normalizar($celda) !== '') {
                $total++;
            }
        }

        return $total;
    }

    /**
     * Mediana inferior de un histograma [celdas con dato => cantidad de filas].
     *
     * @param  array $histograma
     * @return int
     */
    protected static function mediana(array $histograma)
    {
        if (empty($histograma)) {
            return 0;
        }

        ksort($histograma);

        $posicion  = (int) floor((array_sum($histograma) - 1) / 2);
        $acumulado = 0;

        foreach ($histograma as $celdas => $filas) {
            $acumulado += $filas;

            if ($acumulado > $posicion) {
                return (int) $celdas;
            }
        }

        return 0;
    }

    /**
     * Las celdas con dato de la fila, cortadas para el prompt.
     *
     * @param  array $celdas
     * @return string
     */
    protected static function muestra(array $celdas)
    {
        $con_dato = array_filter($celdas, function ($celda) {
            return self::normalizar($celda) !== '';
        });

        return mb_substr(implode(' | ', array_map('trim', array_map('strval', $con_dato))), 0, 200);
    }

    /**
     * @param  array|null $respuesta_ia
     * @return array
     */
    protected static function confirmadas_por_la_ia($respuesta_ia)
    {
        $confirmadas = [];

        if (!is_array($respuesta_ia)) {
            return $confirmadas;
        }

        foreach ($respuesta_ia as $item) {
            if (!is_array($item) || !isset($item['valor']) || !is_scalar($item['valor'])) {
                continue;
            }

            $clave = self::normalizar($item['valor']);

            if ($clave === '') {
                continue;
            }

            /* Si la IA no dio un índice válido, vale el valor en cualquier columna candidata. */
            $columna = isset($item['columna']) && is_numeric($item['columna']) ? (int) $item['columna'] : '*';

            $confirmadas[$columna . '|' . $clave] = true;
        }

        return $confirmadas;
    }

    /**
     * @param  array $column_mapping
     * @return array posición => true
     */
    protected static function columnas_identificadoras(array $column_mapping)
    {
        $columnas = [];

        foreach ($column_mapping as $columna) {
            if (in_array($columna['system_property'] ?? null, self::PROPIEDADES_IDENTIFICADORAS, true)
                && isset($columna['excel_column_index'])) {
                $columnas[(int) $columna['excel_column_index']] = true;
            }
        }

        return $columnas;
    }

    /**
     * Minúsculas, sin acentos y con los espacios (también los duros) colapsados.
     *
     * @param  mixed $valor
     * @return string
     */
    protected static function normalizar($valor)
    {
        if (!is_scalar($valor)) {
            return '';
        }

        $texto = preg_replace('/\s+/u', ' ', (string) $valor);

        /* UTF-8 inválido: no se puede comparar, así que no coincide con nada. */
        if ($texto === null) {
            return '';
        }

        return strtr(mb_strtolower(trim($texto)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);
    }
}
