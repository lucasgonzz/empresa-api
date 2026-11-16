<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

/**
 * BUSCAR POR LAS PALABRAS DEL DUEÑO Y NO POR LA FRASE ENTERA (misión asistente-altas-y-conversacion,
 * 16/10/2026).
 *
 * 🔴 EL CASO REAL. En demo3 (conv 11, prueba por WhatsApp del 16/10/2026) el dueño pidió "un combo
 * con la ducha gloa y el flexible" y el asistente contestó "no aparece ninguna ducha Gloa", con
 * DUCHA DE MANO METAL GLOA en el catálogo —la misma que había encontrado en otras conversaciones—.
 * Todas las búsquedas de artículos del asistente hacían `name LIKE '%ducha gloa%'`: la frase entera,
 * pegada. El dueño no dice el nombre como está cargado: dice dos o tres palabras sueltas, en otro
 * orden, en plural ("duchas"), con "de" en el medio o sin él.
 *
 * Ahora un registro aparece si tiene LA FRASE ENTERA (lo de antes, tal cual) O CADA UNA de las
 * palabras que importan en alguna de las columnas, en cualquier orden. Es un superconjunto de lo de
 * antes: nada que se encontraba deja de encontrarse. Los resolvers que eligen UN registro siguen
 * desempatando como siempre (uno solo, o el nombre exacto, o "faltan" con las opciones).
 *
 * Qué palabras importan (palabras()):
 *   - se sacan los conectores ("de", "la", "con", "para"...): "cinta de embalar" encuentra
 *     "CINTA EMBALAR TRANSPARENTE";
 *   - el plural se recorta ("duchas" → "ducha", "llaves" → "llav", "martillos" → "martillo"): el
 *     LIKE es por pedazo, así que el tronco más corto encuentra el singular;
 *   - los comodines del LIKE se escapan, palabra por palabra (molde:
 *     CriteriosDeOfertaService::articulos_que_matchean()).
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class BusquedaPorPalabrasIaHelper
{
    /** Conectores que el dueño pone y el nombre cargado casi nunca tiene. */
    const PALABRAS_VACIAS = [
        'de', 'del', 'la', 'las', 'el', 'los', 'lo', 'y', 'e', 'o', 'u', 'con', 'sin', 'para', 'por',
        'x', 'a', 'al', 'en', 'un', 'una', 'unos', 'unas', 'que', 'q',
    ];

    /** Un tronco más corto que esto encuentra cualquier cosa: no se recorta el plural. */
    const LARGO_MINIMO_DEL_TRONCO = 3;

    /**
     * Le agrega a la consulta el filtro por el texto que dijo la persona: la frase entera en alguna
     * columna, o cada palabra que importa en la PRIMERA columna (el nombre). Con texto vacío no
     * filtra nada.
     *
     * 🔴 LAS PALABRAS SUELTAS SE BUSCAN SOLO EN EL NOMBRE (chequeo independiente, 16/10/2026). Con
     * las palabras también contra el código de barras y el de proveedor, un número corto del pedido
     * ("pintura blanca 4 litros") encajaba en el código de casi cualquier artículo, y un artículo que
     * no tenía nada que ver quedaba como único candidato. Los códigos se siguen buscando como siempre:
     * por la frase entera.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     * @param  string  $texto
     * @param  array<int, string>  $columnas  Las columnas donde buscar la frase; la primera es el nombre ('name', 'articles.name'...).
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     */
    public static function filtrar($query, $texto, array $columnas)
    {
        $texto = trim((string) $texto);

        if ($texto === '' || !count($columnas)) {

            return $query;
        }

        $palabras = self::palabras($texto);

        $nombre = array_values($columnas)[0];

        return $query->where(function ($sub) use ($texto, $palabras, $columnas, $nombre) {

            // La frase entera, como siempre.
            $sub->where(function ($frase) use ($texto, $columnas) {

                self::alguna_columna($frase, $texto, $columnas);
            });

            /*
             * O cada palabra en el nombre. Con una sola palabra igual a la frase no suma nada, pero
             * con el plural recortado ("duchas") sí: por eso no se saltea.
             */
            if (count($palabras)) {

                $sub->orWhere(function ($todas) use ($palabras, $nombre) {

                    foreach ($palabras as $palabra) {

                        $todas->where($nombre, 'LIKE', self::patron_like_de_la_palabra($palabra));
                    }
                });
            }
        });
    }

    /**
     * Ordena primero lo que tiene la FRASE ENTERA (lo que se encontraba antes), y después lo que
     * encaja solo por palabras. Se llama ANTES del orderBy de siempre: con un tope de filas, lo que
     * encaja por frase no puede quedar afuera por lo que encaja por palabras.
     *
     * Las columnas las pone el código que llama (nunca la persona); el texto viaja como binding.
     *
     * @param  mixed  $query
     * @param  string  $texto
     * @param  array<int, string>  $columnas
     * @return mixed
     */
    public static function ordenar_por_frase($query, $texto, array $columnas)
    {
        $texto = trim((string) $texto);

        if ($texto === '' || !count($columnas)) {

            return $query;
        }

        $escapado = '%' . addcslashes($texto, '%_\\') . '%';

        $condiciones = [];
        $bindings = [];

        foreach ($columnas as $columna) {

            $condiciones[] = $columna . ' LIKE ?';
            $bindings[] = $escapado;
        }

        return $query->orderByRaw('CASE WHEN (' . implode(' OR ', $condiciones) . ') THEN 0 ELSE 1 END', $bindings);
    }

    /**
     * Los candidatos que tienen la frase entera en alguna de las columnas (lo que encontraba la
     * búsqueda de antes), comparando sin acentos ni mayúsculas. Vacío si todos encajan solo por
     * palabras.
     *
     * @param  iterable  $candidatos  Filas con esas columnas (modelos o stdClass).
     * @param  string  $texto
     * @param  array<int, string>  $columnas  Sin prefijo de tabla ('name', 'bar_code'...).
     * @return array
     */
    public static function por_frase($candidatos, $texto, array $columnas)
    {
        $frase = self::normalizar($texto);

        $con_la_frase = [];

        foreach ($candidatos as $candidato) {

            foreach ($columnas as $columna) {

                $valor = isset($candidato->{$columna}) ? self::normalizar((string) $candidato->{$columna}) : '';

                if ($frase !== '' && $valor !== '' && mb_strpos($valor, $frase) !== false) {

                    $con_la_frase[] = $candidato;

                    break;
                }
            }
        }

        return $con_la_frase;
    }

    /**
     * true si es seguro tomar SIN PREGUNTAR un artículo que encajó solo por palabras: cada palabra
     * está en su nombre, y cada palabra con números ("4", "10mm", "1/2") está como palabra entera del
     * nombre, no adentro de otra ("4" no encaja en "1/4" ni en "40"). Un número es casi siempre la
     * medida o la presentación: si no coincide entero, es otro artículo y se pregunta.
     *
     * @param  string  $nombre
     * @param  string  $texto
     * @return bool
     */
    public static function es_seguro($nombre, $texto)
    {
        $palabras = self::palabras($texto);

        if (!count($palabras)) {

            return false;
        }

        $nombre = self::normalizar($nombre);

        /* Se corta por espacios y separadores, no por "/" ni ".": "1/4" y "1.5" son UNA medida. */
        $tokens = array_map(function ($token) {
            return trim($token, "\"'.:");
        }, preg_split('/[\s,;()]+/u', $nombre, -1, PREG_SPLIT_NO_EMPTY));

        foreach ($palabras as $palabra) {

            $palabra = self::normalizar($palabra);

            if (preg_match('/\d/u', $palabra)) {

                if (!in_array($palabra, $tokens, true)) {

                    return false;
                }

                continue;
            }

            if (mb_strpos($nombre, $palabra) === false) {

                return false;
            }
        }

        return true;
    }

    /**
     * Minúsculas y sin acentos, como compara la base (collation _ci) y ConsultasSistemaIaHelper::normalize_text.
     *
     * Misión agente-mejoras-uso-real (E2 punto 6, 29/10/2026): además, los mismos símbolos que
     * sin_simbolos() (grados, comillas y la "x" entre medidas), con los espacios colapsados. Se aplica
     * igual a lo que dijo la persona y al nombre cargado, así que "90° 60x60" y "90 º 60 X 60" quedan
     * iguales de los dos lados.
     *
     * @param  string  $texto
     * @return string
     */
    protected static function normalizar($texto)
    {
        $texto = strtr(mb_strtolower(trim((string) $texto)), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);

        return trim((string) preg_replace('/\s+/u', ' ', self::sin_simbolos($texto)));
    }

    /**
     * LOS SÍMBOLOS CON LOS QUE EL AGENTE REESCRIBE UN NOMBRE (misión agente-mejoras-uso-real, E2
     * punto 6, 29/10/2026). En la CONV 11 de Pablo el agente buscó «tuboforte 1.8 ramal 90° 60x60» y el
     * artículo se llamaba «TUBOFORTE 1.8 RAMAL 90 º 60 X 60 MH»; y «H° carrocera» por «HºCARROCERA».
     * Por palabras, "90°" no está en "90 º" y "60x60" no está en "60 X 60": no encontraba la ficha.
     *
     *  - Los grados y el ordinal (°, º, ˚) pasan a espacio: "90°" → "90", "Hº" → "h".
     *  - Las comillas (rectas, tipográficas, el acento suelto) pasan a espacio: 1/2" → 1/2.
     *  - 🔴 UNA MEDIDA "N x M" ES UNA SOLA PALABRA, COMPACTA (revisión independiente, 30/10/2026): la
     *    "x" (o el ×) entre dos números se pega, con o sin espacios y en mayúscula o minúscula:
     *    "60 X 60" → "60x60", "76X5X50MM" → "76x5x50mm". La primera versión la SEPARABA ("60 x 60" y
     *    la "x" como palabra vacía), y entonces "ramal 60x60" quedaba en las palabras "ramal" y "60",
     *    que también están en «RAMAL 60X40»: es_seguro() lo daba por bueno y la ficha contestaba sobre
     *    OTRO artículo. Compacta, la medida se compara entera: "60x60" encuentra "60 X 60" y nunca
     *    "60X40". Del lado de la base, patron_like_de_la_palabra() la busca con los espacios que
     *    tenga el nombre cargado.
     *
     * Espera el texto ya en minúscula.
     *
     * @param  string  $texto
     * @return string
     */
    protected static function sin_simbolos($texto)
    {
        $texto = str_replace(['°', 'º', '˚', '"', '“', '”', '´', '`', '‘', '’', "''"], ' ', (string) $texto);

        return (string) preg_replace('/(\d)\s*[x×]\s*(?=\d)/u', '$1x', $texto);
    }

    /**
     * true si la palabra es una medida compacta de sin_simbolos(): números unidos por "x" ("60x60",
     * "76x5x50mm", "6x1 1/2" no: eso son dos palabras).
     *
     * @param  string  $palabra
     * @return bool
     */
    protected static function es_medida($palabra)
    {
        return preg_match('/^\d[\d.,\/]*(x\d[\d.,\/]*)+[a-z]*$/u', (string) $palabra) === 1;
    }

    /**
     * El patrón LIKE de una palabra para la base: `%palabra%` con los comodines escapados, y una medida
     * compacta con lugar para los espacios que tenga el nombre cargado ("60x60" → `%60%x%60%`, que
     * encuentra "60 X 60" y "60x60"). Es un prefiltro: el que decide si un candidato es el artículo
     * pedido es es_seguro() (o el match de la frase), que compara la medida entera.
     *
     * @param  string  $palabra
     * @return string
     */
    protected static function patron_like_de_la_palabra($palabra)
    {
        if (!self::es_medida($palabra)) {

            return '%' . addcslashes((string) $palabra, '%_\\') . '%';
        }

        $partes = [];

        foreach (explode('x', (string) $palabra) as $parte) {

            $partes[] = addcslashes($parte, '%_\\');
        }

        return '%' . implode('%x%', $partes) . '%';
    }

    /**
     * Las palabras que importan de lo que dijo la persona: en minúscula, sin conectores, sin
     * repetidas y con el plural recortado. Vacío si no queda ninguna ("de la").
     *
     * Misión agente-mejoras-uso-real (E2): antes de cortar, sin_simbolos() (grados, comillas y la "x"
     * entre medidas), para que "90° 60x60" busque "90" y "60" y encuentre "90 º 60 X 60".
     *
     * @param  string  $texto
     * @return array<int, string>
     */
    public static function palabras($texto)
    {
        $partes = preg_split('/[\s,;]+/u', self::sin_simbolos(mb_strtolower(trim((string) $texto))), -1, PREG_SPLIT_NO_EMPTY);

        $palabras = [];

        foreach ($partes as $parte) {

            // Las comillas y los puntos de los costados no son parte de la palabra ("gloa".).
            $parte = trim($parte, "\"'“”‘’.:()¿?¡!");

            if ($parte === '' || in_array($parte, self::PALABRAS_VACIAS, true)) {

                continue;
            }

            $palabras[] = self::sin_plural($parte);
        }

        return array_values(array_unique($palabras));
    }

    /**
     * "duchas" → "ducha", "llaves" → "llav", "pinceles" → "pincel". Solo palabras de letras: un
     * código ("3m", "10mm", "ab12s") queda como está.
     *
     * @param  string  $palabra
     * @return string
     */
    protected static function sin_plural($palabra)
    {
        if (!preg_match('/^\p{L}+$/u', $palabra)) {

            return $palabra;
        }

        $largo = mb_strlen($palabra);

        if ($largo > self::LARGO_MINIMO_DEL_TRONCO + 1 && mb_substr($palabra, -2) === 'es'
            && !in_array(mb_substr($palabra, -3, 1), ['a', 'e', 'i', 'o', 'u', 'á', 'é', 'í', 'ó', 'ú'], true)) {

            return mb_substr($palabra, 0, $largo - 2);
        }

        if ($largo > self::LARGO_MINIMO_DEL_TRONCO && mb_substr($palabra, -1) === 's') {

            return mb_substr($palabra, 0, $largo - 1);
        }

        return $palabra;
    }

    /*
     * ════════════════════════════════════════════════════════════════════════════════════════════
     * 🔴 LA BÚSQUEDA TOLERANTE (misión asistente-mejoras-4-dias, 16/11/2026, P8: V14)
     * ════════════════════════════════════════════════════════════════════════════════════════════
     *
     * EL CASO REAL (Truvari, 13 al 15/11): «Kilka Malbec» (el vino es KILLKA), «Rosotto Gustavo» (el
     * cliente está escrito distinto), «mix clásico» (la promo es MIX CLASICO Nº1). La búsqueda por
     * palabras exige que cada palabra ESTÉ en el nombre: una letra de más o de menos y no aparece nada,
     * y el agente daba vueltas o decía que no existía.
     *
     * parecidos() es el SEGUNDO intento, solo cuando el de siempre no trajo nada: cada palabra que
     * importa tiene que parecerse a una palabra del nombre (Levenshtein normalizado, sin tildes ni
     * mayúsculas; los números, enteros e iguales), y devuelve los candidatos con su `similitud`. 🔴 NUNCA
     * ELIGE: lo que devuelve son "parecidos" y quien llama los ofrece como opciones para que la persona
     * diga cuál (un parecido elegido solo es una venta o una foto en el artículo equivocado).
     */

    /** Lo mínimo que se tiene que parecer cada palabra (1 = igual). «kilka»/«killka» da 0,83. */
    const UMBRAL_PARECIDO = 0.75;

    /** Cuántos registros se leen como mucho para comparar (el prefiltro por pedazos de palabra). */
    const TOPE_PREFILTRO = 3000;

    /** Cuántos parecidos se devuelven si no se pide otra cosa. */
    const TOPE_PARECIDOS = 5;

    /**
     * 🔴 MÉTODO PÚBLICO Y REUTILIZABLE (para artículos, clientes, proveedores, promos o cualquier tabla
     * con una columna de nombre). Los registros cuyo nombre se PARECE al texto, de más a menos parecido.
     *
     * Uso típico, después de una búsqueda que no trajo nada:
     *
     *     $parecidos = BusquedaPorPalabrasIaHelper::parecidos(
     *         Client::where('user_id', $owner_id),   // la consulta YA acotada al dueño (y a los activos)
     *         'Rosotto Gustavo',                     // lo que dijo la persona
     *         'name'                                 // la columna del nombre
     *     );
     *     // [['id' => 12, 'nombre' => 'Rossotto Gustavo', 'similitud' => 0.94], ...]
     *
     * El prefiltro en la base exige TODAS las palabras, cada una por alguno de sus pedazos (sus primeras y sus últimas tres letras, o
     * el número entero), así no se lee el catálogo entero; la comparación fina va en PHP.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query  Ya acotada al dueño.
     * @param  string  $texto  Lo que dijo la persona.
     * @param  string  $columna  La columna del nombre ('name', 'clients.name'...). La pone el código, nunca la persona.
     * @param  int  $limite  Cuántos parecidos devolver.
     * @param  string  $columna_id  La columna del id.
     * @return array<int, array{id: int, nombre: string, similitud: float}>
     */
    public static function parecidos($query, $texto, $columna = 'name', $limite = self::TOPE_PARECIDOS, $columna_id = 'id')
    {
        $pedidas = self::palabras_para_comparar($texto);

        if (!count($pedidas)) {

            return [];
        }

        /*
         * Revisión de P8 (16/11/2026): el prefiltro es por PALABRA: cada palabra que importa tiene que aparecer
         * por alguno de sus pedazos (OR adentro de la palabra), y TODAS las palabras a la vez (AND entre
         * ellas). Con un OR general, «kilka malbec» traía cada Malbec del catálogo y el tope de filas podía
         * dejar afuera justo al KILLKA. Una palabra de menos de tres letras no filtra (no tiene pedazos).
         */
        $grupos = [];

        foreach ($pedidas as $palabra) {

            if (preg_match('/^\d+$/u', $palabra)) {

                $grupos[] = [$palabra];

            } elseif (mb_strlen($palabra) >= 3) {

                $grupos[] = array_values(array_unique([mb_substr($palabra, 0, 3), mb_substr($palabra, -3)]));
            }
        }

        if (!count($grupos)) {

            return [];
        }

        $consulta = clone $query;

        foreach ($grupos as $pedazos) {

            $consulta->where(function ($sub) use ($pedazos, $columna) {

                foreach ($pedazos as $pedazo) {

                    $sub->orWhere($columna, 'LIKE', '%' . addcslashes($pedazo, '%_\\') . '%');
                }
            });
        }

        $filas = $consulta->limit(self::TOPE_PREFILTRO)->get([$columna_id . ' as parecido_id', $columna . ' as parecido_nombre']);

        $encontrados = [];

        foreach ($filas as $fila) {

            $similitud = self::similitud($texto, (string) $fila->parecido_nombre);

            if ($similitud >= self::UMBRAL_PARECIDO) {

                $encontrados[] = [
                    'id'        => (int) $fila->parecido_id,
                    'nombre'    => (string) $fila->parecido_nombre,
                    'similitud' => round($similitud, 2),
                ];
            }
        }

        usort($encontrados, function ($a, $b) {

            if ($a['similitud'] === $b['similitud']) {

                return strcmp($a['nombre'], $b['nombre']);
            }

            return $a['similitud'] < $b['similitud'] ? 1 : -1;
        });

        return array_slice($encontrados, 0, max(1, (int) $limite));
    }

    /**
     * Los parecidos entre los ARTÍCULOS activos del dueño y, si el negocio tiene promos de vinoteca,
     * también entre sus PROMOS («mix clásico» era una promo: V7/V14). Cada uno dice su `tipo`.
     *
     * @param  int  $owner_id
     * @param  string  $texto
     * @param  int  $limite
     * @return array<int, array{tipo: string, id: int, nombre: string, similitud: float}>
     */
    public static function parecidos_de_articulos($owner_id, $texto, $limite = self::TOPE_PARECIDOS)
    {
        $todos = [];

        foreach (self::parecidos(\App\Models\Article::query()->where('user_id', (int) $owner_id)->where('status', 'active'), $texto, 'name', $limite) as $parecido) {

            $todos[] = array_merge(['tipo' => 'articulo'], $parecido);
        }

        try {

            if (\Illuminate\Support\Facades\Schema::hasTable('promocion_vinotecas')) {

                $promos = \Illuminate\Support\Facades\DB::table('promocion_vinotecas')
                    ->where('user_id', (int) $owner_id)
                    ->whereNull('deleted_at');

                foreach (self::parecidos($promos, $texto, 'name', $limite) as $parecido) {

                    $todos[] = array_merge(['tipo' => 'promocion'], $parecido);
                }
            }

        } catch (\Throwable $e) {
            // Sin la tabla de promos, quedan los artículos.
        }

        usort($todos, function ($a, $b) {
            return $b['similitud'] <=> $a['similitud'];
        });

        return array_slice($todos, 0, max(1, (int) $limite));
    }

    /**
     * La respuesta de una consulta que no encontró nada pero tiene parecidos: la lista vacía de siempre
     * más los parecidos y la regla para el modelo (ofrecerlos, nunca elegir).
     *
     * @param  array  $parecidos  Lo que devolvió parecidos() / parecidos_de_articulos().
     * @param  string  $texto  Lo que se buscó.
     * @param  string  $que  "artículos", "clientes"...
     * @return array<string, mixed>
     */
    public static function sin_resultados_con_parecidos(array $parecidos, $texto, $que)
    {
        return [
            'encontrados' => 0,
            'resultados'  => [],
            'parecidos'   => $parecidos,
            'nota'        => 'No hay ' . $que . ' que se llamen "' . $texto . '", pero estos se le PARECEN (puede estar mal escrito o dictado). '
                . 'Preguntale a la persona si es alguno de estos, nombrándolos como están cargados; nunca elijas uno solo sin que te lo confirme. '
                . 'Con el que elija, volvé a consultar por su id o por su nombre exacto.',
        ];
    }

    /**
     * Cuánto se parece un nombre a lo que dijo la persona, de 0 a 1: el promedio, para cada palabra que
     * importa del pedido, de su mejor parecido con alguna palabra del nombre. Si UNA sola palabra no
     * llega a UMBRAL_PARECIDO, da 0 (el nombre no es ese). Un número tiene que estar entero ("210" no se
     * parece a "120"). Pública para que quien ya tiene los candidatos en la mano los pueda ordenar.
     *
     * @param  string  $texto
     * @param  string  $nombre
     * @return float
     */
    public static function similitud($texto, $nombre)
    {
        $pedidas = self::palabras_para_comparar($texto);
        $del_nombre = self::palabras_para_comparar($nombre, false);

        if (!count($pedidas) || !count($del_nombre)) {

            return 0.0;
        }

        $suma = 0.0;

        foreach ($pedidas as $pedida) {

            $mejor = 0.0;

            foreach ($del_nombre as $palabra) {

                $mejor = max($mejor, self::parecido_de_palabras($pedida, $palabra));

                if ($mejor >= 1.0) {
                    break;
                }
            }

            if ($mejor < self::UMBRAL_PARECIDO) {

                return 0.0;
            }

            $suma += $mejor;
        }

        return $suma / count($pedidas);
    }

    /**
     * El parecido de dos palabras ya normalizadas, de 0 a 1. Un número solo se parece a sí mismo. Una
     * palabra que es el comienzo de la otra (3 letras o más: «clas» de «clasico») cuenta como igual.
     *
     * @param  string  $pedida
     * @param  string  $palabra
     * @return float
     */
    protected static function parecido_de_palabras($pedida, $palabra)
    {
        if ($pedida === $palabra) {

            return 1.0;
        }

        if (preg_match('/\d/u', $pedida) || preg_match('/\d/u', $palabra)) {

            return 0.0;
        }

        if (mb_strlen($pedida) >= 3 && mb_strpos($palabra, $pedida) === 0) {

            return 1.0;
        }

        $largo = max(strlen($pedida), strlen($palabra));

        if ($largo === 0) {

            return 0.0;
        }

        return 1.0 - (levenshtein($pedida, $palabra) / $largo);
    }

    /**
     * Las palabras para comparar: minúsculas, sin tildes (y la ñ como n), sin símbolos, separadas por
     * espacios y signos. Del pedido se sacan los conectores; del nombre no (pueden ser parte de él).
     *
     * @param  string  $texto
     * @param  bool  $sin_conectores
     * @return array<int, string>
     */
    protected static function palabras_para_comparar($texto, $sin_conectores = true)
    {
        $normal = self::normalizar($texto);

        $partes = preg_split('/[^\p{L}\p{N}\/.]+/u', $normal, -1, PREG_SPLIT_NO_EMPTY);

        $palabras = [];

        foreach ($partes as $parte) {

            $parte = trim($parte, './');

            if ($parte === '') {
                continue;
            }

            if ($sin_conectores && in_array($parte, self::PALABRAS_VACIAS, true)) {
                continue;
            }

            $palabras[] = $parte;
        }

        return array_values(array_unique($palabras));
    }

    /**
     * `(col1 LIKE '%t%' OR col2 LIKE '%t%' ...)`, con los comodines del texto escapados.
     *
     * @param  mixed  $query
     * @param  string  $texto
     * @param  array<int, string>  $columnas
     * @return void
     */
    protected static function alguna_columna($query, $texto, array $columnas)
    {
        $escapado = '%' . addcslashes((string) $texto, '%_\\') . '%';

        foreach ($columnas as $indice => $columna) {

            if ($indice === 0) {

                $query->where($columna, 'LIKE', $escapado);

            } else {

                $query->orWhere($columna, 'LIKE', $escapado);
            }
        }
    }
}
