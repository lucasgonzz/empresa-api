<?php

namespace App\Http\Controllers\Helpers\import\relaciones;

use Illuminate\Support\Str;

/**
 * La parte determinista del chequeo de nombres parecidos de la importación de Excel (misión
 * importacion-relaciones-parecidas, 8/10/2026): claves de comparación, candidatos parecidos,
 * agrupado de valores nuevos entre sí y armado de los ítems que ve el usuario en el paso 3.
 *
 * Es PURA: no toca la base ni la IA. Recibe los valores del Excel, los modelos existentes y las
 * equivalencias recordadas, y devuelve qué hay que preguntar. RelacionesParecidasChequeo orquesta
 * (lee el Excel, carga existentes, llama a la IA) y RelacionesParecidasIa juzga los candidatos.
 *
 * Dos capas, en este orden:
 *   1. Clave fuerte: "DEL NORTE", "Del norte", "DELNORTE" y "Del Nórte" son la misma escritura
 *      (`delnorte`). Certeza alta, sin IA.
 *   2. Candidatos parecidos (barato, sin IA): contención, Levenshtein relativo y tokens en común.
 *      Esto NO decide nada: solo arma la lista corta que la IA juzga. "Caño 1/2" y "Caño 3/4" son
 *      candidatos a propósito: lo que los separa es un dato (la medida) y eso lo ve la IA, no una
 *      distancia de caracteres.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class RelacionesParecidasDetector
{
    /** Levenshtein relativo mínimo (1 - distancia / largo) para ser candidato. */
    const UMBRAL_LEVENSHTEIN = 0.7;

    /** Jaccard de tokens mínimo para ser candidato. */
    const UMBRAL_TOKENS = 0.5;

    /** Largo mínimo (en la clave fuerte) del texto contenido para que la contención cuente. */
    const LARGO_MINIMO_CONTENCION = 4;

    /** Largo mínimo del token más corto para que "prefijo de" cuente como coincidencia. */
    const LARGO_MINIMO_PREFIJO = 4;

    /** Hasta este largo un texto es "corto": ahí manda la distancia absoluta (<= 1), no la relativa. */
    const LARGO_CORTO = 4;

    /** Peso de un token que coincide solo por prefijo ("ferret" ~ "ferreteria") contra uno idéntico. */
    const PESO_PREFIJO = 0.75;

    /** Candidatos parecidos por valor, como máximo. */
    const MAX_CANDIDATOS = 3;

    /** Con más valores distintos que esto en una columna, solo corre la clave fuerte. */
    const TOPE_VALORES_POR_COLUMNA = 2000;

    /** Puntaje desde el que un parecido se muestra igual cuando la IA no se pudo consultar. */
    const PUNTAJE_ALTO = 0.85;

    /**
     * Comparaciones completas (puntaje_entre) por corrida, como máximo. Agotado el presupuesto, los
     * valores que faltan quedan solo con la capa determinista y la corrida sale `recortado`.
     */
    const PRESUPUESTO_COMPARACIONES = 150000;

    /**
     * Pares que pasan el bloqueo y se comparan en serio, por valor, como máximo: los que más
     * bigramas comparten. Solo se muestran 3 candidatos; comparar 30 alcanza y sobra.
     */
    const MAX_COMPARACIONES_POR_VALOR = 20;

    /** Fracción mínima de bigramas compartidos (sobre el más corto de los dos) para comparar un par. */
    const MINIMO_BIGRAMAS_COMPARTIDOS = 0.3;

    const MOTIVO_MISMA_ESCRITURA = 'misma_escritura';
    const MOTIVO_PARECIDO        = 'parecido';
    const MOTIVO_RECORDADO       = 'recordado';
    const MOTIVO_ENTRE_NUEVOS    = 'entre_nuevos';
    const MOTIVO_POSIBLE         = 'posible';

    const EXPLICACION_MISMA_ESCRITURA = 'Solo cambian mayúsculas, tildes o espacios';
    const EXPLICACION_PARECIDO        = 'Parece el mismo nombre escrito de otra forma';
    const EXPLICACION_RECORDADO       = 'Lo confirmaste en una importación anterior';
    const EXPLICACION_NUEVOS_ESCRITURA = 'En el archivo aparece escrito de varias formas: solo cambian mayúsculas, tildes o espacios';
    const EXPLICACION_NUEVOS_PARECIDO  = 'En el archivo aparece escrito de varias formas parecidas';
    const EXPLICACION_POSIBLE          = 'No se pudo consultar a la IA; revisá estos nombres parecidos';

    /** La IA cree que puede ser el mismo pero no está segura (%s = el nombre que eligió). */
    const EXPLICACION_DUDOSA           = 'Podría ser «%s» escrito de otra forma; si es el mismo, elegilo';

    /* =====================================================================
     * Claves de comparación
     * ================================================================== */

    /**
     * Clave fuerte: sin tildes, en minúscula y sin nada que no sea letra o número.
     *
     * "DEL NORTE", "Del norte", "DELNORTE" y "Del Nórte" dan `delnorte`. Dos valores con la misma
     * clave fuerte son la misma escritura y se preguntan sin IA, con "es el existente" marcado.
     *
     * Si el valor es solo símbolos ("-", "***") la clave daría vacía y todos esos valores
     * colisionarían entre sí: en ese caso cae a la forma en minúscula con trim.
     *
     * @param  mixed $valor
     * @return string
     */
    public static function clave_fuerte($valor)
    {
        $limpio = trim((string) $valor);

        $fuerte = preg_replace('/[^a-z0-9]/', '', mb_strtolower(Str::ascii($limpio), 'UTF-8'));

        if (is_string($fuerte) && $fuerte !== '') {
            return $fuerte;
        }

        return mb_strtolower($limpio, 'UTF-8');
    }

    /**
     * Clave de cache del worker: minúscula, con trim y con los espacios internos colapsados.
     *
     * 🔴 mb_strtolower y no strtolower: strtolower deja intactos los bytes de la Ñ y de las
     * vocales con tilde en mayúscula, y "CAÑERÍA" no daba la misma clave que "cañería" (la
     * importación creaba una categoría duplicada). Sí distingue tildes ("pintureria" no es
     * "pinturería"): sacar tildes es cosa de la clave fuerte, que pregunta; esta resuelve sola.
     *
     * @param  mixed $valor
     * @return string
     */
    public static function clave_cache($valor)
    {
        $texto = trim((string) $valor);

        $colapsado = preg_replace('/\s+/u', ' ', $texto);

        /* Con /u, preg_replace devuelve null ante UTF-8 inválido: se usa el texto tal cual. */
        if (is_string($colapsado)) {
            $texto = $colapsado;
        }

        return mb_strtolower($texto, 'UTF-8');
    }

    /**
     * La clave con la que el WHERE de MySQL (collation utf8mb4_unicode_ci) considera iguales dos
     * nombres, aproximada: sin tildes, en minúscula, con trim y espacios colapsados. La usa
     * EquivalenciasResolver con clientes y proveedores, cuyo camino de siempre busca con ese WHERE.
     *
     * @param  mixed $valor
     * @return string
     */
    public static function clave_sin_tildes($valor)
    {
        return self::clave_cache(Str::ascii(trim((string) $valor)));
    }

    /**
     * Forma "legible" para comparar: sin tildes, minúscula, espacios colapsados, signos conservados.
     *
     * Levenshtein corre sobre esta forma ADEMÁS de sobre la clave fuerte porque la clave fuerte se
     * come los separadores: "Caño 1/2" y "Caño 3/4" dan `cano12` y `cano34` (dos de seis
     * caracteres distintos, no llega al umbral), mientras que "cano 1/2" y "cano 3/4" sí. Es justo
     * el caso que tiene que llegar a la IA para que lo descarte ella.
     *
     * @param  mixed $valor
     * @return string
     */
    public static function forma_ascii($valor)
    {
        $ascii = mb_strtolower(Str::ascii(trim((string) $valor)), 'UTF-8');

        $colapsado = preg_replace('/\s+/', ' ', $ascii);

        return is_string($colapsado) ? trim($colapsado) : $ascii;
    }

    /**
     * Rasgos precalculados de un valor, para no recalcularlos en cada par comparado.
     *
     * @param  mixed $valor
     * @return array ['fuerte' => string, 'ascii' => string, 'tokens' => string[]]
     */
    public static function rasgos($valor)
    {
        $ascii = self::forma_ascii($valor);

        $tokens = preg_split('/[^a-z0-9]+/', $ascii, -1, PREG_SPLIT_NO_EMPTY);

        $fuerte = self::clave_fuerte($valor);
        $bigramas = self::bigramas($fuerte);

        return [
            'fuerte'     => $fuerte,
            'ascii'      => $ascii,
            'tokens'     => is_array($tokens) ? $tokens : [],
            'bigramas'   => $bigramas,
            'n_bigramas' => count($bigramas),
        ];
    }

    /**
     * Bigramas (distintos) de la clave fuerte: lo que usa el bloqueo para decidir qué pares vale
     * la pena comparar en serio.
     *
     * Bigramas y no trigramas a propósito: con textos cortos ("Bosh" y "Bosch") los trigramas
     * casi no se comparten ("bos" y nada más) y el par se perdía; los bigramas comparten "bo" y
     * "os". Y todo lo que la comparación completa considera candidato comparte bigramas: misma
     * subcadena (contención), pocas ediciones (Levenshtein) o tokens en común (que viven en la
     * clave fuerte).
     *
     * @param  string $fuerte
     * @return array [bigrama => true]
     */
    protected static function bigramas($fuerte)
    {
        $bigramas = [];
        $largo = strlen($fuerte);

        if ($largo === 1) {
            $bigramas[$fuerte] = true;
        }

        for ($i = 0; $i < $largo - 1; $i++) {
            $bigramas[substr($fuerte, $i, 2)] = true;
        }

        return $bigramas;
    }

    /**
     * Presupuesto de comparaciones de una corrida (lo comparten todos los grupos del chequeo).
     *
     * @param  int $comparaciones
     * @return \stdClass {restante: int, recortado: bool}
     */
    public static function nuevo_presupuesto($comparaciones = self::PRESUPUESTO_COMPARACIONES)
    {
        $presupuesto = new \stdClass();
        $presupuesto->restante = (int) $comparaciones;
        $presupuesto->recortado = false;

        return $presupuesto;
    }

    /**
     * ¿Tiene tildes, ñ o cualquier carácter fuera de ASCII? Desempata el canónico de un grupo de
     * valores nuevos: entre "Pinturería" y "Pintureria", con la misma frecuencia, se queda con la
     * que está bien escrita.
     *
     * @param  string $valor
     * @return bool
     */
    public static function tiene_tildes($valor)
    {
        return preg_match('/[^\x00-\x7F]/', (string) $valor) === 1;
    }

    /* =====================================================================
     * Candidatos parecidos
     * ================================================================== */

    /**
     * Puntaje de parecido entre dos valores (con sus rasgos ya calculados). 0 = no es candidato.
     *
     * Es el máximo de tres criterios, cada uno con su umbral:
     *   - contención: uno contiene al otro (en la clave fuerte) y el corto tiene al menos 4
     *     caracteres. Puntaje 0,6 + 0,3 × (corto / largo): "pintura" en "pinturas" sale alto,
     *     "pala" en "palanca" no tanto.
     *   - Levenshtein relativo >= 0,7 sobre la clave fuerte o sobre la forma ascii; con textos
     *     cortos (<= 4) manda la distancia absoluta (<= 1).
     *   - tokens en común (Jaccard >= 0,5), contando como coincidencia (con peso 0,75) un token
     *     que es prefijo de otro con al menos 4 caracteres: "ferret" ~ "ferreteria".
     *
     * Misma clave fuerte = 1 (ese caso lo trata aparte la capa determinista).
     *
     * @param  array $a  rasgos()
     * @param  array $b  rasgos()
     * @return float
     */
    public static function puntaje_entre(array $a, array $b)
    {
        $fa = $a['fuerte'];
        $fb = $b['fuerte'];

        if ($fa === '' || $fb === '') {
            return 0.0;
        }

        if ($fa === $fb) {
            return 1.0;
        }

        $mejor = 0.0;

        $la = strlen($fa);
        $lb = strlen($fb);

        $corto = min($la, $lb);
        $largo = max($la, $lb);

        if ($corto >= self::LARGO_MINIMO_CONTENCION) {
            $texto_corto = $la <= $lb ? $fa : $fb;
            $texto_largo = $la <= $lb ? $fb : $fa;

            if (strpos($texto_largo, $texto_corto) !== false) {
                $mejor = max($mejor, 0.6 + 0.3 * ($corto / $largo));
            }
        }

        $mejor = max($mejor, self::puntaje_levenshtein($fa, $fb));
        $mejor = max($mejor, self::puntaje_levenshtein($a['ascii'], $b['ascii']));
        $mejor = max($mejor, self::puntaje_tokens($a['tokens'], $b['tokens']));

        return round($mejor, 4);
    }

    /**
     * Levenshtein relativo, con poda previa por diferencia de largo. 0 si no llega al umbral.
     *
     * @param  string $x
     * @param  string $y
     * @return float
     */
    protected static function puntaje_levenshtein($x, $y)
    {
        $lx = strlen($x);
        $ly = strlen($y);

        $largo = max($lx, $ly);

        /* levenshtein() de PHP 7.4 no acepta más de 255 caracteres por lado. */
        if ($largo === 0 || $lx > 255 || $ly > 255) {
            return 0.0;
        }

        if ($largo <= self::LARGO_CORTO) {
            /*
             * Con uno o dos caracteres todo está a distancia 1 de todo ("S" y "M", "A" y "B"):
             * se exige que los dos tengan al menos 3 para que esto signifique algo.
             */
            if (min($lx, $ly) < 3) {
                return 0.0;
            }

            $distancia = levenshtein($x, $y);

            return $distancia <= 1 ? 1 - ($distancia / $largo) : 0.0;
        }

        /* La distancia nunca es menor que la diferencia de largo: si eso ya no llega, ni se calcula. */
        if (1 - (abs($lx - $ly) / $largo) < self::UMBRAL_LEVENSHTEIN) {
            return 0.0;
        }

        $relativo = 1 - (levenshtein($x, $y) / $largo);

        return $relativo >= self::UMBRAL_LEVENSHTEIN ? $relativo : 0.0;
    }

    /**
     * Jaccard de tokens con coincidencia por prefijo. 0 si no llega al umbral.
     *
     * @param  string[] $ta
     * @param  string[] $tb
     * @return float
     */
    protected static function puntaje_tokens(array $ta, array $tb)
    {
        if (count($ta) === 0 || count($tb) === 0) {
            return 0.0;
        }

        $usados = [];
        $peso = 0.0;
        $coincidencias = 0;

        foreach ($ta as $token_a) {
            $mejor_indice = null;
            $mejor_peso = 0.0;

            foreach ($tb as $indice => $token_b) {
                if (isset($usados[$indice])) {
                    continue;
                }

                if ($token_a === $token_b) {
                    $mejor_indice = $indice;
                    $mejor_peso = 1.0;
                    break;
                }

                $corto = strlen($token_a) <= strlen($token_b) ? $token_a : $token_b;
                $largo = strlen($token_a) <= strlen($token_b) ? $token_b : $token_a;

                if (
                    $mejor_peso < self::PESO_PREFIJO
                    && strlen($corto) >= self::LARGO_MINIMO_PREFIJO
                    && strpos($largo, $corto) === 0
                ) {
                    $mejor_indice = $indice;
                    $mejor_peso = self::PESO_PREFIJO;
                }
            }

            if (!is_null($mejor_indice)) {
                $usados[$mejor_indice] = true;
                $peso += $mejor_peso;
                $coincidencias++;
            }
        }

        if ($coincidencias === 0) {
            return 0.0;
        }

        $jaccard = $peso / (count($ta) + count($tb) - $coincidencias);

        return $jaccard >= self::UMBRAL_TOKENS ? $jaccard : 0.0;
    }

    /**
     * ¿$a es más canónico que $b? Más frecuente en el archivo; empate: el que tiene tildes o ñ;
     * después el más largo; y por último el orden alfabético, para que sea determinista.
     *
     * @param  string $a
     * @param  int    $filas_a
     * @param  string $b
     * @param  int    $filas_b
     * @return int  < 0 si $a es más canónico, > 0 si lo es $b
     */
    public static function comparar_canonico($a, $filas_a, $b, $filas_b)
    {
        if ((int) $filas_a !== (int) $filas_b) {
            return (int) $filas_b - (int) $filas_a;
        }

        $tildes_a = self::tiene_tildes($a);
        $tildes_b = self::tiene_tildes($b);

        if ($tildes_a !== $tildes_b) {
            return $tildes_a ? -1 : 1;
        }

        $largo_a = mb_strlen($a, 'UTF-8');
        $largo_b = mb_strlen($b, 'UTF-8');

        if ($largo_a !== $largo_b) {
            return $largo_b - $largo_a;
        }

        return strcmp($a, $b);
    }

    /* =====================================================================
     * Análisis de un grupo (un tipo dentro de un contexto)
     * ================================================================== */

    /**
     * Clasifica los valores de UNA columna (o de una subcategoría dentro de su categoría) contra los
     * modelos que ya existen y arma los candidatos para la IA.
     *
     * Estados posibles de cada valor:
     *   - identico:         mismo string (con trim) que un existente. No se pregunta nunca.
     *   - recordado:        hay una equivalencia guardada hacia un modelo vivo. Sin IA.
     *   - misma_escritura:  misma clave fuerte que uno o más existentes. Sin IA.
     *   - consultar:        tiene existentes parecidos: los juzga la IA.
     *   - nuevo:            no se parece a nada de lo cargado.
     * Y, para los dos últimos, `candidatos_archivo`: otros valores del MISMO archivo, más
     * canónicos, que se le parecen (la IA juzga si son el mismo, para crear uno solo).
     *
     * 🔴 Los candidatos parecidos NO se calculan para todos los pares: con 1999 valores contra 3000
     * existentes eso eran 6 millones de comparaciones (195 s en una columna, medido; con las cuatro
     * columnas de artículos se acercaba al timeout del job, y ahí se perdía también la
     * recomendación). Un bloqueo por bigramas de la clave fuerte (índice invertido) elige los pares
     * que comparten lo suficiente, y solo esos se comparan en serio, con un presupuesto por corrida.
     *
     * @param  array          $valores        [valor => filas]; valores ya con trim y no vacíos
     * @param  array          $existentes     [['id' => int, 'nombre' => string], ...] ordenados por id
     * @param  array          $recordadas     [clave_guardable => model_id]
     * @param  bool           $con_parecidos  false = solo la capa determinista
     * @param  \stdClass|null $presupuesto    nuevo_presupuesto(); null = uno propio para este grupo
     * @return array  ['valores' => [valor => [...]], 'con_parecidos' => bool, 'recortado' => bool]
     */
    public static function analizar_grupo(array $valores, array $existentes, array $recordadas = [], $con_parecidos = true, $presupuesto = null)
    {
        if (is_null($presupuesto)) {
            $presupuesto = self::nuevo_presupuesto();
        }

        $recortado = false;

        if (count($valores) > self::TOPE_VALORES_POR_COLUMNA) {
            /* Demasiados valores distintos: solo la clave fuerte, y se avisa que se acotó. */
            if ($con_parecidos) {
                $recortado = true;
            }

            $con_parecidos = false;
        }

        $por_id      = [];
        $por_nombre  = [];
        $por_fuerte  = [];
        $rasgos_existentes = [];

        foreach ($existentes as $existente) {
            $id     = (int) $existente['id'];
            $nombre = (string) $existente['nombre'];

            $entrada = ['id' => $id, 'nombre' => $nombre];

            $por_id[$id] = $entrada;

            /* La más vieja gana: llegan ordenados por id, así que se queda la primera. */
            if (!array_key_exists($nombre, $por_nombre)) {
                $por_nombre[$nombre] = $entrada;
            }

            if (trim($nombre) === '') {
                continue;
            }

            $fuerte = self::clave_fuerte($nombre);
            $por_fuerte[$fuerte][] = $entrada;

            if ($con_parecidos) {
                $rasgos_existentes[] = ['entrada' => $entrada, 'rasgos' => self::rasgos($nombre)];
            }
        }

        $indice_existentes = $con_parecidos ? self::indice_de_bigramas($rasgos_existentes) : [];

        $resultado = [];
        $posibles_nuevos = [];

        foreach ($valores as $valor => $filas) {
            $valor = (string) $valor;

            $info = [
                'valor'              => $valor,
                'filas'              => (int) $filas,
                'estado'             => 'nuevo',
                'id'                 => null,
                'opciones'           => [],
                'candidatos'         => [],
                'candidatos_archivo' => [],
            ];

            $clave = EquivalenciasResolver::clave_guardable($valor);
            $recordado_id = isset($recordadas[$clave]) ? (int) $recordadas[$clave] : null;

            /* Un recordado hacia un modelo borrado o ajeno no cuenta: sigue el camino normal. */
            if (!is_null($recordado_id) && !isset($por_id[$recordado_id])) {
                $recordado_id = null;
            }

            $identico = array_key_exists($valor, $por_nombre) ? $por_nombre[$valor] : null;

            if (!is_null($recordado_id)) {
                /*
                 * El worker mira la equivalencia ANTES que el nombre exacto, así que el chequeo
                 * también. Pero si la equivalencia apunta justo al modelo que se llama igual, no
                 * hay nada que preguntar.
                 */
                if (!is_null($identico) && (int) $identico['id'] === $recordado_id) {
                    $info['estado'] = 'identico';
                    $info['id'] = $recordado_id;
                } else {
                    $info['estado'] = 'recordado';
                    $info['id'] = $recordado_id;
                    $info['opciones'] = [$por_id[$recordado_id]];
                }
            } elseif (!is_null($identico)) {
                $info['estado'] = 'identico';
                $info['id'] = (int) $identico['id'];
            } else {
                $fuerte = self::clave_fuerte($valor);

                if (isset($por_fuerte[$fuerte])) {
                    $info['estado'] = 'misma_escritura';
                    $info['opciones'] = $por_fuerte[$fuerte];
                } else {
                    if ($con_parecidos) {
                        $info['candidatos'] = self::candidatos_existentes(self::rasgos($valor), $rasgos_existentes, $indice_existentes, $presupuesto);
                    }

                    $info['estado'] = count($info['candidatos']) > 0 ? 'consultar' : 'nuevo';
                    $posibles_nuevos[] = $valor;
                }
            }

            $resultado[$valor] = $info;
        }

        if ($con_parecidos) {
            self::candidatos_entre_valores($resultado, $posibles_nuevos, $presupuesto);
        }

        return [
            'valores'       => $resultado,
            'con_parecidos' => (bool) $con_parecidos,
            'recortado'     => $recortado || (bool) $presupuesto->recortado,
        ];
    }

    /**
     * Índice invertido bigrama => [posición, ...] sobre una lista de ['rasgos' => [...]].
     *
     * @param  array $lista
     * @return array
     */
    protected static function indice_de_bigramas(array $lista)
    {
        $indice = [];

        foreach ($lista as $posicion => $elemento) {
            foreach ($elemento['rasgos']['bigramas'] as $bigrama => $nada) {
                $indice[$bigrama][] = $posicion;
            }
        }

        return $indice;
    }

    /**
     * El bloqueo: las posiciones de la lista que comparten con el valor al menos el 30 % de los
     * bigramas del más corto de los dos, ordenadas por Jaccard de bigramas, hasta
     * MAX_COMPARACIONES_POR_VALOR. Solo esas se comparan con puntaje_entre().
     *
     * El umbral es sobre el MÁS CORTO para no perder la contención ("pala" adentro de "palanca de
     * cambios" comparte todos los bigramas de "pala", que son pocos contra los del largo).
     *
     * @param  array $rasgos_valor
     * @param  array $lista   [posición => ['rasgos' => [...]]]
     * @param  array $indice  indice_de_bigramas($lista)
     * @return int[] posiciones
     */
    protected static function bloquear(array $rasgos_valor, array $lista, array $indice)
    {
        $listas = [];

        foreach ($rasgos_valor['bigramas'] as $bigrama => $nada) {
            if (isset($indice[$bigrama])) {
                $listas[] = $indice[$bigrama];
            }
        }

        if (count($listas) === 0) {
            return [];
        }

        /*
         * El conteo de bigramas compartidos con array_merge + array_count_values, que corren en C,
         * y no con un foreach que incrementa: los bigramas comunes ("er", "an", "os") tienen listas
         * de miles de posiciones, y el foreach era la mitad del tiempo del chequeo.
         */
        $conteo = array_count_values(count($listas) === 1 ? $listas[0] : array_merge(...$listas));

        $pasan = [];
        $n_valor = $rasgos_valor['n_bigramas'];

        foreach ($conteo as $posicion => $compartidos) {
            $n_otro = $lista[$posicion]['rasgos']['n_bigramas'];
            $minimo = (int) ceil(self::MINIMO_BIGRAMAS_COMPARTIDOS * min($n_valor, $n_otro));

            if ($compartidos >= max(1, $minimo)) {
                /*
                 * Se ordena por Jaccard de bigramas y no por cantidad compartida: con muchos
                 * existentes que CONTIENEN el valor ("MARTILLOS SA", "SRL MARTILLOS"...) todos
                 * comparten los mismos bigramas que "Martillos", y por cantidad el más parecido
                 * podía quedar afuera de los que se comparan. El Jaccard premia el largo parecido.
                 */
                $pasan[$posicion] = $compartidos / ($n_valor + $n_otro - $compartidos);
            }
        }

        arsort($pasan);

        return array_keys(array_slice($pasan, 0, self::MAX_COMPARACIONES_POR_VALOR, true));
    }

    /**
     * Gasta una comparación del presupuesto. false = se agotó (y la corrida queda `recortado`).
     *
     * @param  \stdClass $presupuesto
     * @return bool
     */
    protected static function gastar(\stdClass $presupuesto)
    {
        if ($presupuesto->restante <= 0) {
            $presupuesto->recortado = true;

            return false;
        }

        $presupuesto->restante--;

        return true;
    }

    /**
     * Hasta MAX_CANDIDATOS existentes parecidos a un valor, por puntaje (empate: el más viejo).
     *
     * @param  array     $rasgos_valor
     * @param  array     $rasgos_existentes  [['entrada' => [...], 'rasgos' => [...]], ...]
     * @param  array     $indice             indice_de_bigramas($rasgos_existentes)
     * @param  \stdClass $presupuesto
     * @return array [['id' => int, 'nombre' => string, 'puntaje' => float], ...]
     */
    protected static function candidatos_existentes(array $rasgos_valor, array $rasgos_existentes, array $indice, \stdClass $presupuesto)
    {
        $candidatos = [];

        foreach (self::bloquear($rasgos_valor, $rasgos_existentes, $indice) as $posicion) {
            if (!self::gastar($presupuesto)) {
                break;
            }

            $existente = $rasgos_existentes[$posicion];

            $puntaje = self::puntaje_entre($rasgos_valor, $existente['rasgos']);

            if ($puntaje > 0) {
                $candidatos[] = [
                    'id'      => $existente['entrada']['id'],
                    'nombre'  => $existente['entrada']['nombre'],
                    'puntaje' => $puntaje,
                ];
            }
        }

        usort($candidatos, function ($a, $b) {
            if ($a['puntaje'] != $b['puntaje']) {
                return $a['puntaje'] < $b['puntaje'] ? 1 : -1;
            }

            return $a['id'] - $b['id'];
        });

        return array_slice($candidatos, 0, self::MAX_CANDIDATOS);
    }

    /**
     * Candidatos entre los propios valores del archivo que no matchean nada existente. Solo se
     * anotan del lado del MENOS canónico (apuntando al más canónico): el canónico de un grupo no
     * necesita preguntarle nada a la IA, y así cada par se juzga una sola vez. Los pares con la
     * misma clave fuerte no se anotan: esos se agrupan solos, sin IA.
     *
     * @param  array     $resultado        se modifica por referencia
     * @param  array     $posibles_nuevos  valores en estado consultar o nuevo
     * @param  \stdClass $presupuesto
     * @return void
     */
    protected static function candidatos_entre_valores(array &$resultado, array $posibles_nuevos, \stdClass $presupuesto)
    {
        if (count($posibles_nuevos) < 2) {
            return;
        }

        /* Misma forma que los existentes, para usar el mismo bloqueo: [posición => ['rasgos' => ...]]. */
        $lista = [];

        foreach ($posibles_nuevos as $valor) {
            $lista[] = ['valor' => (string) $valor, 'rasgos' => self::rasgos($valor)];
        }

        $indice = self::indice_de_bigramas($lista);

        foreach ($lista as $posicion_valor => $elemento) {
            $valor = $elemento['valor'];
            $rasgos_valor = $elemento['rasgos'];
            $candidatos = [];

            foreach (self::bloquear($rasgos_valor, $lista, $indice) as $posicion) {
                $otro = $lista[$posicion]['valor'];

                if ($posicion === $posicion_valor || $lista[$posicion]['rasgos']['fuerte'] === $rasgos_valor['fuerte']) {
                    continue;
                }

                if (self::comparar_canonico($otro, $resultado[$otro]['filas'], $valor, $resultado[$valor]['filas']) >= 0) {
                    continue;
                }

                if (!self::gastar($presupuesto)) {
                    break;
                }

                $puntaje = self::puntaje_entre($rasgos_valor, $lista[$posicion]['rasgos']);

                if ($puntaje > 0) {
                    $candidatos[] = ['valor' => $otro, 'puntaje' => $puntaje];
                }
            }

            usort($candidatos, function ($a, $b) {
                if ($a['puntaje'] != $b['puntaje']) {
                    return $a['puntaje'] < $b['puntaje'] ? 1 : -1;
                }

                return strcmp($a['valor'], $b['valor']);
            });

            $resultado[$valor]['candidatos_archivo'] = array_slice($candidatos, 0, self::MAX_CANDIDATOS);
        }
    }

    /* =====================================================================
     * Cierre de un grupo: de estados + respuestas de la IA a ítems
     * ================================================================== */

    /**
     * Arma los ítems de un grupo con lo que contestó la IA.
     *
     * @param  string      $tipo        proveedor | categoria | sub_categoria | marca | ...
     * @param  array       $grupo       salida de analizar_grupo()
     * @param  array       $respuestas  [valor => eleccion]; eleccion = null (es otra cosa),
     *                                  ['existente' => id, 'seguro' => bool] o
     *                                  ['archivo' => valor, 'seguro' => bool]. Solo los valores que
     *                                  la IA contestó. Sin `seguro` (o no booleano) cuenta como false.
     * @param  array       $enviados    [valor => true]: los que se le mandaron a la IA
     * @param  bool        $ia_ok       false = la IA no se pudo consultar (o no contestó en forma)
     * @param  string|null $padre       categoría (o provincia) del Excel, para los tipos con padre
     * @param  int|null    $padre_id    el id del padre que supone la opción preseleccionada
     * @return array ['items' => [...], 'supuestos' => [valor => id|null]]
     */
    public static function cerrar_grupo($tipo, array $grupo, array $respuestas, array $enviados, $ia_ok, $padre = null, $padre_id = null)
    {
        $items     = [];
        $supuestos = [];
        $nuevos    = [];
        $aristas   = [];

        /* Pares entre valores nuevos que la IA cree posibles pero no seguros: [valor => otro]. */
        $dudosos   = [];

        /* Valores que ya tienen un ítem contra un existente: no entran al agrupado entre nuevos. */
        $con_item = [];

        $base = [
            'tipo'     => $tipo,
            'padre'    => $padre,
            'padre_id' => is_null($padre_id) ? null : (int) $padre_id,
        ];

        foreach ($grupo['valores'] as $valor => $info) {
            $valor = (string) $valor;

            switch ($info['estado']) {
                case 'identico':
                    $supuestos[$valor] = $info['id'];
                    break;

                case 'recordado':
                    $supuestos[$valor] = $info['id'];
                    $items[] = self::item($base, $info, self::MOTIVO_RECORDADO, self::EXPLICACION_RECORDADO,
                        self::opciones_usar($info['opciones']), 0);
                    break;

                case 'misma_escritura':
                    $supuestos[$valor] = (int) $info['opciones'][0]['id'];
                    $items[] = self::item($base, $info, self::MOTIVO_MISMA_ESCRITURA, self::EXPLICACION_MISMA_ESCRITURA,
                        self::opciones_usar($info['opciones']), 0);
                    break;

                default:
                    $supuestos[$valor] = null;

                    $fue_enviado = isset($enviados[$valor]);

                    /*
                     * La IA contestó, pero se salteó este valor: se trata como DUDOSO sobre su
                     * mejor candidato (se pregunta, con "crear" marcado), sin importar el puntaje.
                     * Descartarlo sería el mismo error que "ante la duda, null": el valor no se
                     * pregunta y el duplicado se crea en silencio. No es "la IA no se pudo
                     * consultar": la IA anduvo, solo que no dijo nada de éste.
                     */
                    if ($fue_enviado && $ia_ok && !array_key_exists($valor, $respuestas)) {
                        if (count($info['candidatos']) > 0) {
                            $respuestas[$valor] = ['existente' => (int) $info['candidatos'][0]['id'], 'seguro' => false];
                        } elseif (count($info['candidatos_archivo']) > 0) {
                            $respuestas[$valor] = ['archivo' => (string) $info['candidatos_archivo'][0]['valor'], 'seguro' => false];
                        }
                    }

                    $contesto = $fue_enviado && $ia_ok && array_key_exists($valor, $respuestas);

                    if ($contesto) {
                        $eleccion = $respuestas[$valor];

                        /*
                         * 🔴 Solo el booleano true es "seguro". Cualquier otra cosa (ausente, "true"
                         * como texto) es dudosa: se pregunta igual, pero sin marcar "usar el
                         * existente", así un clic distraído nunca une dos cosas distintas.
                         */
                        $seguro = is_array($eleccion) && isset($eleccion['seguro']) && $eleccion['seguro'] === true;

                        if (is_array($eleccion) && isset($eleccion['existente'])) {
                            $opciones = self::opciones_usar($info['candidatos']);
                            $elegida  = 0;
                            $nombre   = '';

                            foreach ($info['candidatos'] as $indice => $candidato) {
                                if ((int) $candidato['id'] === (int) $eleccion['existente']) {
                                    $elegida = $indice;
                                    $nombre  = (string) $candidato['nombre'];
                                }
                            }

                            $con_item[$valor] = true;

                            if ($seguro) {
                                $supuestos[$valor] = (int) $eleccion['existente'];
                                $items[] = self::item($base, $info, self::MOTIVO_PARECIDO, self::EXPLICACION_PARECIDO, $opciones, $elegida);
                            } else {
                                /* Dudoso: se pregunta con "crear" marcado (lo que pasaría hoy). */
                                $items[] = self::item($base, $info, self::MOTIVO_POSIBLE, sprintf(self::EXPLICACION_DUDOSA, $nombre), $opciones, count($opciones) - 1);
                            }

                            break;
                        }

                        if (is_array($eleccion) && isset($eleccion['archivo'])) {
                            if ($seguro) {
                                $aristas[] = [$valor, (string) $eleccion['archivo']];
                            } else {
                                $dudosos[$valor] = (string) $eleccion['archivo'];
                            }
                        }

                        $nuevos[$valor] = $info;
                        break;
                    }

                    if ($fue_enviado) {
                        /*
                         * La IA no se pudo consultar: queda lo que pasaría hoy —crear uno nuevo—
                         * pero se le muestran al usuario los parecidos de puntaje alto, para que
                         * decida él. (Si la IA anduvo y solo se salteó este valor, ya se trató
                         * arriba como dudoso.)
                         */
                        $altos = [];

                        foreach ($info['candidatos'] as $candidato) {
                            if ($candidato['puntaje'] >= self::PUNTAJE_ALTO) {
                                $altos[] = $candidato;
                            }
                        }

                        if (count($altos) > 0) {
                            $opciones = self::opciones_usar($altos);
                            $con_item[$valor] = true;
                            $items[] = self::item($base, $info, self::MOTIVO_POSIBLE, self::EXPLICACION_POSIBLE, $opciones, count($opciones) - 1);
                            break;
                        }
                    }

                    $nuevos[$valor] = $info;
                    break;
            }
        }

        $items = array_merge($items, self::items_entre_nuevos($base, $nuevos, $aristas, $enviados, $ia_ok, $respuestas, $dudosos));

        usort($items, function ($a, $b) {
            if ($a['filas'] !== $b['filas']) {
                return $b['filas'] - $a['filas'];
            }

            return strcmp($a['valor'], $b['valor']);
        });

        return [
            'items'     => $items,
            'supuestos' => $supuestos,
        ];
    }

    /**
     * Agrupa los valores nuevos (los que no son ningún existente) y arma un ítem por cada variante
     * que no es la canónica de su grupo: "unir con «canónico»" preseleccionado, o "crear aparte".
     *
     * Se agrupan por clave fuerte (seguro) y por los pares que la IA dijo, SEGURA, que son el
     * mismo. Los pares que la IA cree posibles pero no seguros, y los de puntaje alto cuando la IA
     * no se pudo consultar, se muestran con "crear aparte" marcado.
     *
     * @param  array $base
     * @param  array $nuevos      [valor => info]
     * @param  array $aristas     [[valor, otro], ...] pares que la IA confirmó con seguridad
     * @param  array $enviados
     * @param  bool  $ia_ok
     * @param  array $respuestas
     * @param  array $dudosos     [valor => otro] pares que la IA cree posibles pero no seguros
     * @return array items
     */
    protected static function items_entre_nuevos(array $base, array $nuevos, array $aristas, array $enviados, $ia_ok, array $respuestas, array $dudosos = [])
    {
        if (count($nuevos) === 0) {
            return [];
        }

        /*
         * Union-find chico, sobre los valores nuevos. Todo se castea a string: un valor "10" como
         * clave de array PHP pasa a int, y la comparación estricta de la raíz no terminaría de
         * reconocerse a sí misma.
         */
        $padre_de = [];

        foreach ($nuevos as $valor => $info) {
            $padre_de[(string) $valor] = (string) $valor;
        }

        $raiz = function ($valor) use (&$padre_de) {
            $valor = (string) $valor;

            while ((string) $padre_de[$valor] !== $valor) {
                $padre_de[$valor] = (string) $padre_de[(string) $padre_de[$valor]];
                $valor = (string) $padre_de[$valor];
            }

            return $valor;
        };

        $unir = function ($a, $b) use (&$padre_de, $raiz) {
            $ra = $raiz($a);
            $rb = $raiz($b);

            if ($ra !== $rb) {
                $padre_de[$ra] = $rb;
            }
        };

        $por_fuerte = [];

        foreach ($nuevos as $valor => $info) {
            $por_fuerte[self::clave_fuerte($valor)][] = (string) $valor;
        }

        foreach ($por_fuerte as $miembros) {
            for ($i = 1; $i < count($miembros); $i++) {
                $unir($miembros[0], $miembros[$i]);
            }
        }

        foreach ($aristas as $arista) {
            if (isset($nuevos[$arista[0]]) && isset($nuevos[$arista[1]])) {
                $unir($arista[0], $arista[1]);
            }
        }

        $grupos = [];

        foreach ($nuevos as $valor => $info) {
            $grupos[$raiz((string) $valor)][] = (string) $valor;
        }

        $items = [];
        $agrupados = [];

        /* [valor => canónico de su grupo]: un "unir" siempre apunta al canónico, nunca a una variante. */
        $canonico_de = [];

        foreach ($grupos as $miembros) {
            if (count($miembros) < 2) {
                continue;
            }

            usort($miembros, function ($a, $b) use ($nuevos) {
                return self::comparar_canonico($a, $nuevos[$a]['filas'], $b, $nuevos[$b]['filas']);
            });

            $canonico = $miembros[0];
            $fuerte_canonico = self::clave_fuerte($canonico);

            for ($i = 1; $i < count($miembros); $i++) {
                $variante = $miembros[$i];
                $agrupados[$variante] = true;
                $canonico_de[$variante] = $canonico;

                $explicacion = self::clave_fuerte($variante) === $fuerte_canonico
                    ? self::EXPLICACION_NUEVOS_ESCRITURA
                    : self::EXPLICACION_NUEVOS_PARECIDO;

                $items[] = self::item($base, $nuevos[$variante], self::MOTIVO_ENTRE_NUEVOS, $explicacion, [
                    ['accion' => 'unir', 'con' => $canonico],
                    ['accion' => 'crear'],
                ], 0);
            }
        }

        /*
         * Pares que se preguntan con "crear aparte" marcado: los que la IA cree posibles pero no
         * seguros y, si la IA no se pudo consultar, los de puntaje alto.
         */
        foreach ($nuevos as $valor => $info) {
            $valor = (string) $valor;

            if (isset($agrupados[$valor]) || !isset($enviados[$valor])) {
                continue;
            }

            if ($ia_ok && array_key_exists($valor, $respuestas)) {
                if (isset($dudosos[$valor]) && isset($nuevos[$dudosos[$valor]])) {
                    $con = isset($canonico_de[$dudosos[$valor]]) ? $canonico_de[$dudosos[$valor]] : $dudosos[$valor];

                    $items[] = self::item($base, $info, self::MOTIVO_POSIBLE, sprintf(self::EXPLICACION_DUDOSA, $con), [
                        ['accion' => 'unir', 'con' => $con],
                        ['accion' => 'crear'],
                    ], 1);
                }

                continue;
            }

            foreach ($info['candidatos_archivo'] as $candidato) {
                if ($candidato['puntaje'] >= self::PUNTAJE_ALTO && isset($nuevos[$candidato['valor']])) {
                    $con = isset($canonico_de[$candidato['valor']]) ? $canonico_de[$candidato['valor']] : $candidato['valor'];

                    $items[] = self::item($base, $info, self::MOTIVO_POSIBLE, self::EXPLICACION_POSIBLE, [
                        ['accion' => 'unir', 'con' => $con],
                        ['accion' => 'crear'],
                    ], 1);
                    break;
                }
            }
        }

        return $items;
    }

    /**
     * @param  array $entradas  [['id' => int, 'nombre' => string, ...], ...]
     * @return array opciones "usar" + la opción "crear" al final
     */
    protected static function opciones_usar(array $entradas)
    {
        $opciones = [];

        foreach ($entradas as $entrada) {
            $opciones[] = [
                'accion' => 'usar',
                'id'     => (int) $entrada['id'],
                'nombre' => (string) $entrada['nombre'],
            ];
        }

        $opciones[] = ['accion' => 'crear'];

        return $opciones;
    }

    /**
     * Un ítem del contrato `relaciones_parecidas.items`.
     *
     * `clave` es única en toda la lista: lleva el padre cuando el tipo lo tiene, porque la misma
     * subcategoría "Martillos" bajo dos categorías distintas son dos preguntas distintas.
     *
     * @param  array  $base
     * @param  array  $info
     * @param  string $motivo
     * @param  string $explicacion
     * @param  array  $opciones
     * @param  int    $sugerida
     * @return array
     */
    protected static function item(array $base, array $info, $motivo, $explicacion, array $opciones, $sugerida)
    {
        $clave = is_null($base['padre'])
            ? $base['tipo'] . '|' . $info['valor']
            : $base['tipo'] . '|' . $base['padre'] . '|' . $info['valor'];

        return [
            'clave'       => $clave,
            'tipo'        => $base['tipo'],
            'valor'       => $info['valor'],
            'filas'       => (int) $info['filas'],
            'padre'       => $base['padre'],
            'padre_id'    => $base['padre_id'],
            'motivo'      => $motivo,
            'explicacion' => $explicacion,
            'opciones'    => $opciones,
            'sugerida'    => (int) $sugerida,
        ];
    }
}
