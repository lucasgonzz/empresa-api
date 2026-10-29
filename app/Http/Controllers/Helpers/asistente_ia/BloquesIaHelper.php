<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use Illuminate\Support\Facades\Log;

/**
 * Los bloques que una respuesta del asistente lleva además del texto, en la app «ComercioCity IA»
 * (misión app-ia-y-memoria, 29/10/2026, formato `bloques` del §3 del plan): gráficos, tablas e imágenes
 * generadas. Los arma este API (las herramientas mostrar_grafico, mostrar_tabla y pedir_imagen) y los
 * dibuja la app.
 *
 * 🔴 ESTE ES EL ÚNICO LUGAR QUE DICE QUÉ ES UN BLOQUE VÁLIDO. Las herramientas validan con grafico() y
 * tabla() y le devuelven al modelo el motivo exacto para que corrija; la columna `ai_messages.bloques`
 * pasa todo por normalizar() antes de guardar, y lo que no cumple se descarta con un log, sin romper la
 * respuesta. Los topes (§3): 1–8 series; ≤ 500 datos por serie; torta/dona con una sola serie;
 * dispersión con `puntos [[x,y],…]` (≤ 500) en vez de `datos`; tabla ≤ 12 columnas y ≤ 300 filas;
 * ≤ 6 bloques por mensaje. Los valores son NÚMEROS: un "1.234,50" no es un dato.
 *
 * Los textos largos se recortan (un título de 130 caracteres no merece perder el gráfico); los datos
 * que no entran o no son números NO se recortan ni se arreglan: se rechazan, porque un gráfico con la
 * mitad de los datos dice otra cosa.
 *
 * `categorias`: el contrato dice "≤ 100 strings". Se toma como el largo de CADA etiqueta (≤ 100
 * caracteres); la cantidad tiene el mismo techo que los datos de una serie (500), porque cada dato es
 * de una categoría.
 */
class BloquesIaHelper
{
    const MAX_BLOQUES = 6;

    const MAX_SERIES = 8;

    const MAX_DATOS = 500;

    const MAX_COLUMNAS = 12;

    const MAX_FILAS = 300;

    const MAX_TITULO = 120;

    const MAX_SUBTITULO = 200;

    const MAX_CATEGORIA = 100;

    const MAX_NOMBRE_DE_SERIE = 60;

    const MAX_TITULO_DE_EJE = 60;

    const MAX_ETIQUETA = 60;

    const MAX_CLAVE = 40;

    /** Techo de una celda de texto de una tabla. */
    const MAX_CELDA = 300;

    const MAX_PROMPT = 4000;

    const MAX_ERROR = 500;

    const TIPO_GRAFICO = 'grafico';

    const TIPO_TABLA = 'tabla';

    const TIPO_IMAGEN = 'imagen_generada';

    const TIPOS_DE_GRAFICO = ['barras', 'barras_horizontales', 'barras_apiladas', 'lineas', 'area', 'torta', 'dona', 'dispersion'];

    /** Los gráficos de una sola serie (las porciones de un total). */
    const GRAFICOS_DE_UNA_SERIE = ['torta', 'dona'];

    const FORMATOS_DE_EJE = ['moneda', 'numero', 'porcentaje'];

    const MONEDAS = ['ARS', 'USD'];

    const FORMATOS_DE_COLUMNA = ['texto', 'numero', 'moneda', 'porcentaje', 'fecha'];

    const FORMATOS_NUMERICOS = ['numero', 'moneda', 'porcentaje'];

    const ESTADOS_DE_IMAGEN = ['pendiente', 'generando', 'lista', 'fallida', 'no_disponible'];

    const TAMANOS = ['cuadrada', 'vertical', 'horizontal'];

    /**
     * Valida y normaliza un gráfico (lo que manda mostrar_grafico, o el `grafico` de un bloque
     * guardado). Devuelve `['bloque' => array|null, 'error' => string|null]`.
     *
     * @param  mixed  $entrada
     * @return array{bloque: array|null, error: string|null}
     */
    public static function grafico($entrada): array
    {
        if (!is_array($entrada)) {

            return self::error('el gráfico tiene que ser un objeto');
        }

        $tipo = (string) ($entrada['tipo'] ?? '');

        if (!in_array($tipo, self::TIPOS_DE_GRAFICO, true)) {

            return self::error('`tipo` tiene que ser uno de: ' . implode(', ', self::TIPOS_DE_GRAFICO));
        }

        $titulo = self::texto($entrada['titulo'] ?? '', self::MAX_TITULO);

        if ($titulo === '') {

            return self::error('falta `titulo`');
        }

        $series = isset($entrada['series']) && is_array($entrada['series']) ? array_values($entrada['series']) : [];

        if (count($series) < 1 || count($series) > self::MAX_SERIES) {

            return self::error('tiene que haber entre 1 y ' . self::MAX_SERIES . ' series');
        }

        if (in_array($tipo, self::GRAFICOS_DE_UNA_SERIE, true) && count($series) !== 1) {

            return self::error('un gráfico de ' . $tipo . ' lleva una sola serie (las porciones son las categorías)');
        }

        $es_dispersion = $tipo === 'dispersion';

        $categorias = [];

        if (!$es_dispersion) {

            $crudas = isset($entrada['categorias']) && is_array($entrada['categorias']) ? array_values($entrada['categorias']) : [];

            if (!count($crudas)) {

                return self::error('faltan `categorias` (el eje X o las etiquetas de cada porción)');
            }

            if (count($crudas) > self::MAX_DATOS) {

                return self::error('hay más de ' . self::MAX_DATOS . ' categorías');
            }

            foreach ($crudas as $categoria) {

                if (!is_scalar($categoria)) {

                    return self::error('cada categoría tiene que ser un texto');
                }

                $categorias[] = self::texto($categoria, self::MAX_CATEGORIA);
            }
        }

        $normalizadas = [];

        foreach ($series as $i => $serie) {

            if (!is_array($serie)) {

                return self::error('la serie ' . ($i + 1) . ' tiene que ser un objeto');
            }

            $nombre = self::texto($serie['nombre'] ?? '', self::MAX_NOMBRE_DE_SERIE);

            if ($nombre === '') {
                $nombre = 'Serie ' . ($i + 1);
            }

            if ($es_dispersion) {

                $puntos = self::puntos($serie['puntos'] ?? null);

                if (is_string($puntos)) {

                    return self::error('la serie "' . $nombre . '": ' . $puntos);
                }

                $normalizadas[] = ['nombre' => $nombre, 'puntos' => $puntos];

                continue;
            }

            $datos = self::datos($serie['datos'] ?? null);

            if (is_string($datos)) {

                return self::error('la serie "' . $nombre . '": ' . $datos);
            }

            if (count($datos) !== count($categorias)) {

                return self::error('la serie "' . $nombre . '" tiene ' . count($datos) . ' datos y hay ' . count($categorias)
                    . ' categorías: tiene que haber un dato por categoría (null si falta)');
            }

            $normalizadas[] = ['nombre' => $nombre, 'datos' => $datos];
        }

        $eje_x = isset($entrada['eje_x']) && is_array($entrada['eje_x']) ? $entrada['eje_x'] : [];
        $eje_y = isset($entrada['eje_y']) && is_array($entrada['eje_y']) ? $entrada['eje_y'] : [];

        $formato = (string) ($eje_y['formato'] ?? 'numero');

        if (!in_array($formato, self::FORMATOS_DE_EJE, true)) {
            $formato = 'numero';
        }

        $moneda = isset($entrada['moneda']) ? strtoupper(trim((string) $entrada['moneda'])) : '';

        if (!in_array($moneda, self::MONEDAS, true)) {
            $moneda = $formato === 'moneda' ? 'ARS' : null;
        }

        return [
            'bloque' => [
                'tipo'    => self::TIPO_GRAFICO,
                'grafico' => [
                    'tipo'       => $tipo,
                    'titulo'     => $titulo,
                    'subtitulo'  => self::texto_o_null($entrada['subtitulo'] ?? null, self::MAX_SUBTITULO),
                    'categorias' => $categorias,
                    'series'     => $normalizadas,
                    'eje_x'      => ['titulo' => self::texto_o_null($eje_x['titulo'] ?? null, self::MAX_TITULO_DE_EJE)],
                    'eje_y'      => [
                        'titulo'  => self::texto_o_null($eje_y['titulo'] ?? null, self::MAX_TITULO_DE_EJE),
                        'formato' => $formato,
                    ],
                    'moneda'     => $moneda,
                ],
            ],
            'error'  => null,
        ];
    }

    /**
     * Valida y normaliza una tabla (lo que manda mostrar_tabla, o la `tabla` de un bloque guardado).
     *
     * @param  mixed  $entrada
     * @return array{bloque: array|null, error: string|null}
     */
    public static function tabla($entrada): array
    {
        if (!is_array($entrada)) {

            return self::error('la tabla tiene que ser un objeto');
        }

        $titulo = self::texto($entrada['titulo'] ?? '', self::MAX_TITULO);

        if ($titulo === '') {

            return self::error('falta `titulo`');
        }

        $crudas = isset($entrada['columnas']) && is_array($entrada['columnas']) ? array_values($entrada['columnas']) : [];

        if (count($crudas) < 1 || count($crudas) > self::MAX_COLUMNAS) {

            return self::error('tiene que haber entre 1 y ' . self::MAX_COLUMNAS . ' columnas');
        }

        $columnas = [];
        $formatos = [];

        foreach ($crudas as $i => $columna) {

            if (!is_array($columna)) {

                return self::error('la columna ' . ($i + 1) . ' tiene que ser un objeto');
            }

            $clave = trim((string) ($columna['clave'] ?? ''));

            if ($clave === '' || mb_strlen($clave) > self::MAX_CLAVE || !preg_match('/^[A-Za-z0-9_]+$/', $clave)) {

                return self::error('la columna ' . ($i + 1) . ' necesita una `clave` corta de letras, números o guión bajo');
            }

            if (isset($formatos[$clave])) {

                return self::error('la clave "' . $clave . '" está repetida');
            }

            $formato = (string) ($columna['formato'] ?? 'texto');

            if (!in_array($formato, self::FORMATOS_DE_COLUMNA, true)) {
                $formato = 'texto';
            }

            $etiqueta = self::texto($columna['etiqueta'] ?? '', self::MAX_ETIQUETA);

            $formatos[$clave] = $formato;

            $columnas[] = [
                'clave'    => $clave,
                'etiqueta' => $etiqueta === '' ? $clave : $etiqueta,
                'formato'  => $formato,
            ];
        }

        $filas_crudas = isset($entrada['filas']) && is_array($entrada['filas']) ? array_values($entrada['filas']) : [];

        if (count($filas_crudas) > self::MAX_FILAS) {

            return self::error('hay más de ' . self::MAX_FILAS . ' filas; mostrá las más importantes');
        }

        $filas = [];

        foreach ($filas_crudas as $n => $fila) {

            if (!is_array($fila)) {

                return self::error('la fila ' . ($n + 1) . ' tiene que ser un objeto con las claves de las columnas');
            }

            $normalizada = [];

            foreach ($formatos as $clave => $formato) {

                $valor = array_key_exists($clave, $fila) ? $fila[$clave] : null;

                if (is_null($valor) || $valor === '') {
                    $normalizada[$clave] = null;
                    continue;
                }

                if (in_array($formato, self::FORMATOS_NUMERICOS, true)) {

                    if (!self::es_numero($valor)) {

                        return self::error('en la fila ' . ($n + 1) . ', "' . $clave . '" tiene que ser un número sin formato (ej. 1234.5), no "'
                            . mb_substr(is_scalar($valor) ? (string) $valor : '?', 0, 30) . '"');
                    }

                    $normalizada[$clave] = $valor + 0;
                    continue;
                }

                if (!is_scalar($valor)) {

                    return self::error('en la fila ' . ($n + 1) . ', "' . $clave . '" tiene que ser un texto o un número');
                }

                $normalizada[$clave] = self::texto($valor, self::MAX_CELDA);
            }

            $filas[] = $normalizada;
        }

        return [
            'bloque' => [
                'tipo'  => self::TIPO_TABLA,
                'tabla' => [
                    'titulo'   => $titulo,
                    'columnas' => $columnas,
                    'filas'    => $filas,
                ],
            ],
            'error'  => null,
        ];
    }

    /**
     * Un bloque de imagen generada, normalizado. Null si no tiene uuid o el estado no vale.
     *
     * @param  mixed  $entrada
     * @return array<string, mixed>|null
     */
    public static function imagen($entrada)
    {
        if (!is_array($entrada)) {

            return null;
        }

        $uuid   = trim((string) ($entrada['uuid'] ?? ''));
        $estado = (string) ($entrada['estado'] ?? '');

        if ($uuid === '' || mb_strlen($uuid) > 36 || !in_array($estado, self::ESTADOS_DE_IMAGEN, true)) {

            return null;
        }

        $tamano = (string) ($entrada['tamano'] ?? 'cuadrada');

        if (!in_array($tamano, self::TAMANOS, true)) {
            $tamano = 'cuadrada';
        }

        return [
            'tipo'   => self::TIPO_IMAGEN,
            'uuid'   => $uuid,
            'estado' => $estado,
            'prompt' => self::texto($entrada['prompt'] ?? '', self::MAX_PROMPT),
            'tamano' => $tamano,
            'url'    => $estado === 'lista' ? 'ia-imagenes/' . $uuid : null,
            'error'  => self::texto_o_null($entrada['error'] ?? null, self::MAX_ERROR),
        ];
    }

    /**
     * Normaliza una lista de bloques entera (lo que se guarda en la columna). Lo que no cumple se
     * descarta con un log; más de MAX_BLOQUES, los primeros. Devuelve [] si no queda nada.
     *
     * @param  mixed  $bloques
     * @return array<int, array<string, mixed>>
     */
    public static function normalizar($bloques): array
    {
        if (is_string($bloques)) {
            $bloques = json_decode($bloques, true);
        }

        if (!is_array($bloques)) {

            return [];
        }

        $normalizados = [];

        foreach (array_values($bloques) as $bloque) {

            if (count($normalizados) >= self::MAX_BLOQUES) {
                Log::warning('BloquesIaHelper: más de ' . self::MAX_BLOQUES . ' bloques en un mensaje; se descartan los de más.');
                break;
            }

            $normalizado = self::uno($bloque);

            if (is_null($normalizado)) {
                Log::warning('BloquesIaHelper: se descartó un bloque que no cumple el formato.', [
                    'tipo' => is_array($bloque) ? ($bloque['tipo'] ?? null) : gettype($bloque),
                ]);
                continue;
            }

            $normalizados[] = $normalizado;
        }

        return $normalizados;
    }

    /**
     * Lo que el modelo lee de los bloques de una respuesta anterior suya, una línea por bloque
     * ("[Mostraste el gráfico «Ventas de la semana»]"). Sin esto, a "¿y el de la semana pasada?" no
     * sabría de qué gráfico le hablan: el bloque viaja aparte del texto. Los datos NO viajan (los vuelve
     * a consultar si los necesita: el historial no es la fuente de los números). '' si no hay.
     *
     * @param  mixed  $bloques
     * @return string
     */
    public static function lineas_de_historial($bloques): string
    {
        if (!is_array($bloques) || !count($bloques)) {

            return '';
        }

        $lineas = [];

        foreach ($bloques as $bloque) {

            if (!is_array($bloque)) {
                continue;
            }

            $tipo = (string) ($bloque['tipo'] ?? '');

            if ($tipo === self::TIPO_GRAFICO) {
                $lineas[] = '[Mostraste el gráfico «' . (string) ($bloque['grafico']['titulo'] ?? '') . '»]';
            } elseif ($tipo === self::TIPO_TABLA) {
                $lineas[] = '[Mostraste la tabla «' . (string) ($bloque['tabla']['titulo'] ?? '') . '»]';
            } elseif ($tipo === self::TIPO_IMAGEN) {
                $lineas[] = '[Pediste una imagen: «' . mb_substr((string) ($bloque['prompt'] ?? ''), 0, 120) . '» · estado: ' . (string) ($bloque['estado'] ?? '') . ']';
            }
        }

        return implode("\n", $lineas);
    }

    /**
     * Un bloque cualquiera, normalizado, o null.
     *
     * @param  mixed  $bloque
     * @return array<string, mixed>|null
     */
    protected static function uno($bloque)
    {
        if (!is_array($bloque)) {

            return null;
        }

        $tipo = (string) ($bloque['tipo'] ?? '');

        if ($tipo === self::TIPO_GRAFICO) {

            return self::grafico($bloque['grafico'] ?? null)['bloque'];
        }

        if ($tipo === self::TIPO_TABLA) {

            return self::tabla($bloque['tabla'] ?? null)['bloque'];
        }

        if ($tipo === self::TIPO_IMAGEN) {

            return self::imagen($bloque);
        }

        return null;
    }

    /**
     * Los datos de una serie (números o null), o el motivo del rechazo.
     *
     * @param  mixed  $datos
     * @return array<int, float|int|null>|string
     */
    protected static function datos($datos)
    {
        if (!is_array($datos) || !count($datos)) {

            return 'faltan `datos` (una lista de números)';
        }

        if (count($datos) > self::MAX_DATOS) {

            return 'tiene más de ' . self::MAX_DATOS . ' datos';
        }

        $limpios = [];

        foreach (array_values($datos) as $dato) {

            if (is_null($dato)) {
                $limpios[] = null;
                continue;
            }

            if (!self::es_numero($dato)) {

                return 'los datos tienen que ser números sin formato (ej. 1234.5), no "' . mb_substr(is_scalar($dato) ? (string) $dato : '?', 0, 30) . '"';
            }

            $limpios[] = $dato + 0;
        }

        return $limpios;
    }

    /**
     * Los puntos de una serie de dispersión ([[x, y], …]), o el motivo del rechazo.
     *
     * @param  mixed  $puntos
     * @return array<int, array<int, float|int>>|string
     */
    protected static function puntos($puntos)
    {
        if (!is_array($puntos) || !count($puntos)) {

            return 'en un gráfico de dispersión cada serie lleva `puntos` ([[x, y], …])';
        }

        if (count($puntos) > self::MAX_DATOS) {

            return 'tiene más de ' . self::MAX_DATOS . ' puntos';
        }

        $limpios = [];

        foreach (array_values($puntos) as $punto) {

            if (!is_array($punto) || count($punto) !== 2) {

                return 'cada punto tiene que ser [x, y]';
            }

            $par = array_values($punto);

            if (!self::es_numero($par[0]) || !self::es_numero($par[1])) {

                return 'los puntos tienen que ser números sin formato';
            }

            $limpios[] = [$par[0] + 0, $par[1] + 0];
        }

        return $limpios;
    }

    /**
     * true si el valor es un número: int/float, o un texto numérico SIN formato ("1234.5", no "1.234,50"
     * ni "$ 1.234").
     *
     * @param  mixed  $valor
     * @return bool
     */
    protected static function es_numero($valor): bool
    {
        if (is_int($valor) || is_float($valor)) {

            return is_finite((float) $valor);
        }

        if (is_string($valor)) {

            return (bool) preg_match('/^-?\d+(\.\d+)?$/', trim($valor));
        }

        return false;
    }

    /**
     * Un texto recortado (y trim), '' si no es texto.
     *
     * @param  mixed  $valor
     * @param  int  $maximo
     * @return string
     */
    protected static function texto($valor, $maximo): string
    {
        if (!is_scalar($valor)) {

            return '';
        }

        return mb_substr(trim((string) $valor), 0, (int) $maximo);
    }

    /**
     * Como texto(), pero null cuando queda vacío.
     *
     * @param  mixed  $valor
     * @param  int  $maximo
     * @return string|null
     */
    protected static function texto_o_null($valor, $maximo)
    {
        $texto = self::texto($valor, $maximo);

        return $texto === '' ? null : $texto;
    }

    /**
     * @param  string  $motivo
     * @return array{bloque: null, error: string}
     */
    protected static function error($motivo): array
    {
        return ['bloque' => null, 'error' => $motivo];
    }
}
