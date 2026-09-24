<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

/**
 * Limpia el texto final del asistente ANTES de que le llegue a la persona: saca las ORACIONES que
 * son razonamiento interno filtrado y no una respuesta (misión asistente-fotos-barras-y-compras,
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
 * el tipo de bloque: se separa por lo que dice. Una oración se descarta si:
 *
 *   (a) nombra una herramienta registrada (`confirmar_carga_pendiente`, `proponer_alta`...) —
 *       también adentro de una viñeta: a una persona nunca se le habla con el nombre de una función;
 *   (b) trae un MARCADOR de razonamiento en inglés ("let me", "the user", "i need to"...) Y no tiene
 *       NINGUNA palabra funcional española Y no tiene ningún carácter con tilde, ñ, ¿ ni ¡;
 *   y además, como antes, una línea de sistema ("[Tarjeta ..." / "[El sistema ...") que el modelo
 *   a veces repite.
 *
 * 🔴 POR QUÉ POR ORACIÓN Y CON "CERO PALABRAS ESPAÑOLAS" (dos chequeos adversariales, 24/9/2026). La
 * primera versión contaba palabras inglesas y borraba catálogo ("Tenés 3 notebooks con stock:\n-
 * Lenovo IdeaPad Core i5..."). La segunda, por renglón y con "a lo sumo una palabra española",
 * todavía borraba datos —"Stock de Let's Go Naranja 1L: 12 u.", "Encontré: I Will Survive (DVD) — 3
 * u.", "Precio del Now I Know (libro): $8000"— y dejaba pasar "Te dejé la tarjeta de la compra. Let
 * me tell the user…" (el renglón tenía castellano) y "- Need to call confirmar_carga_pendiente" (era
 * una viñeta). Los nombres de productos están en inglés todo el tiempo; una oración del modelo
 * pensando para sí no tiene ni un "de" ni una tilde.
 *
 * 🔴 UNA LÍNEA DE LISTA SIN HERRAMIENTA NUNCA SE TOCA (empieza con "-", "*", "•" o número y punto): en
 * una lista están los datos, y ahí sobra un renglón raro antes que falte un artículo.
 *
 * limpiar() devuelve lo que queda, aunque sea vacío; sanear() devuelve el ORIGINAL si no queda nada
 * (un mensaje vacío no se puede mandar). AsistenteIaService decide qué hacer con el vacío: si hubo
 * una confirmación determinista, manda su resultado.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class TextoFinalIaHelper
{
    /**
     * Frases que sólo aparecen cuando el modelo razona en voz alta, en inglés. Se buscan en
     * minúsculas y como palabras enteras. Solas no alcanzan: ver la regla (b) del docblock.
     */
    const MARCADORES_DE_RAZONAMIENTO = [
        'let me', "let's", 'the user', 'i need to', 'need to', 'i should', "i'll", 'i will', 'we need',
        'confirmation needed', "i'm going to", 'i am going to', 'i must', 'i have to', 'i think',
        'now i', 'first i', 'i can now', 'no report state', 'wait for', 'the tool', 'tool call',
    ];

    /** Palabras funcionales del castellano: con una sola, la oración es en español. */
    const PALABRAS_ESPANOLAS = [
        'el', 'la', 'los', 'las', 'de', 'del', 'que', 'y', 'en', 'un', 'una', 'para', 'con', 'por',
        'se', 'lo', 'le', 'les', 'es', 'te', 'ya', 'al', 'su', 'sus', 'tu', 'mi', 'pero', 'como',
        'mas', 'si', 'esta', 'este', 'eso', 'esa', 'hay', 'fue', 'vos', 'o', 'queda', 'quedo',
        'foto', 'tienda', 'articulo', 'compra', 'venta', 'deje', 'cuando', 'porque', 'tenes',
    ];

    /**
     * El texto sin las oraciones filtradas, o el original si no queda nada.
     *
     * @param  string  $texto
     * @param  array<int, string>  $nombres_de_herramientas  Los `name` de las tools del turno.
     * @return string
     */
    public static function sanear($texto, array $nombres_de_herramientas)
    {
        $limpio = self::limpiar($texto, $nombres_de_herramientas);

        return trim($limpio) === '' ? (string) $texto : $limpio;
    }

    /**
     * El texto sin las oraciones filtradas, AUNQUE quede vacío. Si no hubo nada que sacar, vuelve el
     * texto tal cual, byte por byte.
     *
     * @param  string  $texto
     * @param  array<int, string>  $nombres_de_herramientas
     * @return string
     */
    public static function limpiar($texto, array $nombres_de_herramientas)
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
        $saque_algo = false;

        foreach ($renglones as $renglon) {

            if (trim($renglon) === '') {

                $quedan[] = '';

                continue;
            }

            $limpio = self::limpiar_renglon($renglon, $nombres_de_herramientas);

            if ($limpio !== $renglon) {

                $saque_algo = true;
            }

            if (trim($limpio) === '') {

                continue;
            }

            $quedan[] = $limpio;
        }

        if (!$saque_algo) {

            return $texto;
        }

        /* Los huecos que dejó un renglón sacado se colapsan a UNA línea en blanco, como un párrafo. */
        return trim(preg_replace('/\n{3,}/', "\n\n", implode("\n", $quedan)));
    }

    /**
     * true si el texto ENTERO es razonamiento filtrado (limpiarlo lo deja vacío). Lo usa también
     * AsistenteIaService para descartar un bloque `text` completo antes de unir los bloques.
     *
     * @param  string  $texto
     * @param  array<int, string>  $nombres_de_herramientas
     * @return bool
     */
    public static function es_razonamiento_filtrado($texto, array $nombres_de_herramientas)
    {
        return trim((string) $texto) !== '' && trim(self::limpiar($texto, $nombres_de_herramientas)) === '';
    }

    /**
     * Un renglón sin sus oraciones filtradas: la línea de lista entera si nombra una herramienta (si
     * no, intacta), y en el resto, oración por oración. Si no se saca nada, vuelve igual.
     *
     * @param  string  $renglon
     * @param  array<int, string>  $nombres_de_herramientas
     * @return string
     */
    protected static function limpiar_renglon($renglon, array $nombres_de_herramientas)
    {
        if (self::es_de_lista($renglon)) {

            return self::nombra_una_herramienta($renglon, $nombres_de_herramientas) ? '' : $renglon;
        }

        if (mb_stripos($renglon, '[Tarjeta') !== false || mb_stripos($renglon, '[El sistema') !== false) {

            return '';
        }

        $oraciones = preg_split('/(?<=[.!?…])\s+/u', trim($renglon));

        if (!is_array($oraciones)) {

            return $renglon;
        }

        $quedan = [];
        $saque = false;

        foreach ($oraciones as $oracion) {

            if (self::oracion_filtrada($oracion, $nombres_de_herramientas)) {

                $saque = true;

                continue;
            }

            $quedan[] = $oracion;
        }

        return $saque ? implode(' ', $quedan) : $renglon;
    }

    /**
     * Las reglas (a) y (b) del docblock de la clase, sobre una oración.
     *
     * @param  string  $oracion
     * @param  array<int, string>  $nombres_de_herramientas
     * @return bool
     */
    protected static function oracion_filtrada($oracion, array $nombres_de_herramientas)
    {
        if (trim($oracion) === '') {

            return false;
        }

        if (self::nombra_una_herramienta($oracion, $nombres_de_herramientas)) {

            return true;
        }

        return self::tiene_marcador($oracion)
            && self::palabras_espanolas($oracion) === 0
            && !preg_match('/[áéíóúüñÁÉÍÓÚÜÑ¿¡]/u', (string) $oracion);
    }

    /**
     * @param  string  $texto
     * @param  array<int, string>  $nombres_de_herramientas
     * @return bool
     */
    protected static function nombra_una_herramienta($texto, array $nombres_de_herramientas)
    {
        foreach ($nombres_de_herramientas as $nombre) {

            $nombre = trim((string) $nombre);

            if ($nombre !== '' && preg_match('/(?<![a-z0-9_])' . preg_quote($nombre, '/') . '(?![a-z0-9_])/i', (string) $texto)) {

                return true;
            }
        }

        return false;
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
     * @param  string  $texto
     * @return bool
     */
    protected static function tiene_marcador($texto)
    {
        /* El apóstrofo tipográfico (’) se lleva al recto: "I’ll" es "i'll". */
        $minusculas = str_replace("\u{2019}", "'", mb_strtolower((string) $texto));

        foreach (self::MARCADORES_DE_RAZONAMIENTO as $marcador) {

            if (preg_match("/(?<![a-z'])" . preg_quote($marcador, '/') . "(?![a-z'])/u", $minusculas)) {

                return true;
            }
        }

        return false;
    }

    /**
     * Cuántas palabras funcionales del castellano tiene el texto.
     *
     * @param  string  $texto
     * @return int
     */
    protected static function palabras_espanolas($texto)
    {
        $normalizado = strtr(mb_strtolower((string) $texto), [
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
