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

                        $todas->where($nombre, 'LIKE', '%' . addcslashes($palabra, '%_\\') . '%');
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
     *  - La "x" (o el ×) entre dos números se separa: "60x60" → "60 x 60", "76x5x50mm" → "76 x 5 x 50mm";
     *    la "x" suelta es una palabra vacía (PALABRAS_VACIAS), así que quedan las medidas.
     *
     * Espera el texto ya en minúscula.
     *
     * @param  string  $texto
     * @return string
     */
    protected static function sin_simbolos($texto)
    {
        $texto = str_replace(['°', 'º', '˚', '"', '“', '”', '´', '`', '‘', '’', "''"], ' ', (string) $texto);

        return (string) preg_replace('/(\d)\s*[x×]\s*(?=\d)/u', '$1 x ', $texto);
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
