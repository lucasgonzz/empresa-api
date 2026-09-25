<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Models\Article;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Helpers\import\excel\ExcelWorkbookReader;

/**
 * Helper estático para calcular estadísticas de duplicados en un archivo Excel
 * antes de ejecutar la importación de artículos.
 *
 * Responsabilidades:
 * - Leer el Excel completo y acumular recuentos de bar_code y provider_code.
 * - Detectar valores repetidos dentro del mismo archivo (duplicados intra-archivo).
 * - Cruzar los provider_codes del Excel contra la tabla articles en base de datos.
 * - Devolver conteos y ejemplos que el caller puede pasar a Claude para
 *   generar una recomendación de configuración óptima para la importación.
 *
 * Este helper NO escribe nada en base de datos ni lanza eventos.
 * Cualquier error interno retorna el resultado vacío para no interrumpir el flujo.
 */
class ExcelDuplicateStats
{
    /**
     * Tamaño máximo de lote para la consulta whereIn de crossCheckProviderCodes().
     *
     * Tiene que quedar POR DEBAJO de `eq_range_index_dive_limit` (200 por defecto en MySQL 8).
     * MySQL solo estima bajando por el índice (index dives) cuando el IN tiene MENOS rangos que
     * ese límite: con 200 exactos ya estima con las estadísticas del índice (el manual lo dice
     * al revés: para permitir dives de hasta N valores, hay que ponerlo en N + 1). Con dives elige
     * bien `articles_user_provider_code_index`; con estadísticas, en un catálogo grande, puede
     * elegir otro plan. 100 deja margen de sobra.
     *
     * ⚠️ Es una DEFENSA, no el arreglo del corte de Servian del 23/9/2026. Ese corte lo causó la
     * mezcla de int y string en el IN (medido en producción, ver el comentario del strval en
     * crossCheckProviderCodes()): con todos los valores como string, el mismo IN de 5000 usó el
     * índice. Bajar el lote sin el strval no lo hubiera arreglado.
     *
     * Costo: 29k códigos son ~290 consultas de milisegundos cada una por índice. Si alguien
     * sube este número, que antes suba `eq_range_index_dive_limit` en todos los servidores.
     *
     * @var int
     */
    protected const DB_CHUNK_SIZE = 100;

    /**
     * Cantidad máxima de ejemplos que se incluyen en cada lista de valores duplicados.
     *
     * @var int
     */
    protected const MAX_EXAMPLES = 5;

    /**
     * Analiza el Excel y devuelve estadísticas de duplicados.
     *
     * Lee el archivo completo (solo primera hoja, saltando la cabecera) y calcula:
     * - Cuántos bar_codes y provider_codes distintos aparecen más de una vez en el archivo.
     * - Cuántos provider_codes del Excel ya existen en la BD para el mismo proveedor o para otro.
     *
     * Si ambos índices son null, retorna todos los conteos en 0 sin leer el archivo.
     * Si uno de los índices es null, los conteos de ese campo retornan 0.
     *
     * @param  string   $excel_path                          Ruta absoluta al archivo Excel
     * @param  int|null $bar_code_column_index_0based        Índice 0-based de la columna bar_code (null si no identificada)
     * @param  int|null $provider_code_column_index_0based   Índice 0-based de la columna provider_code (null si no identificada)
     * @param  int|null $provider_id                         ID del proveedor seleccionado (null o 0 si no se pudo inferir)
     * @param  int      $user_id                             ID del usuario propietario para filtrar artículos en BD
     * @param  array    $opciones                            ['hoja' => int, 'fila_encabezado' => int|null]. TODO opcional:
     *                                                       AdminSync llama a los analyzers con la firma vieja y no se toca.
     * @return array    Conteos y ejemplos según el contrato:
     *                  [
     *                      'total_filas_datos'                          => int,
     *                      'bar_codes_duplicados_intra_archivo'         => int,
     *                      'provider_codes_duplicados_intra_archivo'    => int,
     *                      'provider_codes_existentes_mismo_proveedor'  => int,
     *                      'provider_codes_existentes_otros_proveedores'=> int,
     *                      'ejemplos_bar_codes_duplicados'              => string[],
     *                      'ejemplos_provider_codes_duplicados'         => string[],
     *                      'detalle_bar_codes_duplicados'               => [['codigo','veces','filas'],...]
     *                      'detalle_provider_codes_duplicados'          => [['codigo','veces','filas'],...]
     *                      'provider_codes_distintos'                   => string[] (grupo 291, prompt 03:
     *                          todos los provider_codes distintos del archivo, no solo los duplicados;
     *                          se usa para persistir en excel_analysis_runs.codigos_proveedor y así
     *                          evitar releer el archivo en refreshProviderStats(). NUNCA debe llegar
     *                          tal cual a una respuesta HTTP: puede tener decenas de miles de strings.)
     *                  ]
     */
    public static function analyze(
        string $excel_path,
        ?int $bar_code_column_index_0based,
        ?int $provider_code_column_index_0based,
        ?int $provider_id,
        int $user_id,
        array $opciones = []
    ): array {
        $indice_hoja     = isset($opciones['hoja']) ? (int) $opciones['hoja'] : 0;
        $fila_encabezado = isset($opciones['fila_encabezado']) ? $opciones['fila_encabezado'] : null;

        /*
         * 🔴 INTERRUPTOR DE SEGURIDAD (§1.3 del plan). Con $fila_encabezado en null o en 1 se
         * corre la rama de HOY, byte por byte: preservar_filas_vacias = false, saltear la
         * primera fila del iterador y numerar $total_filas + 1. La rama nueva —números de
         * fila FÍSICOS del Excel— sólo se enciende con $fila_encabezado > 1, o sea sólo en
         * los archivos que hoy ya se leen mal.
         *
         * NO la unifiques en una sola rama "porque es lo mismo". No es lo mismo: los números
         * de fila que salen de acá son los que la pantalla le muestra al usuario al lado de
         * cada código duplicado ("aparece en las filas 12, 47 y 93"), y los dos criterios de
         * preservar_filas_vacias dan números distintos en cuanto hay una fila vacía en el
         * medio del archivo. Dejar la rama vieja intacta es lo que acota TODO el riesgo de
         * esta misión a los archivos que ya estaban rotos.
         */
        $usar_numeracion_fisica = (!is_null($fila_encabezado) && (int) $fila_encabezado > 1);
        $fila_encabezado        = is_null($fila_encabezado) ? 1 : (int) $fila_encabezado;

        /* Resultado vacío por defecto: se retorna cuando no hay columnas definidas o si ocurre un error. */
        $empty_result = [
            'total_filas_datos'                           => 0,
            'bar_codes_duplicados_intra_archivo'          => 0,
            'provider_codes_duplicados_intra_archivo'     => 0,
            'provider_codes_existentes_mismo_proveedor'   => 0,
            'provider_codes_existentes_otros_proveedores' => 0,
            'ejemplos_bar_codes_duplicados'               => [],
            'ejemplos_provider_codes_duplicados'          => [],
            /* Detalle enriquecido: código, cantidad de repeticiones y filas donde aparece. */
            'detalle_bar_codes_duplicados'                => [],
            'detalle_provider_codes_duplicados'           => [],
            /* Grupo 291, prompt 03: sin columna definida no hay códigos que listar. */
            'provider_codes_distintos'                    => [],
        ];

        /* Si no hay ningún índice definido, no tiene sentido leer el archivo. */
        if (is_null($bar_code_column_index_0based) && is_null($provider_code_column_index_0based)) {
            Log::info('ExcelDuplicateStats: sin columnas de código definidas, retornando vacío.');
            return $empty_result;
        }

        /*
         * Acumuladores PLANOS, dos por campo (misión importacion-excel-motor-rapido, 24/9/2026):
         *   $<campo>_veces[valor] = cuántas veces aparece (int)
         *   $<campo>_filas[valor] = filas donde aparece, como texto compacto "12,47,93"
         *                          (número real de fila del Excel, 1-based con cabecera;
         *                          se guardan las primeras 10, como siempre)
         *
         * Antes era un solo array de arrays por campo (['count' => N, 'filas' => [...]]) y con
         * 100.000 códigos distintos el proceso pasaba de 128 MB (medido: pico de 164 MB),
         * que es el memory_limit del php-cli de producción. Un int y un string cortos por
         * clave cuestan una fracción de dos arrays anidados por clave, y el resultado que sale
         * de acá es exactamente el mismo: el detalle se arma abajo desde estos dos.
         *
         * Las claves de $provider_code_veces sirven además para el cruce posterior contra la BD.
         */
        $bar_code_veces      = [];
        $bar_code_filas      = [];
        $provider_code_veces = [];
        $provider_code_filas = [];

        /* Contador de filas de datos procesadas (sin contar la cabecera). */
        $total_filas = 0;

        try {
            /*
             * Mismo lector XLSX de OpenSpout que InitExcelImport, ahora a través de
             * ExcelWorkbookReader para poder leer la hoja que el usuario eligió y no
             * siempre la primera.
             */
            $lectura = ExcelWorkbookReader::abrir($excel_path, $indice_hoja, $usar_numeracion_fisica);

            /* Bandera para saltar la primera fila (cabecera) en la rama vieja. */
            $header_skipped = false;

            /* Número de fila física dentro de la hoja (sólo tiene sentido en la rama nueva). */
            $numero_fila = 0;

            foreach ($lectura->filas() as $row) {
                $numero_fila++;

                if ($usar_numeracion_fisica) {
                    /* Título, razón social y filas vacías de arriba del encabezado no son datos. */
                    if ($numero_fila <= $fila_encabezado) {
                        continue;
                    }
                } else {
                    if (!$header_skipped) {
                        $header_skipped = true;
                        continue;
                    }
                }

                /* Extraemos los valores de las celdas como strings simples. */
                $cells = [];
                foreach ($row->getCells() as $cell) {
                    $value = $cell->getValue();

                    if ($value instanceof \DateTime) {
                        $value = $value->format('Y-m-d');
                    }

                    $cells[] = trim((string) ($value ?? ''));
                }

                /*
                 * En la rama nueva se lee con preservar_filas_vacias = true (para que el
                 * número de fila sea el físico), así que las filas vacías llegan igual y hay
                 * que descartarlas acá: es lo que hacía el flag en la rama vieja.
                 */
                if ($usar_numeracion_fisica && self::fila_esta_vacia($cells)) {
                    continue;
                }

                $total_filas++;

                /* Número de fila real en el Excel (1-based, incluye cabecera). */
                $excel_row_number = $usar_numeracion_fisica ? $numero_fila : ($total_filas + 1);

                /* Acumular bar_code si la columna está definida y la celda tiene contenido. */
                if (!is_null($bar_code_column_index_0based) && isset($cells[$bar_code_column_index_0based])) {
                    $bar_code_val = $cells[$bar_code_column_index_0based];
                    if ($bar_code_val !== '') {
                        self::acumular($bar_code_veces, $bar_code_filas, $bar_code_val, $excel_row_number);
                    }
                }

                /* Acumular provider_code si la columna está definida y la celda tiene contenido. */
                if (!is_null($provider_code_column_index_0based) && isset($cells[$provider_code_column_index_0based])) {
                    $provider_code_val = $cells[$provider_code_column_index_0based];
                    if ($provider_code_val !== '') {
                        self::acumular($provider_code_veces, $provider_code_filas, $provider_code_val, $excel_row_number);
                    }
                }
            }

            $lectura->cerrar();

        } catch (\Throwable $e) {
            /*
             * T9: esta degradación deja la pantalla diciendo "0 duplicados" en vez de "no se
             * pudo leer el archivo", y NO se cambia acá (está fuera del alcance de la misión).
             * Pero la hoja y la fila de encabezado sí van al contexto del log: el mismo
             * archivo se lee bien o mal según qué hoja y qué fila se le hayan pedido, así que
             * sin esos dos datos el próximo que caiga acá no puede reproducir nada.
             */
            Log::error('ExcelDuplicateStats: error al leer el Excel', [
                'message'         => $e->getMessage(),
                'file'            => $excel_path,
                'hoja'            => $indice_hoja,
                'fila_encabezado' => $fila_encabezado,
            ]);
            return $empty_result;
        }

        Log::info('ExcelDuplicateStats: Excel leído', [
            'total_filas'              => $total_filas,
            'bar_codes_distintos'      => count($bar_code_veces),
            'provider_codes_distintos' => count($provider_code_veces),
        ]);

        /*
         * Contamos cuántos valores distintos de bar_code aparecen más de una vez en el archivo.
         * Guardamos hasta MAX_EXAMPLES para debug o presentación en frontend.
         * También construimos el detalle enriquecido con filas para la tabla del frontend.
         */
        $bar_codes_duplicados = 0;
        $ejemplos_bar_codes   = [];
        $detalle_bar_codes    = [];
        foreach ($bar_code_veces as $val => $veces) {
            if ($veces > 1) {
                $bar_codes_duplicados++;
                if (count($ejemplos_bar_codes) < self::MAX_EXAMPLES) {
                    $ejemplos_bar_codes[] = (string) $val;
                }
                /* Detalle enriquecido: máximo MAX_EXAMPLES entradas para no sobrecargar la respuesta. */
                if (count($detalle_bar_codes) < self::MAX_EXAMPLES) {
                    $detalle_bar_codes[] = [
                        'codigo' => (string) $val,
                        'veces'  => $veces,
                        'filas'  => self::filas_desde_texto($bar_code_filas[$val]),
                    ];
                }
            }
        }

        /*
         * Contamos cuántos valores distintos de provider_code aparecen más de una vez en el archivo.
         * Mismo criterio que bar_code: ejemplos simples + detalle enriquecido con filas.
         */
        $provider_codes_duplicados_intra = 0;
        $ejemplos_provider_codes         = [];
        $detalle_provider_codes          = [];
        foreach ($provider_code_veces as $val => $veces) {
            if ($veces > 1) {
                $provider_codes_duplicados_intra++;
                if (count($ejemplos_provider_codes) < self::MAX_EXAMPLES) {
                    $ejemplos_provider_codes[] = (string) $val;
                }
                /* Detalle enriquecido: máximo MAX_EXAMPLES entradas. */
                if (count($detalle_provider_codes) < self::MAX_EXAMPLES) {
                    $detalle_provider_codes[] = [
                        'codigo' => (string) $val,
                        'veces'  => $veces,
                        'filas'  => self::filas_desde_texto($provider_code_filas[$val]),
                    ];
                }
            }
        }

        /* Las filas ya no hacen falta: se liberan antes del cruce contra la base. */
        unset($bar_code_filas, $provider_code_filas);

        /*
         * Cruzamos los provider_codes únicos extraídos del Excel contra la tabla articles en BD.
         * Solo si la columna provider_code existe y tiene datos para cruzar.
         *
         * (grupo 291, prompt 03) El cruce en sí se extrajo a crossCheckProviderCodes() para que
         * refreshProviderStats() pueda reusarlo sin releer el archivo, pasando directamente la
         * lista de códigos ya persistida en excel_analysis_runs.codigos_proveedor.
         */
        if (!is_null($provider_code_column_index_0based) && !empty($provider_code_veces)) {
            $cross_check = self::crossCheckProviderCodes(
                array_keys($provider_code_veces),
                $provider_id,
                $user_id
            );
            $provider_codes_mismo_proveedor   = $cross_check['provider_codes_existentes_mismo_proveedor'];
            $provider_codes_otros_proveedores = $cross_check['provider_codes_existentes_otros_proveedores'];
        } else {
            $provider_codes_mismo_proveedor   = 0;
            $provider_codes_otros_proveedores = 0;
        }

        Log::info('ExcelDuplicateStats: análisis completado', [
            'total_filas'                               => $total_filas,
            'bar_codes_duplicados_intra_archivo'        => $bar_codes_duplicados,
            'provider_codes_duplicados_intra_archivo'   => $provider_codes_duplicados_intra,
            'provider_codes_existentes_mismo_proveedor' => $provider_codes_mismo_proveedor,
            'provider_codes_existentes_otros_proveedores' => $provider_codes_otros_proveedores,
        ]);

        return [
            'total_filas_datos'                           => $total_filas,
            'bar_codes_duplicados_intra_archivo'          => $bar_codes_duplicados,
            'provider_codes_duplicados_intra_archivo'     => $provider_codes_duplicados_intra,
            'provider_codes_existentes_mismo_proveedor'   => $provider_codes_mismo_proveedor,
            'provider_codes_existentes_otros_proveedores' => $provider_codes_otros_proveedores,
            'ejemplos_bar_codes_duplicados'               => $ejemplos_bar_codes,
            'ejemplos_provider_codes_duplicados'          => $ejemplos_provider_codes,
            /* Detalle enriquecido: vacío si la columna respectiva no estaba mapeada. */
            'detalle_bar_codes_duplicados'                => !is_null($bar_code_column_index_0based) ? $detalle_bar_codes : [],
            'detalle_provider_codes_duplicados'           => !is_null($provider_code_column_index_0based) ? $detalle_provider_codes : [],
            /*
             * Grupo 291, prompt 03: todos los provider_codes distintos del archivo (no solo los
             * duplicados). El acumulador $provider_code_veces ya los tenía en memoria; antes se
             * descartaban al retornar. El caller (AiExcelAnalyzer::analyze()) es responsable de
             * sacar esta clave antes de exponer duplicate_stats por HTTP.
             */
            'provider_codes_distintos'                    => !is_null($provider_code_column_index_0based) ? array_keys($provider_code_veces) : [],
        ];
    }

    /**
     * Suma una aparición de $valor en la fila $fila a los dos acumuladores planos de un campo.
     *
     * Las filas se guardan como texto "12,47,93" y sólo las primeras 10 apariciones (el mismo
     * tope de siempre del payload): con 100.000 códigos distintos, un array de filas por código
     * es lo que hacía que el análisis pasara de 128 MB.
     *
     * @param  array  $veces  por referencia: valor => cantidad de apariciones
     * @param  array  $filas  por referencia: valor => "fila,fila,..."
     * @param  string $valor
     * @param  int    $fila   número real de fila del Excel (1-based, incluye cabecera)
     * @return void
     */
    protected static function acumular(array &$veces, array &$filas, $valor, $fila)
    {
        if (!isset($veces[$valor])) {
            $veces[$valor] = 1;
            $filas[$valor] = (string) $fila;

            return;
        }

        $veces[$valor]++;

        if ($veces[$valor] <= 10) {
            $filas[$valor] .= ',' . $fila;
        }
    }

    /**
     * Inversa de acumular(): "12,47,93" => [12, 47, 93] (enteros, como en el detalle de siempre).
     *
     * @param  string $texto
     * @return int[]
     */
    protected static function filas_desde_texto($texto)
    {
        return array_map('intval', explode(',', (string) $texto));
    }

    /**
     * Una fila es "vacía" cuando ninguna de sus celdas tiene contenido.
     *
     * Hace falta sólo en la rama de numeración física, donde se lee con
     * preservar_filas_vacias = true para que el número de fila coincida con el del Excel.
     *
     * @param  array $celdas
     * @return bool
     */
    protected static function fila_esta_vacia(array $celdas)
    {
        foreach ($celdas as $celda) {
            if (trim((string) $celda) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Cruza una lista de provider_codes ya extraída contra la tabla articles en BD,
     * sin necesidad de leer el archivo Excel (grupo 291, prompt 03).
     *
     * Extraído de analyze() para que pueda reusarse desde refreshProviderStats()
     * cuando ya existe un análisis previo con los códigos persistidos en
     * excel_analysis_runs.codigos_proveedor: en ese caso alcanza con este cruce
     * (una consulta) y no hace falta releer el archivo completo.
     *
     * Mismo criterio que el cruce original: un provider_code que tiene artículos
     * tanto en el proveedor seleccionado como en otro proveedor distinto cuenta en
     * AMBOS contadores.
     *
     * @param  string[]  $provider_codes  Lista de provider_codes distintos a cruzar contra la BD
     * @param  int|null  $provider_id     ID del proveedor seleccionado (null o 0 si no se pudo inferir)
     * @param  int       $user_id         ID del usuario propietario para filtrar artículos en BD
     * @return array     [
     *                       'provider_codes_existentes_mismo_proveedor'   => int,
     *                       'provider_codes_existentes_otros_proveedores' => int,
     *                   ]
     */
    public static function crossCheckProviderCodes(array $provider_codes, ?int $provider_id, int $user_id): array
    {
        /* Contadores de códigos distintos (no de artículos) que matchean en BD. */
        $provider_codes_mismo_proveedor   = 0;
        $provider_codes_otros_proveedores = 0;

        /* Sin códigos que cruzar, no hay nada que consultar. */
        if (empty($provider_codes)) {
            return [
                'provider_codes_existentes_mismo_proveedor'   => $provider_codes_mismo_proveedor,
                'provider_codes_existentes_otros_proveedores' => $provider_codes_otros_proveedores,
            ];
        }

        /*
         * 🔴 Todos los códigos viajan como STRING. Los códigos salen de array_keys() de un array
         * indexado por el valor de la celda, y PHP convierte en int toda clave con forma de entero
         * ("12345" → 12345); lo mismo vuelve de excel_analysis_runs.codigos_proveedor (JSON).
         * Laravel bindea un int como PDO::PARAM_INT, y MySQL, al comparar la columna varchar
         * provider_code contra un número, compara numéricamente: no puede usar el índice para ese
         * IN (warning 1739, "Cannot use range access ... due to type or collation conversion") y
         * recorre todos los artículos del usuario, con lotes de cualquier tamaño. Y encima cuenta
         * de más: "777" del Excel matchea un "0777" de la base, que la importación no matchea.
         *
         * Es la causa del corte de Servian del 23/9/2026 (568k artículos; el análisis del Excel
         * quedó en "Falló"). Medido en producción sobre la consulta real del slow log: 42 de los
         * 5000 valores del IN viajaron como enteros, el EXPLAIN dio `ref` por
         * `articles_user_id_foreign` (325k filas estimadas; 686.402 examinadas en el corte) y la
         * cortó el `max_execution_time` global del VPS (120 s). La misma consulta, con esos 42
         * valores entre comillas, tardó 0,73 s.
         */
        $provider_codes = array_map('strval', array_values($provider_codes));

        /* Partimos en lotes de DB_CHUNK_SIZE: el porqué del tamaño está en su docblock. */
        $db_chunks = array_chunk($provider_codes, self::DB_CHUNK_SIZE);

        foreach ($db_chunks as $chunk) {
            /*
             * Buscamos artículos del mismo usuario con cualquiera de esos provider_codes.
             * Solo traemos las columnas necesarias para el cruce.
             */
            $matches = Article::where('user_id', $user_id)
                ->whereIn('provider_code', $chunk)
                ->get(['provider_code', 'provider_id']);

            /*
             * Agrupar por provider_code para contar códigos distintos, no artículos individuales.
             * Para cada código, determinamos si existe en el mismo proveedor, en otro proveedor, o en ambos.
             */
            $codes_grouped = [];
            foreach ($matches as $article) {
                $code = (string) $article->provider_code;
                if (!isset($codes_grouped[$code])) {
                    $codes_grouped[$code] = ['mismo' => false, 'otro' => false];
                }

                $is_same_provider = (
                    !is_null($provider_id)
                    && (int) $provider_id > 0
                    && (int) $article->provider_id === (int) $provider_id
                );

                if ($is_same_provider) {
                    $codes_grouped[$code]['mismo'] = true;
                } else {
                    $codes_grouped[$code]['otro'] = true;
                }
            }

            foreach ($codes_grouped as $flags) {
                if ($flags['mismo']) {
                    $provider_codes_mismo_proveedor++;
                }
                if ($flags['otro']) {
                    $provider_codes_otros_proveedores++;
                }
            }
        }

        return [
            'provider_codes_existentes_mismo_proveedor'   => $provider_codes_mismo_proveedor,
            'provider_codes_existentes_otros_proveedores' => $provider_codes_otros_proveedores,
        ];
    }
}
