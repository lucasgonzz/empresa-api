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
     * columna, o cada palabra que importa en alguna columna. Con texto vacío no filtra nada.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     * @param  string  $texto
     * @param  array<int, string>  $columnas  Las columnas donde buscar ('name', 'bar_code', 'articles.name'...).
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     */
    public static function filtrar($query, $texto, array $columnas)
    {
        $texto = trim((string) $texto);

        if ($texto === '' || !count($columnas)) {

            return $query;
        }

        $palabras = self::palabras($texto);

        return $query->where(function ($sub) use ($texto, $palabras, $columnas) {

            // La frase entera, como siempre.
            $sub->where(function ($frase) use ($texto, $columnas) {

                self::alguna_columna($frase, $texto, $columnas);
            });

            /*
             * O cada palabra en alguna columna. Con una sola palabra igual a la frase no suma nada,
             * pero con el plural recortado ("duchas") sí: por eso no se saltea.
             */
            if (count($palabras)) {

                $sub->orWhere(function ($todas) use ($palabras, $columnas) {

                    foreach ($palabras as $palabra) {

                        $todas->where(function ($una) use ($palabra, $columnas) {

                            self::alguna_columna($una, $palabra, $columnas);
                        });
                    }
                });
            }
        });
    }

    /**
     * Las palabras que importan de lo que dijo la persona: en minúscula, sin conectores, sin
     * repetidas y con el plural recortado. Vacío si no queda ninguna ("de la").
     *
     * @param  string  $texto
     * @return array<int, string>
     */
    public static function palabras($texto)
    {
        $partes = preg_split('/[\s,;]+/u', mb_strtolower(trim((string) $texto)), -1, PREG_SPLIT_NO_EMPTY);

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
