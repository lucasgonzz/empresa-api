<?php

namespace App\Http\Controllers\Pdf\ArticleTicket;

/**
 * Disposición de una etiqueta individual (misión etiquetas-individuales-sin-partir, 4/10/2026):
 * qué líneas van en la etiqueta, con qué letra y en qué `y`, calculado ANTES de dibujar.
 *
 * Por qué existe: `ArticleBarCodeEtiquetasPdf` estimaba el alto del contenido con una cuenta que no
 * coincidía con el corte real de `MultiCell` y, si no entraba, el salto de página automático de
 * FPDF mandaba el resto de la etiqueta a otra hoja. Una etiqueta nunca se parte: si no entra, se
 * achica o se recorta adentro de la misma etiqueta.
 *
 * Es una clase PURA (sin FPDF, sin base, sin Laravel): recibe la medida, las propiedades ya
 * normalizadas y los textos, y devuelve un array. 🔴 El MISMO algoritmo está portado a JS en
 * `empresa-spa` (`etiquetas-individuales/disposicion.js`) para la vista previa del modal, y los dos
 * lados se comparan caso por caso: mismas líneas, mismos tamaños, mismas `y`. Por eso las cuentas
 * se hacen en el mismo orden que el pseudocódigo del plan
 * (`_cruzado/misiones/20261004-etiquetas-individuales-sin-partir/plan.md`) y no se "mejoran" acá
 * sin cambiar también el port de JS.
 *
 * Secuencia (se queda con el primer intento que entra en `alto - 2`):
 *
 *   1. Modo normal, letra pedida, código pedido (lo de siempre, si entra).
 *   2. Modo compacto (alto de línea = tamaño * 1,2) achicando la letra de a 5 %.
 *   3. Con la letra del paso 2, achicando el código de barras de a 5 % hasta 4 mm.
 *   4. Recortando con "..." el bloque de texto con más líneas, de a una línea.
 *   5. Dejando afuera los bloques de abajo, de a uno.
 *
 * PHP 7.4 estricto: ninguna sintaxis de PHP 8 en ningún camino de este archivo.
 */
class DisposicionDeEtiquetaIndividual
{
    /** Milímetros por punto tipográfico. Solo lo usa la vista previa; el ancho usa la fórmula de FPDF. */
    const MM_POR_PT = 25.4 / 72;

    /** Margen de arriba y de abajo (mm): el alto disponible es `alto - 2`. */
    const MARGEN_VERTICAL = 1;

    /** Margen de cada costado para el texto (mm, el `cMargin` de FPDF): el ancho útil es `ancho - 2`. */
    const MARGEN_TEXTO = 1;

    /** Alto de línea en modo compacto: `tamano_mm * 1.2`. */
    const FACTOR_LINEA = 1.2;

    /** Letra mínima (pt) a la que se achica un texto antes de recortarlo. */
    const FUENTE_MINIMA = 5;

    /** Alto mínimo (mm) al que se achica el código de barras. */
    const CODIGO_MINIMO = 4;

    /** Lo que se agrega al final de una línea recortada. */
    const PUNTOS = '...';

    /**
     * Tablas de anchos de Helvetica de FPDF (`$cw`, indexadas por `chr(i)`), cacheadas por proceso.
     * Clave `normal` (helvetica.php) y `negrita` (helveticab.php).
     *
     * @var array<string, array<string, int>>
     */
    protected static $anchos = array();

    /**
     * Calcula la disposición de una etiqueta.
     *
     * @param  int|float  $ancho         Ancho de la etiqueta (mm).
     * @param  int|float  $alto          Alto de la etiqueta (mm).
     * @param  array      $propiedades   Propiedades ya normalizadas, en orden: `[{key, font_size, negrita}]`.
     * @param  int|float  $codigo_alto   Alto pedido para el código de barras (mm).
     * @param  int|float  $interlineado  Espacio pedido entre bloques (mm).
     * @param  array      $textos        `key => texto` (UTF-8) de cada propiedad de texto.
     * @param  bool       $tiene_codigo  Si el artículo tiene código de barras.
     * @return array  `{bloques, alto_total, y_inicio, modo, factor, codigo_alto, interlineado,
     *                  ajustado, recortado, omitidos}` (ver `resultado()`).
     */
    public static function calcular($ancho, $alto, array $propiedades, $codigo_alto, $interlineado, array $textos, $tiene_codigo)
    {
        /* Todo lo que no cambia entre un intento y otro: se arma una vez y viaja a `armar()`. */
        $contexto = array(
            'ancho'        => $ancho,
            'alto'         => $alto,
            'propiedades'  => $propiedades,
            'interlineado' => $interlineado,
            'tiene_codigo' => (bool) $tiene_codigo,
            /* Lo que hace hoy print_bar_code: min(ancho - 4, 75), y nunca más que ancho - 6. */
            'ancho_codigo' => min(min($ancho - 4, 75), $ancho - 6),
            'textos'       => self::normalizar_textos($propiedades, $textos),
        );

        /* Alto disponible: la etiqueta menos el margen de arriba y el de abajo. */
        $alto_disponible = $alto - 2 * self::MARGEN_VERTICAL;

        /*
         * Paso 1: modo normal, letra y código pedidos. Si entra, la etiqueta sale como siempre.
         */
        $intento = self::armar($contexto, 'normal', 1, $codigo_alto, array(), array());

        if (self::entra_sin_partir_palabras($intento, $alto_disponible)) {
            return self::resultado($contexto, $intento, false, array());
        }

        /*
         * Paso 2: modo compacto achicando la letra de a 5 %. Sin bloques de texto no hace nada (y la
         * letra queda en el 100 %).
         */
        $f_final = 1;

        if (self::cantidad_de_bloques_de_texto($intento) > 0) {
            $k = 0;

            while (true) {
                /* Factor de letra de este intento: 1, 0.95, 0.9, … */
                $f = (100 - 5 * $k) / 100;

                $intento = self::armar($contexto, 'compacto', $f, $codigo_alto, array(), array());
                $f_final = $f;

                if (self::entra_sin_partir_palabras($intento, $alto_disponible)) {
                    return self::resultado($contexto, $intento, true, array());
                }

                /* Ya no se puede achicar más: todas las letras en el mínimo, o el factor tocó fondo. */
                if (self::todas_las_letras_en_el_minimo($intento) || $f <= 0.05) {
                    break;
                }

                $k++;
            }
        }

        /*
         * Paso 3: con la letra del paso 2, el código de barras de a 5 % hasta CODIGO_MINIMO. Solo si
         * hay bloque de código y el pedido es más grande que el mínimo.
         */
        $codigo_final = $codigo_alto;

        if (self::tiene_bloque_de_codigo($intento) && $codigo_alto > self::CODIGO_MINIMO) {
            $k = 1;

            while (true) {
                /* Alto del código de este intento: el pedido al 95 %, 90 %, … sin bajar del mínimo. */
                $c = max(self::CODIGO_MINIMO, $codigo_alto * (100 - 5 * $k) / 100);

                $intento = self::armar($contexto, 'compacto', $f_final, $c, array(), array());
                $codigo_final = $c;

                if ($intento['alto_total'] <= $alto_disponible) {
                    return self::resultado($contexto, $intento, true, array());
                }

                if ($c <= self::CODIGO_MINIMO) {
                    break;
                }

                $k++;
            }
        }

        /*
         * Paso 4: recortes. Mientras no entre, al bloque de texto con MÁS líneas (más de una; en
         * empate, el primero en orden) se le baja su máximo a sus líneas menos una.
         */
        /* `key => máximo de líneas` de cada bloque recortado. */
        $recortes = array();

        $intento = self::armar($contexto, 'compacto', $f_final, $codigo_final, $recortes, array());

        while ($intento['alto_total'] > $alto_disponible) {
            /* El bloque elegido para perder una línea (null si ninguno tiene más de una). */
            $elegido = null;
            /* Cuántas líneas tiene el elegido: arranca en 1 para que solo cuenten los de 2 o más. */
            $mas_lineas = 1;

            foreach ($intento['bloques'] as $bloque) {
                if ($bloque['tipo'] === 'texto' && count($bloque['lineas']) > $mas_lineas) {
                    $elegido = $bloque;
                    $mas_lineas = count($bloque['lineas']);
                }
            }

            if (is_null($elegido)) {
                break;
            }

            $recortes[$elegido['key']] = count($elegido['lineas']) - 1;

            $intento = self::armar($contexto, 'compacto', $f_final, $codigo_final, $recortes, array());
        }

        if ($intento['alto_total'] <= $alto_disponible) {
            return self::resultado($contexto, $intento, true, array());
        }

        /*
         * Paso 5: último recurso. Mientras no entre y queden bloques, se deja afuera el ÚLTIMO.
         */
        /* Keys de los bloques que quedaron afuera, en el orden en que se sacaron. */
        $omitidos = array();

        while ($intento['alto_total'] > $alto_disponible && count($intento['bloques']) > 0) {
            $ultimo = $intento['bloques'][count($intento['bloques']) - 1];
            $omitidos[] = $ultimo['key'];

            $intento = self::armar($contexto, 'compacto', $f_final, $codigo_final, $recortes, $omitidos);
        }

        return self::resultado($contexto, $intento, true, $omitidos);
    }

    /*
     * ---------------------------------------------------------------------------------------------
     *  Un intento
     * ---------------------------------------------------------------------------------------------
     */

    /**
     * Arma los bloques de un intento y su alto total (todavía sin `y`).
     *
     * @param  array      $contexto      Lo armado en `calcular()`.
     * @param  string     $modo          `normal` | `compacto`.
     * @param  int|float  $f             Factor de letra (solo en modo compacto).
     * @param  int|float  $codigo_alto   Alto del código de barras de este intento (mm).
     * @param  array      $recortes      `key => máximo de líneas`.
     * @param  string[]   $omitidos      Keys que no se dibujan.
     * @return array  `{bloques, alto_total, modo, factor, codigo_alto, interlineado}`.
     */
    protected static function armar(array $contexto, $modo, $f, $codigo_alto, array $recortes, array $omitidos)
    {
        $bloques = array();

        /* Si alguna palabra quedó partida por letras en este intento (y a la letra mínima entraría). */
        $parte_una_palabra = false;

        /* Ancho útil para el texto: la etiqueta menos el margen de cada costado. */
        $ancho_texto = $contexto['ancho'] - 2 * self::MARGEN_TEXTO;

        foreach ($contexto['propiedades'] as $propiedad) {
            $key = $propiedad['key'];

            if (in_array($key, $omitidos, true)) {
                continue;
            }

            if ($key === 'codigo_barras') {
                if (!$contexto['tiene_codigo']) {
                    continue;
                }

                $bloques[] = array(
                    'tipo'  => 'codigo',
                    'key'   => $key,
                    'alto'  => $codigo_alto,
                    'ancho' => $contexto['ancho_codigo'],
                );

                continue;
            }

            /* El texto ya normalizado (sin espacios repetidos ni saltos de línea). */
            $texto = isset($contexto['textos'][$key]) ? $contexto['textos'][$key] : '';

            if ($texto === '') {
                continue;
            }

            $font_size = $propiedad['font_size'];
            $negrita = !empty($propiedad['negrita']);

            /* Tamaño de letra de este intento (pt). */
            $pt = ($modo === 'normal') ? $font_size : max(self::FUENTE_MINIMA, $font_size * $f);

            $lineas = self::envolver($texto, $pt, $negrita, $ancho_texto);

            /*
             * Una palabra más ancha que la etiqueta queda partida por letras: "$15.432,1" / "0" en
             * un precio, o "ESP" / "ATUL" / "A" en un nombre. Mientras la letra se pueda achicar eso
             * no cuenta como que entra (ver `entra_sin_partir_palabras`). Si esa palabra ni a
             * FUENTE_MINIMA entra, se parte igual: no tiene sentido llevar todas las letras al
             * mínimo por ella. (envolver() corta por letras exactamente las palabras más anchas que
             * la línea.)
             */
            foreach (explode(' ', $texto) as $palabra) {
                if (self::ancho_de_texto($palabra, $pt, $negrita) > $ancho_texto
                    && self::ancho_de_texto($palabra, self::FUENTE_MINIMA, $negrita) <= $ancho_texto) {
                    $parte_una_palabra = true;
                }
            }

            $recortado = false;

            if (isset($recortes[$key]) && count($lineas) > $recortes[$key]) {
                $lineas = array_slice($lineas, 0, $recortes[$key]);

                $ultima = count($lineas) - 1;
                $lineas[$ultima] = self::con_puntos($lineas[$ultima], $pt, $negrita, $ancho_texto);

                $recortado = true;
            }

            /*
             * Alto de cada línea: en modo normal, el de siempre (`max(4, floor(pt * 0.55))`); en
             * compacto, el tamaño real de la letra en mm por FACTOR_LINEA.
             */
            $alto_linea = ($modo === 'normal')
                ? max(4, floor($font_size * 0.55))
                : self::tamano_mm($pt) * self::FACTOR_LINEA;

            $bloques[] = array(
                'tipo'       => 'texto',
                'key'        => $key,
                'lineas'     => $lineas,
                'tamano'     => $pt,
                'negrita'    => $negrita,
                'alto_linea' => $alto_linea,
                'alto'       => count($lineas) * $alto_linea,
                'recortado'  => $recortado,
            );
        }

        /* Espacio entre bloques de este intento: en compacto se achica con la letra. */
        $inter = ($modo === 'normal') ? $contexto['interlineado'] : $contexto['interlineado'] * $f;

        /* Suma de los altos de los bloques, en orden (mismo orden de sumas que en JS). */
        $suma_de_altos = 0;

        foreach ($bloques as $bloque) {
            $suma_de_altos += $bloque['alto'];
        }

        return array(
            'bloques'      => $bloques,
            'alto_total'   => $suma_de_altos + $inter * max(0, count($bloques) - 1),
            'modo'         => $modo,
            'factor'       => $f,
            'codigo_alto'  => $codigo_alto,
            'interlineado' => $inter,
            /* Solo para decidir si el intento sirve; no viaja al resultado. */
            'parte_una_palabra' => $parte_una_palabra,
        );
    }

    /**
     * Si un intento de los pasos 1 y 2 sirve: entra en el alto y no parte por letras una palabra
     * que a la letra mínima entraría. Lo segundo se perdona recién con todas las letras en el mínimo, porque ahí
     * ya no hay letra más chica que probar (y un código de 13 dígitos en una etiqueta angosta
     * tiene que salir igual).
     *
     * @param array $intento Lo que devuelve `armar()`.
     * @param float $alto_disponible Alto de la etiqueta menos los márgenes (mm).
     *
     * @return bool
     */
    protected static function entra_sin_partir_palabras(array $intento, $alto_disponible)
    {
        if ($intento['alto_total'] > $alto_disponible) {
            return false;
        }

        return !$intento['parte_una_palabra'] || self::todas_las_letras_en_el_minimo($intento);
    }

    /**
     * El resultado final: cada bloque con su `y` (y la `x` del código), centrado en la etiqueta.
     *
     * @param  array     $contexto
     * @param  array     $intento   El intento que quedó (lo que devolvió `armar()`).
     * @param  bool      $ajustado  Si no entró en el intento 1.
     * @param  string[]  $omitidos
     * @return array
     */
    protected static function resultado(array $contexto, array $intento, $ajustado, array $omitidos)
    {
        /* Primera `y`: el contenido centrado en la etiqueta. */
        $y_inicio = ($contexto['alto'] - $intento['alto_total']) / 2;

        /* Σ (alto + interlineado) de los bloques anteriores al que se está ubicando. */
        $acumulado = 0;

        /* Si algún bloque quedó recortado con "...". */
        $recortado = false;

        $bloques = array();

        foreach ($intento['bloques'] as $bloque) {
            $y = $y_inicio + $acumulado;

            if ($bloque['tipo'] === 'codigo') {
                $bloques[] = array(
                    'tipo'  => 'codigo',
                    'key'   => $bloque['key'],
                    'y'     => $y,
                    'alto'  => $bloque['alto'],
                    'ancho' => $bloque['ancho'],
                    'x'     => ($contexto['ancho'] - $bloque['ancho']) / 2,
                );
            } else {
                $bloques[] = array(
                    'tipo'       => 'texto',
                    'key'        => $bloque['key'],
                    'y'          => $y,
                    'alto'       => $bloque['alto'],
                    'lineas'     => $bloque['lineas'],
                    'tamano'     => $bloque['tamano'],
                    'negrita'    => $bloque['negrita'],
                    'alto_linea' => $bloque['alto_linea'],
                    'recortado'  => $bloque['recortado'],
                );

                if ($bloque['recortado']) {
                    $recortado = true;
                }
            }

            $acumulado = $acumulado + ($bloque['alto'] + $intento['interlineado']);
        }

        return array(
            'bloques'      => $bloques,
            'alto_total'   => $intento['alto_total'],
            'y_inicio'     => $y_inicio,
            'modo'         => $intento['modo'],
            'factor'       => $intento['factor'],
            'codigo_alto'  => $intento['codigo_alto'],
            'interlineado' => $intento['interlineado'],
            'ajustado'     => (bool) $ajustado,
            'recortado'    => $recortado,
            'omitidos'     => $omitidos,
        );
    }

    /**
     * Cuántos bloques de texto tiene un intento.
     *
     * @param  array  $intento
     * @return int
     */
    protected static function cantidad_de_bloques_de_texto(array $intento)
    {
        $cantidad = 0;

        foreach ($intento['bloques'] as $bloque) {
            if ($bloque['tipo'] === 'texto') {
                $cantidad++;
            }
        }

        return $cantidad;
    }

    /**
     * Si todos los bloques de texto del intento ya están en FUENTE_MINIMA.
     *
     * Se compara por valor (`==`), no por tipo: `max(5, 4.5)` es el entero 5 y `max(5, 5.0)`
     * también, pero un tamaño intermedio es float. En JS es el mismo número.
     *
     * @param  array  $intento
     * @return bool
     */
    protected static function todas_las_letras_en_el_minimo(array $intento)
    {
        foreach ($intento['bloques'] as $bloque) {
            if ($bloque['tipo'] === 'texto' && $bloque['tamano'] != self::FUENTE_MINIMA) {
                return false;
            }
        }

        return true;
    }

    /**
     * Si el intento tiene el bloque del código de barras.
     *
     * @param  array  $intento
     * @return bool
     */
    protected static function tiene_bloque_de_codigo(array $intento)
    {
        foreach ($intento['bloques'] as $bloque) {
            if ($bloque['tipo'] === 'codigo') {
                return true;
            }
        }

        return false;
    }

    /*
     * ---------------------------------------------------------------------------------------------
     *  Texto
     * ---------------------------------------------------------------------------------------------
     */

    /**
     * Los textos de las propiedades, normalizados una sola vez.
     *
     * @param  array  $propiedades
     * @param  array  $textos  `key => texto`
     * @return array  `key => texto normalizado`
     */
    protected static function normalizar_textos(array $propiedades, array $textos)
    {
        $normalizados = array();

        foreach ($propiedades as $propiedad) {
            $key = $propiedad['key'];

            $normalizados[$key] = self::normalizar(isset($textos[$key]) ? $textos[$key] : '');
        }

        return $normalizados;
    }

    /**
     * Toda secuencia de espacios en blanco (incluidos los saltos de línea) pasa a un espacio, y
     * se sacan los de los extremos.
     *
     * Primero el reemplazo y después el `trim`: con `/u`, `\s` también agarra el espacio duro
     * (U+00A0), que el `trim` de PHP no saca y el de JS sí. En este orden los dos lados dan igual
     * para todo espacio común; difieren solo en tres caracteres raros (U+0085 y U+180E los toma
     * PHP y no JS; U+FEFF, al revés), donde la vista previa puede cortar distinto que el PDF.
     *
     * @param  string|null  $texto
     * @return string
     */
    public static function normalizar($texto)
    {
        $texto = (string) $texto;

        $limpio = preg_replace('/\s+/u', ' ', $texto);

        /*
         * Con UTF-8 inválido, `preg_replace` con `/u` devuelve null: antes que perder el texto
         * entero, se normalizan solo los espacios ASCII. (En JS un string siempre es UTF-16
         * válido, así que este camino no tiene par del otro lado.)
         */
        if (is_null($limpio)) {
            $limpio = preg_replace('/\s+/', ' ', $texto);
        }

        return trim($limpio);
    }

    /**
     * Tamaño de la letra en mm (el `FontSize` de FPDF en mm: `pt / k`, con `k = 72 / 25.4`).
     *
     * @param  int|float  $pt
     * @return float
     */
    public static function tamano_mm($pt)
    {
        return $pt / (72 / 25.4);
    }

    /**
     * Ancho de un texto en mm, exactamente como `FPDF::GetStringWidth()` después del `utf8_decode()`
     * que hace `Cell()`: la suma de los anchos de cada byte latin1 por el tamaño en mm, sobre 1000.
     *
     * @param  string     $texto    UTF-8.
     * @param  int|float  $pt
     * @param  bool       $negrita
     * @return float
     */
    public static function ancho_de_texto($texto, $pt, $negrita)
    {
        $anchos = self::anchos($negrita);

        /* Los bytes latin1 que va a dibujar Cell(): lo que no existe en latin1 queda como '?'. */
        $bytes = utf8_decode((string) $texto);

        /* Suma de los anchos de cada caracter, en milésimas de la letra. */
        $suma = 0;

        $largo = strlen($bytes);

        for ($i = 0; $i < $largo; $i++) {
            $suma += $anchos[$bytes[$i]];
        }

        return $suma * self::tamano_mm($pt) / 1000;
    }

    /**
     * Corte por palabras, greedy: cada línea suma palabras mientras entren en `$ancho_max`. Una
     * palabra que sola no entra en una línea se corta por caracteres.
     *
     * @param  string     $texto      Ya normalizado (palabras separadas por un espacio).
     * @param  int|float  $pt
     * @param  bool       $negrita
     * @param  int|float  $ancho_max  mm.
     * @return string[]
     */
    public static function envolver($texto, $pt, $negrita, $ancho_max)
    {
        $lineas = array();

        /* La línea que se está armando. */
        $linea = '';

        foreach (explode(' ', $texto) as $palabra) {
            /* La línea con esta palabra agregada. */
            $candidato = ($linea === '') ? $palabra : $linea.' '.$palabra;

            if (self::ancho_de_texto($candidato, $pt, $negrita) <= $ancho_max) {
                $linea = $candidato;
                continue;
            }

            if ($linea !== '') {
                $lineas[] = $linea;
                $linea = '';
            }

            if (self::ancho_de_texto($palabra, $pt, $negrita) <= $ancho_max) {
                $linea = $palabra;
                continue;
            }

            /* La palabra sola es más ancha que la línea: se corta por caracteres (puntos de código). */
            $trozo = '';

            foreach (self::caracteres($palabra) as $c) {
                if ($trozo !== '' && self::ancho_de_texto($trozo.$c, $pt, $negrita) > $ancho_max) {
                    $lineas[] = $trozo;
                    $trozo = $c;
                } else {
                    $trozo = $trozo.$c;
                }
            }

            $linea = $trozo;
        }

        if ($linea !== '') {
            $lineas[] = $linea;
        }

        return $lineas;
    }

    /**
     * La línea con "..." al final, sacándole caracteres del final hasta que la línea con los puntos
     * entre en `$ancho_max`. Si no queda nada, son solo los puntos.
     *
     * @param  string     $linea
     * @param  int|float  $pt
     * @param  bool       $negrita
     * @param  int|float  $ancho_max  mm.
     * @return string
     */
    public static function con_puntos($linea, $pt, $negrita, $ancho_max)
    {
        /* Lo que queda de la línea antes de los puntos. */
        $base = rtrim($linea);

        while ($base !== '' && self::ancho_de_texto($base.self::PUNTOS, $pt, $negrita) > $ancho_max) {
            $base = rtrim(mb_substr($base, 0, mb_strlen($base, 'UTF-8') - 1, 'UTF-8'));
        }

        return $base.self::PUNTOS;
    }

    /**
     * Los caracteres de un texto UTF-8, por punto de código (el `Array.from()` de JS).
     *
     * @param  string  $texto
     * @return string[]
     */
    protected static function caracteres($texto)
    {
        if ($texto === '') {
            return array();
        }

        return mb_str_split($texto, 1, 'UTF-8');
    }

    /**
     * La tabla de anchos de Helvetica de FPDF (normal o negrita), leída una sola vez por proceso.
     *
     * @param  bool  $negrita
     * @return array<string, int>  `chr(i) => ancho`
     */
    protected static function anchos($negrita)
    {
        $clave = $negrita ? 'negrita' : 'normal';

        if (!isset(self::$anchos[$clave])) {
            self::$anchos[$clave] = self::leer_tabla_de_anchos($negrita ? 'helveticab.php' : 'helvetica.php');
        }

        return self::$anchos[$clave];
    }

    /**
     * Lee el `$cw` de un archivo de fuente de FPDF. Se incluye adentro de un método para que las
     * variables del archivo (`$cw`, `$name`, `$uv`, …) queden en este alcance y no ensucien nada.
     *
     * @param  string  $archivo  `helvetica.php` | `helveticab.php`
     * @return array<string, int>
     */
    protected static function leer_tabla_de_anchos($archivo)
    {
        $cw = array();

        include __DIR__.'/../../CommonLaravel/fpdf/font/'.$archivo;

        return $cw;
    }
}
