<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

/**
 * Limpia el texto final del asistente ANTES de que le llegue a la persona: saca los párrafos que son
 * razonamiento interno filtrado y no una respuesta (misión asistente-fotos-barras-y-compras,
 * 24/9/2026).
 *
 * 🔴 EL CASO REAL (demo3, msg 136, 24/9/2026). El dueño recibió por WhatsApp, entre dos párrafos en
 * español, éste:
 *
 *   "Confirmation needed; no report state until confirmar_carga_pendiente returns. Let me tell the
 *    user to confirm."
 *
 * Es el modelo pensando en voz alta: nombra una herramienta interna y está en inglés. El proveedor lo
 * devuelve como un bloque `text` más, igual que la respuesta, así que no hay forma de separarlo por
 * el tipo de bloque: se separa por lo que dice. Un párrafo se descarta si:
 *
 *   (a) nombra una herramienta registrada (`confirmar_carga_pendiente`, `proponer_alta`...): a una
 *       persona nunca se le habla con el nombre de una función;
 *   (b) contiene una línea de sistema: "[Tarjeta ..." (las que arma el historial) o "[El sistema ..."
 *       (la nota de la confirmación determinista), que el modelo a veces repite;
 *   (c) es predominantemente inglés (ver UMBRAL_INGLES).
 *
 * 🔴 Si limpiar deja el texto vacío, se devuelve el ORIGINAL: un mensaje vacío no se puede mandar y
 * es peor que uno con un párrafo de más. El que decide si la respuesta existe es el loop, no esto.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class TextoFinalIaHelper
{
    /**
     * Cuántas palabras funcionales inglesas hacen falta, como mínimo, para descartar un párrafo por
     * idioma, y siempre que sean MÁS que las españolas. El párrafo del caso real tiene diez inglesas
     * y ninguna española; un nombre de producto en inglés adentro de una oración en castellano
     * ("Botella The North Face de aluminio") tiene una o dos inglesas y varias españolas, y se queda.
     */
    const UMBRAL_INGLES = 3;

    /**
     * Palabras que en un texto en castellano no aparecen (salvo un nombre propio suelto). Quedan
     * afuera, a propósito, las que también son castellano o se confunden con él: "a", "no", "me",
     * "son", "sin", "come".
     */
    const PALABRAS_INGLESAS = [
        'the', 'to', 'and', 'of', 'for', 'until', 'let', 'i', 'you', 'we', 'need', 'needed', 'should',
        'will', 'this', 'that', 'with', 'user', 'tell', 'is', 'are', 'it', 'be', 'not', 'do', 'must',
        'can', 'now', 'then', 'before', 'after', 'state', 'report', 'returns', 'wait', 'call', 'tool',
        'ask', 'confirm', 'confirmation', 'my', 'so', 'if', 'just', 'what', 'which', 'when', 'there',
        'has', 'have', 'was', 'were', 'an', 'by', 'from', 'at', 'since', 'because', 'going', 'first',
        'next', 'check', 'already', 'done', 'response', 'reply', 'message', 'tools',
    ];

    /** Palabras funcionales del castellano, para el otro lado de la cuenta. */
    const PALABRAS_ESPANOLAS = [
        'el', 'la', 'los', 'las', 'de', 'del', 'que', 'y', 'en', 'un', 'una', 'para', 'con', 'por',
        'se', 'lo', 'le', 'les', 'es', 'te', 'ya', 'al', 'su', 'sus', 'tu', 'mi', 'pero', 'como',
        'mas', 'si', 'esta', 'este', 'eso', 'esa', 'hay', 'fue', 'vos', 'o', 'queda', 'quedo',
        'foto', 'tienda', 'articulo', 'compra', 'venta', 'deje', 'cuando', 'porque',
    ];

    /**
     * El texto sin los párrafos filtrados, o el original si no queda nada.
     *
     * @param  string  $texto
     * @param  array<int, string>  $nombres_de_herramientas  Los `name` de las tools del turno.
     * @return string
     */
    public static function sanear($texto, array $nombres_de_herramientas)
    {
        $texto = (string) $texto;

        if (trim($texto) === '') {

            return $texto;
        }

        $parrafos = preg_split('/\n[ \t]*\n+/u', $texto);

        if (!is_array($parrafos)) {

            return $texto;
        }

        $quedan = [];

        foreach ($parrafos as $parrafo) {

            if (trim($parrafo) === '') {

                continue;
            }

            if (self::es_razonamiento_filtrado($parrafo, $nombres_de_herramientas)) {

                continue;
            }

            $quedan[] = trim($parrafo);
        }

        if (!count($quedan)) {

            return $texto;
        }

        return implode("\n\n", $quedan);
    }

    /**
     * true si el párrafo es una de las tres cosas del docblock de la clase.
     *
     * @param  string  $parrafo
     * @param  array<int, string>  $nombres_de_herramientas
     * @return bool
     */
    public static function es_razonamiento_filtrado($parrafo, array $nombres_de_herramientas)
    {
        foreach ($nombres_de_herramientas as $nombre) {

            $nombre = trim((string) $nombre);

            if ($nombre !== '' && preg_match('/(?<![a-z0-9_])' . preg_quote($nombre, '/') . '(?![a-z0-9_])/i', $parrafo)) {

                return true;
            }
        }

        if (mb_stripos($parrafo, '[Tarjeta') !== false || mb_stripos($parrafo, '[El sistema') !== false) {

            return true;
        }

        return self::es_mayormente_ingles($parrafo);
    }

    /**
     * Cuenta palabras funcionales de cada idioma y decide con UMBRAL_INGLES.
     *
     * @param  string  $parrafo
     * @return bool
     */
    protected static function es_mayormente_ingles($parrafo)
    {
        $normalizado = strtr(mb_strtolower($parrafo), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);

        $palabras = preg_split('/[^a-z]+/', $normalizado, -1, PREG_SPLIT_NO_EMPTY);

        if (!is_array($palabras)) {

            return false;
        }

        $inglesas = 0;
        $espanolas = 0;

        foreach ($palabras as $palabra) {

            if (in_array($palabra, self::PALABRAS_INGLESAS, true)) {

                $inglesas++;

            } elseif (in_array($palabra, self::PALABRAS_ESPANOLAS, true)) {

                $espanolas++;
            }
        }

        return $inglesas >= self::UMBRAL_INGLES && $inglesas > $espanolas;
    }
}
