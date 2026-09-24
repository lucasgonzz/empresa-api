<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

/**
 * Limpia el texto final del asistente ANTES de que le llegue a la persona: saca los renglones que son
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
 * el tipo de bloque: se separa por lo que dice. Un renglón se descarta si:
 *
 *   (a) nombra una herramienta registrada (`confirmar_carga_pendiente`, `proponer_alta`...): a una
 *       persona nunca se le habla con el nombre de una función;
 *   (b) contiene una línea de sistema: "[Tarjeta ..." (las que arma el historial) o "[El sistema ..."
 *       (la nota de la confirmación determinista), que el modelo a veces repite;
 *   (c) trae un MARCADOR de razonamiento en inglés ("let me", "the user", "i need to"...) y tiene
 *       como mucho UNA palabra española.
 *
 * 🔴 POR QUÉ (c) ES UN MARCADOR Y NO "MAYORMENTE INGLÉS" (chequeo adversarial del 24/9/2026). La
 * primera versión contaba palabras inglesas contra españolas, y borraba DATOS: "Tenés 3 notebooks con
 * stock:\n- Lenovo IdeaPad Core i5 (4 u.)\n- HP 15 Core i7 (2 u.)" quedaba sólo con la pregunta del
 * final ("i" de "Core i5" contaba como inglés), "1. Cable USB to Lightning — 12 u." desaparecía, y
 * "Just For Men, Old Spice After Shave" también. Los nombres de productos, las marcas y los modelos
 * están en inglés todo el tiempo; lo que NO aparece nunca en una respuesta al dueño es "let me tell
 * the user". Si alguien quiere volver a "contar palabras inglesas", está volviendo a borrar catálogo.
 *
 * 🔴 Y UN RENGLÓN DE LISTA NUNCA SE DESCARTA (empieza con "-", "*", "•" o número y punto): en una
 * lista están los datos, y ahí sobra un renglón raro antes que falte un artículo.
 *
 * 🔴 Si limpiar deja el texto vacío, se devuelve el ORIGINAL: un mensaje vacío no se puede mandar y
 * es peor que uno con un renglón de más. El que decide si la respuesta existe es el loop, no esto.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class TextoFinalIaHelper
{
    /**
     * Cuántas palabras españolas puede tener, como mucho, un renglón con marcador de razonamiento
     * para descartarse. Con una alcanza para no salvar "Let me tell the user que sí" por un "que", y
     * con dos o más ya es una oración en castellano que cita algo en inglés: se queda.
     */
    const MAXIMO_DE_PALABRAS_ESPANOLAS = 1;

    /**
     * Frases que sólo aparecen cuando el modelo razona en voz alta, en inglés. Se buscan en
     * minúsculas y como palabras enteras. Ninguna es un nombre de producto posible.
     */
    const MARCADORES_DE_RAZONAMIENTO = [
        'let me', "let's", 'the user', 'i need to', 'i should', "i'll", 'i will', 'we need',
        'confirmation needed', "i'm going to", 'i am going to', 'i must', 'i have to', 'i think',
        'now i', 'first i', 'i can now', 'no report state', 'wait for', 'the tool', 'tool call',
    ];

    /** Palabras funcionales del castellano: las que hacen que un renglón sea una oración en español. */
    const PALABRAS_ESPANOLAS = [
        'el', 'la', 'los', 'las', 'de', 'del', 'que', 'y', 'en', 'un', 'una', 'para', 'con', 'por',
        'se', 'lo', 'le', 'les', 'es', 'te', 'ya', 'al', 'su', 'sus', 'tu', 'mi', 'pero', 'como',
        'mas', 'si', 'esta', 'este', 'eso', 'esa', 'hay', 'fue', 'vos', 'o', 'queda', 'quedo',
        'foto', 'tienda', 'articulo', 'compra', 'venta', 'deje', 'cuando', 'porque', 'tenes',
    ];

    /**
     * El texto sin los renglones filtrados, o el original si no queda nada.
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

        $renglones = preg_split('/\r\n|\n/u', $texto);

        if (!is_array($renglones)) {

            return $texto;
        }

        $quedan = [];
        $saque_alguno = false;

        foreach ($renglones as $renglon) {

            if (trim($renglon) !== '' && self::renglon_filtrado($renglon, $nombres_de_herramientas)) {

                $saque_alguno = true;

                continue;
            }

            $quedan[] = rtrim($renglon);
        }

        if (!$saque_alguno) {

            return $texto;
        }

        /* Los huecos que dejó un renglón sacado se colapsan a UNA línea en blanco, como un párrafo. */
        $limpio = trim(preg_replace('/\n{3,}/', "\n\n", implode("\n", $quedan)));

        return $limpio === '' ? $texto : $limpio;
    }

    /**
     * true si el texto ENTERO es razonamiento filtrado: todos sus renglones no vacíos lo son. Lo usa
     * también AsistenteIaService para descartar un bloque `text` completo antes de unir los bloques.
     *
     * @param  string  $texto
     * @param  array<int, string>  $nombres_de_herramientas
     * @return bool
     */
    public static function es_razonamiento_filtrado($texto, array $nombres_de_herramientas)
    {
        $alguno = false;

        foreach (preg_split('/\r\n|\n/u', (string) $texto) as $renglon) {

            if (trim($renglon) === '') {

                continue;
            }

            if (!self::renglon_filtrado($renglon, $nombres_de_herramientas)) {

                return false;
            }

            $alguno = true;
        }

        return $alguno;
    }

    /**
     * true si un renglón es una de las tres cosas del docblock de la clase y no es de una lista.
     *
     * @param  string  $renglon
     * @param  array<int, string>  $nombres_de_herramientas
     * @return bool
     */
    protected static function renglon_filtrado($renglon, array $nombres_de_herramientas)
    {
        if (self::es_de_lista($renglon)) {

            return false;
        }

        foreach ($nombres_de_herramientas as $nombre) {

            $nombre = trim((string) $nombre);

            if ($nombre !== '' && preg_match('/(?<![a-z0-9_])' . preg_quote($nombre, '/') . '(?![a-z0-9_])/i', $renglon)) {

                return true;
            }
        }

        if (mb_stripos($renglon, '[Tarjeta') !== false || mb_stripos($renglon, '[El sistema') !== false) {

            return true;
        }

        return self::tiene_marcador($renglon) && self::palabras_espanolas($renglon) <= self::MAXIMO_DE_PALABRAS_ESPANOLAS;
    }

    /**
     * true si el renglón es un ítem de lista: "-", "*", "•" o un número con punto o paréntesis.
     *
     * @param  string  $renglon
     * @return bool
     */
    protected static function es_de_lista($renglon)
    {
        return (bool) preg_match('/^\s*([-*•·]|\d+[.)])\s/u', (string) $renglon);
    }

    /**
     * @param  string  $renglon
     * @return bool
     */
    protected static function tiene_marcador($renglon)
    {
        /* El apóstrofo tipográfico (’) se lleva al recto: "I’ll" es "i'll". */
        $minusculas = str_replace("\u{2019}", "'", mb_strtolower((string) $renglon));

        foreach (self::MARCADORES_DE_RAZONAMIENTO as $marcador) {

            if (preg_match("/(?<![a-z'])" . preg_quote($marcador, '/') . "(?![a-z'])/u", $minusculas)) {

                return true;
            }
        }

        return false;
    }

    /**
     * Cuántas palabras funcionales del castellano tiene el renglón.
     *
     * @param  string  $renglon
     * @return int
     */
    protected static function palabras_espanolas($renglon)
    {
        $normalizado = strtr(mb_strtolower((string) $renglon), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);

        $palabras = preg_split('/[^a-z]+/', $normalizado, -1, PREG_SPLIT_NO_EMPTY);

        if (!is_array($palabras)) {

            return 0;
        }

        $cuantas = 0;

        foreach ($palabras as $palabra) {

            if (in_array($palabra, self::PALABRAS_ESPANOLAS, true)) {

                $cuantas++;
            }
        }

        return $cuantas;
    }
}
