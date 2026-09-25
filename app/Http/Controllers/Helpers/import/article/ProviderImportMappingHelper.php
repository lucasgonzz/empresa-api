<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Address;
use App\Models\ExcelAnalysisRun;
use App\Models\PriceType;
use App\Models\ProviderImportMapping;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Configuración de columnas guardada por proveedor (misión importacion-excel-motor-rapido, 24/9/2026).
 *
 * Lo que pidió Lucas: cuando el usuario confirma el paso 2 del modal de importación con IA
 * —las columnas ya revisadas o corregidas, y el proveedor del archivo— esa configuración se
 * guarda para que la próxima vez que importe un Excel de ese proveedor la IA la tenga de
 * referencia. El caso típico: la columna "precio" del proveedor es el COSTO del negocio; el
 * usuario lo corrige una vez y a partir de ahí el sistema lo recomienda así.
 *
 * Cuatro momentos, todos en este archivo:
 *   - guardar_desde_recomendacion(): al confirmar el paso 2 (POST get-recomendacion).
 *   - inferir_por_encabezados() / buscar(): en el análisis, para elegir qué configuración aplica.
 *   - seccion_para_prompt() + aplicar(): la IA recibe la configuración como referencia y, después,
 *     lo que el usuario ya confirmó se impone de forma determinística (la IA propone; lo que el
 *     usuario corrigió manda).
 *   - resolver_para_encabezados(): al cambiar el proveedor en el paso 2 (POST refresh-provider-stats).
 *
 * 🔴 NADA DE ACÁ PUEDE VOLTEAR UNA IMPORTACIÓN. Todo método público atrapa \Throwable, deja un
 * Log::warning y devuelve el valor neutro (null o el mapeo sin tocar). Es una comodidad encima
 * del flujo, no una etapa del flujo.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción en constructor, readonly, enum ni #[...].
 */
class ProviderImportMappingHelper
{
    /** Único model_name que guarda configuración: clientes y proveedores no tienen proveedor. */
    const MODEL_NAME_ARTICLE = 'article';

    /** La columna quedó distinta de lo que la IA propuso: el usuario la cambió a mano. */
    const ORIGEN_CORREGIDO = 'corregido_por_el_usuario';

    /** La columna quedó igual a lo que la IA propuso: el usuario sólo la confirmó. */
    const ORIGEN_CONFIRMADO = 'confirmado';

    /* =====================================================================
     * Normalización y firma de encabezados
     * ================================================================== */

    /**
     * Encabezado normalizado para comparar: trim, minúsculas, sin tildes, espacios colapsados.
     *
     * Es la clave con la que se reconoce una columna de una importación a la siguiente:
     * "PRECIO", " Precio " y "précio" son la misma columna.
     *
     * @param  mixed $valor
     * @return string  '' si el encabezado está vacío
     */
    public static function normalizar_encabezado($valor)
    {
        $texto = trim((string) $valor);

        if ($texto === '') {
            return '';
        }

        $texto = mb_strtolower($texto, 'UTF-8');
        $texto = Str::ascii($texto);
        $texto = preg_replace('/\s+/', ' ', $texto);

        return trim((string) $texto);
    }

    /**
     * Firma de un conjunto de encabezados: sha1 de los encabezados normalizados, en orden.
     *
     * Dos archivos con las mismas columnas en el mismo orden tienen la misma firma aunque
     * cambien mayúsculas, tildes o espacios. Una columna más, una menos o un orden distinto
     * dan otra firma: ahí ya no se reconoce el formato y decide la IA (con la configuración
     * del proveedor que infiera, ver buscar()).
     *
     * @param  array $headers  Encabezados tal como los devuelve el detector (0-based, en orden)
     * @return string
     */
    public static function firma_de_encabezados(array $headers)
    {
        $normalizados = [];

        foreach ($headers as $header) {
            $normalizados[] = self::normalizar_encabezado($header);
        }

        return sha1(implode("\n", $normalizados));
    }

    /* =====================================================================
     * Guardar
     * ================================================================== */

    /**
     * Guarda (upsert) la configuración de columnas a partir de una corrida de recomendación
     * recién creada en POST get-recomendacion.
     *
     * Reglas (plan §2.1):
     *   - sólo si el análisis padre (payload.analysis_uuid) es de model 'article';
     *   - sólo con provider_id > 0 (el usuario eligió un proveedor en el select del paso 2);
     *   - NO se guarda si alguna columna quedó mapeada a 'proveedor' (el proveedor va por fila);
     *   - se guardan las columnas no ignoradas, con `corregida` = la propiedad confirmada
     *     difiere de la que quedó en el análisis padre para esa columna (o el análisis ya la
     *     traía como corregida en una importación anterior: la marca es pegajosa, porque sigue
     *     siendo distinta de lo que la IA diría sola);
     *   - una fila por (user_id, model_name, provider_id): upsert, veces_usado++, ultimo_uso_at.
     *
     * @param  \App\Models\ExcelAnalysisRun $run  Corrida tipo 'recomendacion' (payload con provider_id, column_mapping, analysis_uuid, header_row)
     * @return \App\Models\ProviderImportMapping|null  Lo guardado, o null si no correspondía guardar (o falló)
     */
    public static function guardar_desde_recomendacion(ExcelAnalysisRun $run)
    {
        try {
            if ($run->tipo !== 'recomendacion') {
                return null;
            }

            $payload = $run->payload ?? [];

            $provider_id = isset($payload['provider_id']) && is_numeric($payload['provider_id'])
                ? (int) $payload['provider_id']
                : 0;

            if ($provider_id <= 0) {
                return null;
            }

            $column_mapping = isset($payload['column_mapping']) && is_array($payload['column_mapping'])
                ? $payload['column_mapping']
                : [];

            if (empty($column_mapping)) {
                return null;
            }

            /*
             * El proveedor va por fila: una configuración por proveedor no tiene sentido y
             * además el select del paso 2 tendría que haber quedado en "Sin proveedor".
             */
            if (self::hay_columna_de_proveedor($column_mapping)) {
                return null;
            }

            /*
             * El análisis padre es el que sabe de qué modelo es la corrida y qué propuso la IA
             * para cada columna. Sin él no se puede decidir ninguna de las dos cosas: no se guarda.
             */
            $padre = $run->analisis_padre();

            if (is_null($padre)) {
                Log::info('ProviderImportMappingHelper: recomendación sin análisis padre, no se guarda la configuración', [
                    'excel_analysis_run_id' => $run->id,
                ]);

                return null;
            }

            $payload_padre = $padre->payload ?? [];
            $model         = (string) ($payload_padre['model'] ?? self::MODEL_NAME_ARTICLE);

            if ($model !== self::MODEL_NAME_ARTICLE) {
                return null;
            }

            $resultado_padre = $padre->resultado ?? [];

            if (!is_array($resultado_padre)) {
                $resultado_padre = [];
            }

            $propuesto = self::mapeo_propuesto_por_la_ia($resultado_padre);
            $headers   = self::encabezados_del_analisis($resultado_padre, $column_mapping);

            $columnas   = [];
            $corregidas = 0;

            foreach ($column_mapping as $posicion => $col) {
                if (!is_array($col)) {
                    continue;
                }

                $propiedad = self::propiedad_de($col);

                /* Ignorada por el usuario: no forma parte de la configuración. */
                if (is_null($propiedad)) {
                    continue;
                }

                $indice = isset($col['excel_column_index']) && is_numeric($col['excel_column_index'])
                    ? (int) $col['excel_column_index']
                    : (int) $posicion;

                $encabezado = isset($col['excel_column']) && (string) $col['excel_column'] !== ''
                    ? (string) $col['excel_column']
                    : (string) ($headers[$indice] ?? '');

                $corregida = self::columna_corregida($propiedad, $indice, $propuesto);

                if ($corregida) {
                    $corregidas++;
                }

                $columnas[] = [
                    'excel_column'             => $encabezado,
                    'excel_column_normalizada' => self::normalizar_encabezado($encabezado),
                    'excel_column_index'       => $indice,
                    'excel_column_letter'      => (string) ($col['excel_column_letter'] ?? ''),
                    'system_property'          => $propiedad,
                    'corregida'                => $corregida,
                ];
            }

            if (empty($columnas)) {
                return null;
            }

            $hoja_nombre = null;

            if (isset($resultado_padre['hoja_elegida']['nombre']) && (string) $resultado_padre['hoja_elegida']['nombre'] !== '') {
                $hoja_nombre = mb_substr((string) $resultado_padre['hoja_elegida']['nombre'], 0, 120);
            }

            $datos = [
                'headers_hash'        => self::firma_de_encabezados($headers),
                'header_row'          => isset($payload['header_row']) && is_numeric($payload['header_row'])
                                            ? (int) $payload['header_row']
                                            : null,
                'hoja_nombre'         => $hoja_nombre,
                'column_mapping'      => $columnas,
                'columnas_corregidas' => $corregidas,
                'ultimo_uso_at'       => now(),
            ];

            $mapeo = ProviderImportMapping::where('user_id', $run->user_id)
                ->where('model_name', self::MODEL_NAME_ARTICLE)
                ->where('provider_id', $provider_id)
                ->orderBy('id', 'DESC')
                ->first();

            if (!is_null($mapeo)) {
                $datos['veces_usado'] = (int) $mapeo->veces_usado + 1;
                $mapeo->update($datos);

                return $mapeo;
            }

            return ProviderImportMapping::create(array_merge($datos, [
                'user_id'     => $run->user_id,
                'model_name'  => self::MODEL_NAME_ARTICLE,
                'provider_id' => $provider_id,
                'veces_usado' => 1,
            ]));

        } catch (\Throwable $e) {
            /* Guardar la configuración es una comodidad: nunca puede frenar la recomendación. */
            Log::warning('ProviderImportMappingHelper: no se pudo guardar la configuración de columnas del proveedor', [
                'excel_analysis_run_id' => $run->id,
                'message'               => $e->getMessage(),
            ]);

            return null;
        }
    }

    /* =====================================================================
     * Buscar
     * ================================================================== */

    /**
     * Configuración guardada de un proveedor, con el proveedor cargado; null si no hay (o si el
     * proveedor ya no existe).
     *
     * @param  int    $user_id
     * @param  int    $provider_id
     * @param  string $model_name
     * @return \App\Models\ProviderImportMapping|null
     */
    public static function buscar($user_id, $provider_id, $model_name = self::MODEL_NAME_ARTICLE)
    {
        try {
            if ((int) $provider_id <= 0) {
                return null;
            }

            $mapeo = ProviderImportMapping::with('provider')
                ->where('user_id', (int) $user_id)
                ->where('model_name', $model_name)
                ->where('provider_id', (int) $provider_id)
                ->orderBy('ultimo_uso_at', 'DESC')
                ->orderBy('id', 'DESC')
                ->first();

            return self::con_proveedor_vivo($mapeo);

        } catch (\Throwable $e) {
            Log::warning('ProviderImportMappingHelper: no se pudo buscar la configuración del proveedor', [
                'user_id'     => $user_id,
                'provider_id' => $provider_id,
                'message'     => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Configuración cuya firma de encabezados coincide EXACTAMENTE con la de este archivo.
     *
     * Es lo que permite decir "reconocimos el formato" antes de llamar a la IA. Si la misma
     * firma está guardada para MÁS de un proveedor (dos proveedores que exportan con el mismo
     * sistema), no se elige a ciegas: se devuelve null y decide la IA, que después recibe la
     * configuración del proveedor que infiera por buscar().
     *
     * @param  int    $user_id
     * @param  array  $headers     Encabezados del archivo (0-based, en orden)
     * @param  string $model_name
     * @return \App\Models\ProviderImportMapping|null
     */
    public static function inferir_por_encabezados($user_id, array $headers, $model_name = self::MODEL_NAME_ARTICLE)
    {
        try {
            if (empty($headers)) {
                return null;
            }

            $candidatos = ProviderImportMapping::with('provider')
                ->where('user_id', (int) $user_id)
                ->where('model_name', $model_name)
                ->where('headers_hash', self::firma_de_encabezados($headers))
                ->orderBy('ultimo_uso_at', 'DESC')
                ->orderBy('id', 'DESC')
                ->get();

            $vivos = [];

            foreach ($candidatos as $candidato) {
                if (!is_null(self::con_proveedor_vivo($candidato))) {
                    $vivos[] = $candidato;
                }
            }

            if (count($vivos) === 1) {
                return $vivos[0];
            }

            if (count($vivos) > 1) {
                Log::info('ProviderImportMappingHelper: la firma de encabezados coincide con más de un proveedor, decide la IA', [
                    'user_id'   => $user_id,
                    'proveedores' => array_map(function ($mapeo) {
                        return (int) $mapeo->provider_id;
                    }, $vivos),
                ]);
            }

            return null;

        } catch (\Throwable $e) {
            Log::warning('ProviderImportMappingHelper: no se pudo buscar la configuración por firma de encabezados', [
                'user_id' => $user_id,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /* =====================================================================
     * Usar en el análisis
     * ================================================================== */

    /**
     * Texto para el prompt del análisis con la configuración confirmada por el usuario.
     *
     * @param  \App\Models\ProviderImportMapping|null $mapeo
     * @return string  '' si no hay configuración
     */
    public static function seccion_para_prompt($mapeo)
    {
        try {
            if (is_null($mapeo)) {
                return '';
            }

            $guardadas = self::columnas_guardadas($mapeo);

            if (empty($guardadas)) {
                return '';
            }

            $proveedor_id = (int) $mapeo->provider_id;
            $proveedor    = self::nombre_del_proveedor($mapeo);
            $fecha        = self::fecha_de($mapeo, 'd/m/Y');

            $lineas = '';

            /*
             * Encabezado y nombre del proveedor entran al prompt en una línea y recortados: vienen
             * del archivo y de la ficha, y adentro de una sección que le pide a Claude "devolvé
             * exactamente esto" un texto con saltos de línea podría leerse como otra instrucción.
             * Chequeo 3 de la misión, 24/9/2026.
             */
            $proveedor = self::texto_de_una_linea($proveedor, 80);

            foreach ($guardadas as $guardada) {
                $lineas .= '- «' . self::texto_de_una_linea($guardada['excel_column'], 80) . '» → ' . $guardada['system_property']
                    . (!empty($guardada['corregida']) ? ' (corregida por el usuario)' : '')
                    . "\n";
            }

            return <<<SECCION
## Configuración confirmada por el usuario en la importación anterior de este proveedor
La última vez que el usuario importó un archivo con estos mismos encabezados (el {$fecha}) confirmó, columna por columna, este mapeo para el proveedor ID {$proveedor_id} ({$proveedor}):
{$lineas}
Las marcadas como "corregida por el usuario" son las que el usuario cambió a mano respecto de lo que se le había propuesto: reflejan cómo trabaja este negocio (por ejemplo, la columna "precio" de un proveedor suele ser el COSTO del negocio, no su precio de venta). Para toda columna cuyo encabezado figure en esta lista, devolvé exactamente la propiedad guardada, con confidence 0.95 o más y con interpretation_note en null. Las columnas que no figuren se mapean con las reglas generales. Y salvo que el archivo diga claramente otra cosa, provider_id debe ser {$proveedor_id}.

SECCION;

        } catch (\Throwable $e) {
            Log::warning('ProviderImportMappingHelper: no se pudo armar la sección del prompt', [
                'message' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Un texto del archivo o de la ficha, en una sola línea y con un largo máximo, para
     * interpolarlo en el prompt sin que traiga saltos de línea ni párrafos enteros.
     *
     * @param  mixed $texto
     * @param  int   $largo_maximo
     * @return string
     */
    protected static function texto_de_una_linea($texto, $largo_maximo)
    {
        $texto = trim((string) preg_replace('/\s+/u', ' ', (string) $texto));

        if (mb_strlen($texto) > $largo_maximo) {
            $texto = rtrim(mb_substr($texto, 0, $largo_maximo)) . '…';
        }

        return $texto;
    }

    /**
     * Nota para el usuario cuando el formato del archivo se reconoció por la firma de encabezados.
     *
     * @param  \App\Models\ProviderImportMapping $mapeo
     * @return string
     */
    public static function nota_de_reconocimiento($mapeo)
    {
        return 'Reconocimos el formato de este archivo: es el que usaste la última vez para '
            . self::nombre_del_proveedor($mapeo) . '.';
    }

    /**
     * Aplica la configuración guardada sobre el mapeo que devolvió la IA (ya enriquecido).
     *
     * Para cada columna cuyo encabezado normalizado coincide con uno guardado, `system_property`
     * pasa a ser lo guardado y se agrega la clave `mapeo_guardado`
     * {system_property, origen, guardado_en, proveedor}. En el resto, `mapeo_guardado` queda en
     * null. En toda columna guardada la `interpretation_note` de la IA se anula, cambie o no la
     * propiedad: si cambia, la nota era sobre otra lectura de la columna; si no cambia, le pedía
     * al usuario que valide algo que ya confirmó la vez anterior. En los dos casos la línea de
     * "la última vez corregiste / confirmaste…" la reemplaza (el prompt ya le pide a Claude la
     * nota en null para estas columnas; esto lo asegura aunque no obedezca). Verificado en la
     * interfaz el 24/9/2026: "Descripcion" salía celeste con "Revisá el mapeo antes de importar"
     * al lado de «Guardado».
     *
     * Los ids codificados (address_{id}_* / price_type_{id}_*) se validan contra lo que el usuario
     * tiene HOY: un depósito o una lista que ya no existe no se aplica (queda lo de la IA y sin
     * mapeo_guardado), porque ProcessRow no podría resolverlo.
     *
     * 🔴 Es un override determinístico a propósito: la IA propone, pero lo que el usuario ya
     * corrigió una vez manda. Sin esto, la configuración sería sólo una sugerencia más al
     * prompt, y Claude puede no obedecerla.
     *
     * @param  array                                  $column_mapping  Mapeo enriquecido (excel_column, system_property, excel_column_index, …)
     * @param  \App\Models\ProviderImportMapping|null $mapeo
     * @return array  El mismo mapeo, con la clave mapeo_guardado en cada ítem
     */
    public static function aplicar(array $column_mapping, $mapeo)
    {
        $resultado = [];

        foreach ($column_mapping as $col) {
            if (is_array($col)) {
                $col['mapeo_guardado'] = null;
            }

            $resultado[] = $col;
        }

        if (is_null($mapeo)) {
            return $resultado;
        }

        try {
            $guardadas = self::columnas_guardadas($mapeo);

            if (empty($guardadas)) {
                return $resultado;
            }

            $actuales = [];

            foreach ($resultado as $posicion => $col) {
                if (!is_array($col)) {
                    continue;
                }

                $actuales[$posicion] = [
                    'indice'     => isset($col['excel_column_index']) && is_numeric($col['excel_column_index'])
                                        ? (int) $col['excel_column_index']
                                        : (int) $posicion,
                    'encabezado' => (string) ($col['excel_column'] ?? ''),
                ];
            }

            $pares = self::emparejar($actuales, $guardadas);

            if (empty($pares)) {
                return $resultado;
            }

            $ids_validos = self::ids_validos_para($mapeo, $guardadas);

            foreach ($pares as $posicion => $guardada) {
                $propiedad = self::propiedad_guardada_valida($guardada, $ids_validos);

                if (is_null($propiedad)) {
                    Log::info('ProviderImportMappingHelper: columna guardada con un depósito o lista que ya no existe, no se aplica', [
                        'excel_column'    => $guardada['excel_column'],
                        'system_property' => $guardada['system_property'],
                    ]);

                    continue;
                }

                $resultado[$posicion]['system_property']     = $propiedad;
                $resultado[$posicion]['interpretation_note'] = null;

                $resultado[$posicion]['mapeo_guardado'] = [
                    'system_property' => $propiedad,
                    'origen'          => !empty($guardada['corregida']) ? self::ORIGEN_CORREGIDO : self::ORIGEN_CONFIRMADO,
                    'guardado_en'     => self::fecha_de($mapeo, 'Y-m-d'),
                    'proveedor'       => self::nombre_del_proveedor($mapeo),
                ];
            }

        } catch (\Throwable $e) {
            Log::warning('ProviderImportMappingHelper: no se pudo aplicar la configuración guardada al mapeo', [
                'message' => $e->getMessage(),
            ]);
        }

        return $resultado;
    }

    /**
     * Configuración guardada resuelta contra los encabezados de un análisis, para la respuesta
     * de POST refresh-provider-stats (cuando el usuario cambia el proveedor en el paso 2).
     *
     * @param  \App\Models\ProviderImportMapping|null $mapeo
     * @param  array                                  $headers  Encabezados del análisis (0-based, en orden)
     * @return array|null  [{excel_column_index, excel_column, system_property, origen}] o null si no hay nada aplicable
     */
    public static function resolver_para_encabezados($mapeo, array $headers)
    {
        try {
            if (is_null($mapeo) || empty($headers)) {
                return null;
            }

            $guardadas = self::columnas_guardadas($mapeo);

            if (empty($guardadas)) {
                return null;
            }

            $actuales = [];

            foreach (array_values($headers) as $indice => $header) {
                $actuales[$indice] = [
                    'indice'     => (int) $indice,
                    'encabezado' => (string) $header,
                ];
            }

            $pares = self::emparejar($actuales, $guardadas);

            if (empty($pares)) {
                return null;
            }

            ksort($pares);

            $ids_validos = self::ids_validos_para($mapeo, $guardadas);

            $salida = [];

            foreach ($pares as $indice => $guardada) {
                $propiedad = self::propiedad_guardada_valida($guardada, $ids_validos);

                if (is_null($propiedad)) {
                    continue;
                }

                $salida[] = [
                    'excel_column_index' => (int) $indice,
                    'excel_column'       => $actuales[$indice]['encabezado'],
                    'system_property'    => $propiedad,
                    'origen'             => !empty($guardada['corregida']) ? self::ORIGEN_CORREGIDO : self::ORIGEN_CONFIRMADO,
                ];
            }

            return empty($salida) ? null : $salida;

        } catch (\Throwable $e) {
            Log::warning('ProviderImportMappingHelper: no se pudo resolver la configuración contra los encabezados', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * True si alguna columna del mapeo quedó asignada a 'proveedor' (el proveedor va por fila).
     *
     * @param  array $column_mapping
     * @return bool
     */
    public static function hay_columna_de_proveedor(array $column_mapping)
    {
        foreach ($column_mapping as $col) {
            if (is_array($col) && self::propiedad_de($col) === 'proveedor') {
                return true;
            }
        }

        return false;
    }

    /* =====================================================================
     * Internos
     * ================================================================== */

    /**
     * Propiedad del sistema de un ítem del mapeo, normalizada: null si está ignorada; los
     * alias planos (codigo_proveedor → codigo_de_proveedor, etc.) pasan por el normalizador del
     * importador; las codificadas (address_* / price_type_*) van tal cual.
     *
     * @param  array $col
     * @return string|null
     */
    protected static function propiedad_de(array $col)
    {
        $propiedad = $col['system_property'] ?? null;

        if (is_null($propiedad) || trim((string) $propiedad) === '') {
            return null;
        }

        $propiedad = trim((string) $propiedad);

        if (strpos($propiedad, 'address_') === 0 || strpos($propiedad, 'price_type_') === 0) {
            return $propiedad;
        }

        $normalizada = ArticleImportColumnsNormalizer::normalize_property_key($propiedad);

        return is_null($normalizada) ? null : (string) $normalizada;
    }

    /**
     * Lo que quedó en el análisis padre por columna, indexado por excel_column_index:
     * ['system_property' => string|null, 'mapeo_guardado' => array|null].
     *
     * @param  array $resultado_padre
     * @return array
     */
    protected static function mapeo_propuesto_por_la_ia(array $resultado_padre)
    {
        $propuesto = [];

        $mapeo = isset($resultado_padre['column_mapping']) && is_array($resultado_padre['column_mapping'])
            ? $resultado_padre['column_mapping']
            : [];

        foreach ($mapeo as $posicion => $col) {
            if (!is_array($col)) {
                continue;
            }

            $indice = isset($col['excel_column_index']) && is_numeric($col['excel_column_index'])
                ? (int) $col['excel_column_index']
                : (int) $posicion;

            $propuesto[$indice] = [
                'system_property' => self::propiedad_de($col),
                'mapeo_guardado'  => isset($col['mapeo_guardado']) && is_array($col['mapeo_guardado'])
                                        ? $col['mapeo_guardado']
                                        : null,
            ];
        }

        return $propuesto;
    }

    /**
     * Encabezados con los que se corrió el análisis padre, en el mismo orden que usa la firma.
     * Si el detector no los dejó (fila de encabezado fuera de su ventana), se reconstruyen con
     * los excel_column del mapeo confirmado, por índice.
     *
     * @param  array $resultado_padre
     * @param  array $column_mapping
     * @return array
     */
    protected static function encabezados_del_analisis(array $resultado_padre, array $column_mapping)
    {
        if (isset($resultado_padre['encabezado_detectado']['columnas'])
            && is_array($resultado_padre['encabezado_detectado']['columnas'])
            && !empty($resultado_padre['encabezado_detectado']['columnas'])) {
            return array_values($resultado_padre['encabezado_detectado']['columnas']);
        }

        $por_indice = [];

        foreach ($column_mapping as $posicion => $col) {
            if (!is_array($col)) {
                continue;
            }

            $indice = isset($col['excel_column_index']) && is_numeric($col['excel_column_index'])
                ? (int) $col['excel_column_index']
                : (int) $posicion;

            $por_indice[$indice] = (string) ($col['excel_column'] ?? '');
        }

        if (empty($por_indice)) {
            return [];
        }

        $headers = [];
        $ultimo  = max(array_keys($por_indice));

        for ($i = 0; $i <= $ultimo; $i++) {
            $headers[] = isset($por_indice[$i]) ? $por_indice[$i] : '';
        }

        return $headers;
    }

    /**
     * Una columna está "corregida" si la propiedad confirmada difiere de la que quedó en el
     * análisis, o si el análisis ya la traía aplicada desde una configuración anterior con
     * origen "corregido" y el usuario la dejó así: sigue siendo distinta de lo que la IA diría
     * sola, y ese dato es el que le sirve a la próxima importación.
     *
     * @param  string $propiedad  Propiedad confirmada por el usuario
     * @param  int    $indice     excel_column_index de la columna
     * @param  array  $propuesto  Salida de mapeo_propuesto_por_la_ia()
     * @return bool
     */
    protected static function columna_corregida($propiedad, $indice, array $propuesto)
    {
        if (!isset($propuesto[$indice])) {
            return false;
        }

        if ($propuesto[$indice]['system_property'] !== $propiedad) {
            return true;
        }

        $guardado = $propuesto[$indice]['mapeo_guardado'];

        return is_array($guardado)
            && (string) ($guardado['origen'] ?? '') === self::ORIGEN_CORREGIDO
            && (string) ($guardado['system_property'] ?? '') === (string) $propiedad;
    }

    /**
     * Columnas guardadas de una configuración, saneadas (solo las que tienen encabezado o
     * índice y propiedad). El encabezado normalizado se recalcula por si cambió la regla.
     *
     * @param  \App\Models\ProviderImportMapping $mapeo
     * @return array
     */
    protected static function columnas_guardadas($mapeo)
    {
        $columnas = $mapeo->column_mapping;

        if (!is_array($columnas)) {
            return [];
        }

        $saneadas = [];

        foreach ($columnas as $col) {
            if (!is_array($col)) {
                continue;
            }

            $propiedad = self::propiedad_de($col);

            if (is_null($propiedad)) {
                continue;
            }

            $encabezado = (string) ($col['excel_column'] ?? '');

            $saneadas[] = [
                'excel_column'             => $encabezado,
                'excel_column_normalizada' => self::normalizar_encabezado($encabezado),
                'excel_column_index'       => isset($col['excel_column_index']) && is_numeric($col['excel_column_index'])
                                                ? (int) $col['excel_column_index']
                                                : null,
                'system_property'          => $propiedad,
                'corregida'                => !empty($col['corregida']),
            ];
        }

        return $saneadas;
    }

    /**
     * Empareja las columnas actuales con las guardadas.
     *
     * Por encabezado normalizado; si el mismo nombre se repite (cabecera fusionada sobre varias
     * columnas), gana la guardada que además coincide en índice y, si no hay, la primera que
     * quede libre, en orden. Una columna sin nombre sólo se empareja con una guardada sin nombre
     * y del mismo índice. Cada guardada se usa una sola vez.
     *
     * @param  array $actuales   [posicion => ['indice' => int, 'encabezado' => string]]
     * @param  array $guardadas  Salida de columnas_guardadas()
     * @return array  [posicion => guardada]
     */
    protected static function emparejar(array $actuales, array $guardadas)
    {
        $por_nombre = [];

        foreach ($guardadas as $i => $guardada) {
            $clave = $guardada['excel_column_normalizada'];

            if (!isset($por_nombre[$clave])) {
                $por_nombre[$clave] = [];
            }

            $por_nombre[$clave][] = $i;
        }

        $usadas = [];
        $pares  = [];

        foreach ($actuales as $posicion => $actual) {
            $clave = self::normalizar_encabezado($actual['encabezado']);

            if (!isset($por_nombre[$clave])) {
                continue;
            }

            $elegida = null;

            /* Sin nombre: sólo por índice. Con nombre: índice primero, después la primera libre. */
            foreach ($por_nombre[$clave] as $i) {
                if (isset($usadas[$i])) {
                    continue;
                }

                if ($guardadas[$i]['excel_column_index'] === $actual['indice']) {
                    $elegida = $i;
                    break;
                }
            }

            if (is_null($elegida) && $clave !== '') {
                foreach ($por_nombre[$clave] as $i) {
                    if (!isset($usadas[$i])) {
                        $elegida = $i;
                        break;
                    }
                }
            }

            if (is_null($elegida)) {
                continue;
            }

            $usadas[$elegida] = true;
            $pares[$posicion] = $guardadas[$elegida];
        }

        return $pares;
    }

    /**
     * Ids de depósitos y listas de precio que el usuario tiene HOY, sólo si alguna columna
     * guardada los necesita (dos consultas chicas, y nada si no hay codificadas).
     *
     * @param  \App\Models\ProviderImportMapping $mapeo
     * @param  array                             $guardadas
     * @return array  ['addresses' => [int...], 'price_types' => [int...]]
     */
    protected static function ids_validos_para($mapeo, array $guardadas)
    {
        $necesita_addresses   = false;
        $necesita_price_types = false;

        foreach ($guardadas as $guardada) {
            if (strpos($guardada['system_property'], 'address_') === 0) {
                $necesita_addresses = true;
            }

            if (strpos($guardada['system_property'], 'price_type_') === 0) {
                $necesita_price_types = true;
            }
        }

        $ids = [
            'addresses'   => [],
            'price_types' => [],
        ];

        if ($necesita_addresses) {
            $ids['addresses'] = Address::where('user_id', (int) $mapeo->user_id)
                ->pluck('id')
                ->map(function ($id) {
                    return (int) $id;
                })
                ->all();
        }

        if ($necesita_price_types) {
            /*
             * Mismo criterio que AiExcelAnalyzer::get_available_price_types(): si el dueño no
             * usa listas de precio, ninguna lista es válida aunque queden filas en la tabla.
             */
            $owner = User::find((int) $mapeo->user_id);

            if (UserHelper::uses_listas_de_precio($owner)) {
                $ids['price_types'] = PriceType::where('user_id', (int) $mapeo->user_id)
                    ->pluck('id')
                    ->map(function ($id) {
                        return (int) $id;
                    })
                    ->all();
            }
        }

        return $ids;
    }

    /**
     * Propiedad guardada lista para aplicar, o null si referencia un depósito o una lista que
     * el usuario ya no tiene.
     *
     * @param  array $guardada
     * @param  array $ids_validos  Salida de ids_validos_para()
     * @return string|null
     */
    protected static function propiedad_guardada_valida(array $guardada, array $ids_validos)
    {
        $propiedad = $guardada['system_property'];

        if (preg_match('/^address_(\d+)_(amount|min|max)$/', $propiedad, $m)) {
            return in_array((int) $m[1], $ids_validos['addresses'], true) ? $propiedad : null;
        }

        if (preg_match('/^price_type_(\d+)_(final_price|percentage|setear)$/', $propiedad, $m)) {
            return in_array((int) $m[1], $ids_validos['price_types'], true) ? $propiedad : null;
        }

        /* Cualquier otra codificada que no tenga la forma esperada no se aplica. */
        if (strpos($propiedad, 'address_') === 0 || strpos($propiedad, 'price_type_') === 0) {
            return null;
        }

        return $propiedad;
    }

    /**
     * La configuración con su proveedor cargado, o null si el proveedor ya no existe (SoftDeletes).
     *
     * @param  \App\Models\ProviderImportMapping|null $mapeo
     * @return \App\Models\ProviderImportMapping|null
     */
    protected static function con_proveedor_vivo($mapeo)
    {
        if (is_null($mapeo)) {
            return null;
        }

        if (!$mapeo->relationLoaded('provider')) {
            $mapeo->load('provider');
        }

        return is_null($mapeo->provider) ? null : $mapeo;
    }

    /**
     * @param  \App\Models\ProviderImportMapping $mapeo
     * @return string
     */
    protected static function nombre_del_proveedor($mapeo)
    {
        if (!$mapeo->relationLoaded('provider')) {
            $mapeo->load('provider');
        }

        return is_null($mapeo->provider) ? 'este proveedor' : (string) $mapeo->provider->name;
    }

    /**
     * Fecha de la última confirmación (ultimo_uso_at, o updated_at si no hay), formateada.
     *
     * @param  \App\Models\ProviderImportMapping $mapeo
     * @param  string                            $formato
     * @return string|null
     */
    protected static function fecha_de($mapeo, $formato)
    {
        $fecha = $mapeo->ultimo_uso_at;

        if (is_null($fecha)) {
            $fecha = $mapeo->updated_at;
        }

        return is_null($fecha) ? null : $fecha->format($formato);
    }
}
