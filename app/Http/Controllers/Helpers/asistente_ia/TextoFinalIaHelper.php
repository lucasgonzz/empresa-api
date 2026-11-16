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

    /** Una oración en otro idioma, separada de los nombres breves que pueden venir del catálogo. */
    public static function contiene_prosa_cjk(string $texto): bool
    {
        foreach (preg_split('/\R/u', $texto) ?: [] as $renglon) {
            $caracteres_cjk = preg_match_all('/[\p{Han}\p{Hiragana}\p{Katakana}]/u', $renglon);
            $letras_latinas = preg_match_all('/\p{Latin}/u', $renglon);

            if ($caracteres_cjk > $letras_latinas
                || preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}]{6,}/u', $renglon) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Palabras que solo existen en portugués (minúsculas, con sus tildes). Con una sola alcanza: el caso real
     * fue una respuesta con «$228 milhões» (Ferre Total, 30/10, mensaje 202). Misión asistente-diario-20261116,
     * rama C, 30/10 #14.
     */
    const PALABRAS_PORTUGUESAS = [
        'não', 'você', 'vocês', 'também', 'então', 'obrigado', 'obrigada', 'milhões', 'milhão', 'estão',
        'isso', 'aqui está', 'está bem', 'até', 'já está', 'nós', 'muito obrigado', 'vendas de hoje',
    ];

    /**
     * Palabras funcionales del inglés. Una oración en inglés de verdad trae varias (CUATRO o más, ver
     * contiene_prosa_en_portugues_o_ingles()); un nombre de producto, a lo sumo una o dos.
     */
    const PALABRAS_INGLESAS = [
        'the', 'and', 'you', 'your', 'is', 'are', 'was', 'were', 'have', 'has', 'will', 'with', 'this', 'that',
        'for', 'of', 'to', 'it', 'today', 'sales', 'sold', 'here', 'there', 'can', 'not', 'what', 'which',
    ];

    /**
     * true si la respuesta tiene prosa en PORTUGUÉS o en INGLÉS (misión asistente-diario-20261116, rama C,
     * 30/10 #14), para reintentarla en español como ya se hace con el chino (contiene_prosa_cjk()).
     *
     * Se mira renglón por renglón y 🔴 NUNCA un renglón de lista: ahí están los nombres de los productos, que
     * pueden estar en otro idioma («Pão de queijo», «Let's Go Naranja»). En el resto:
     *   (antes se saca lo que está entre comillas: son nombres)
     *   - portugués: alguna palabra de PALABRAS_PORTUGUESAS escrita en minúscula, o una palabra en minúscula que termina en
     *     «ção», «ções», «ões» o «ão» (los nombres propios van con mayúscula y se dejan);
     *   - inglés: cuatro o más palabras de PALABRAS_INGLESAS (y la mitad o más del renglón), ninguna palabra funcional del castellano y ningún
     *     carácter con tilde, ñ, ¿ ni ¡.
     * Un falso positivo cuesta una llamada más al modelo (el reintento pide la misma respuesta en español);
     * el texto nunca se descarta por esto.
     *
     * @param  string  $texto
     * @return bool
     */
    public static function contiene_prosa_en_portugues_o_ingles($texto)
    {
        foreach (preg_split('/\R/u', (string) $texto) ?: [] as $renglon) {

            if (trim($renglon) === '' || self::es_de_lista($renglon)) {

                continue;
            }

            /*
             * Revisión de la rama C (B1): lo que está ENTRE COMILLAS es un nombre (un vino «Até o fim», un modelo
             * "for gaming"), no la voz del asistente: se saca antes de mirar.
             */
            $renglon = self::sin_lo_citado($renglon);

            // Las palabras portuguesas, solo EN MINÚSCULA en el texto original: «Tenis Não Pare» o «Isso Store» son marcas.
            $palabras = ' ' . preg_replace('/[^\p{L}\p{N}]+/u', ' ', $renglon) . ' ';

            foreach (self::PALABRAS_PORTUGUESAS as $palabra) {

                if (mb_strpos($palabras, ' ' . $palabra . ' ') !== false) {

                    return true;
                }
            }

            // Una palabra en minúscula con terminación portuguesa.
            if (preg_match('/(?<![\p{L}])\p{Ll}+(ção|ções|ões|ão)(?![\p{L}])/u', $renglon)) {

                return true;
            }

            /*
             * Inglés: cuatro o más palabras funcionales inglesas que además sean la MITAD o más de las palabras del
             * renglón (números incluidos), sin ninguna palabra funcional del castellano ni tildes. Un nombre de
             * producto largo en inglés («Monitor LG 24 for gaming with HDMI and the stand: $300.000») no llega.
             */
            $todas = preg_split('/\s+/u', trim(mb_strtolower($palabras))) ?: [];
            $inglesas = 0;

            foreach ($todas as $palabra) {

                if (in_array($palabra, self::PALABRAS_INGLESAS, true)) {

                    $inglesas++;
                }
            }

            if ($inglesas >= 4
                && $inglesas * 2 >= count($todas)
                && self::palabras_espanolas($renglon) === 0
                && !preg_match('/[áéíóúüñÁÉÍÓÚÜÑ¿¡]/u', $renglon)) {

                return true;
            }
        }

        return false;
    }

    /**
     * El renglón sin lo que está entre comillas ("…", «…», “…”), que son nombres y no prosa del asistente
     * (revisión de la rama C, B1). Una comilla suelta (las pulgadas de «24"») no se toca.
     *
     * @param  string  $renglon
     * @return string
     */
    protected static function sin_lo_citado($renglon)
    {
        return (string) preg_replace(['/"[^"\r\n]*"/u', '/«[^»\r\n]*»/u', '/“[^”\r\n]*”/u'], ' ', (string) $renglon);
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
        $texto = self::sin_marcas_de_fotos((string) $texto);

        if (trim($texto) === '') {

            return $texto;
        }

        /*
         * Primero los bloques internos etiquetados (`<system>…</system>`, `<thinking>…</thinking>`): ver
         * sin_bloques_internos(). Si sacó algo, lo que sigue trabaja sobre lo que quedó.
         */
        $sin_bloques = self::sin_bloques_internos($texto);

        if ($sin_bloques !== $texto) {

            $texto = $sin_bloques;

            if (trim($texto) === '') {

                return '';
            }

            return trim(self::limpiar($texto, $nombres_de_herramientas));
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
     * Las etiquetas de bloques internos que nunca le llegan a la persona (misión asistente-mejoras-4-dias, P4,
     * 16/11/2026): son las que usan los modelos para su razonamiento o para «hablarle al sistema».
     */
    const ETIQUETAS_INTERNAS = 'system|thinking|think|reasoning|analysis|internal|scratchpad|reflection|tool_call|function_calls';

    /**
     * El texto sin los BLOQUES INTERNOS etiquetados: `<system>…</system>`, `<thinking>…</thinking>` y sus
     * parientes (ETIQUETAS_INTERNAS), en cualquier canal. Si no hay nada que sacar, vuelve igual, byte por byte.
     *
     * 🔴 EL CASO REAL (Truvari, 13/11/2026, mensaje 259, por WhatsApp, ya en 4.3.59). Le llegó al dueño:
     *
     *   <system>El usuario aclara el criterio: el precio cargado es por caja y hay que dividirlo por la
     *   presentación. En el caso del Rutini, 89.000 / 6 = 14.833,33 por botella. Confirmo.</system>
     *
     * en castellano y sin nombrar ninguna herramienta, así que las reglas por oración (a) y (b) no lo
     * agarraban. Lo que lo delata es la etiqueta, y una persona nunca escribe una así.
     *
     *   - Un bloque cerrado se saca entero, aunque ocupe varios renglones.
     *   - Una etiqueta suelta (una que abre y nunca cierra, o un cierre huérfano) se saca sola, sin el texto de
     *     al lado: llevarse el párrafo podía comerse la respuesta.
     *   - No quedan dobles espacios donde estaba lo que se sacó.
     *
     * @param  string  $texto
     * @return string
     */
    public static function sin_bloques_internos($texto)
    {
        $texto = (string) $texto;

        if (strpos($texto, '<') === false) {

            return $texto;
        }

        $etiquetas = self::ETIQUETAS_INTERNAS;

        $limpio = preg_replace('/<\s*(' . $etiquetas . ')\b[^>]*>.*?<\s*\/\s*\1\s*>/isu', '', $texto);

        if (!is_string($limpio)) {

            return $texto;
        }

        /*
         * Las etiquetas sueltas: una que abre y no cierra, un cierre huérfano, las que se cierran solas. Se saca SOLO
         * la etiqueta (revisión independiente de la rama B: llevarse el párrafo podía comerse la respuesta).
         */
        $limpio = preg_replace('/<\s*\/?\s*(?:' . $etiquetas . ')\b[^>]*\/?\s*>/iu', '', $limpio);

        if (!is_string($limpio) || $limpio === $texto) {

            return $texto;
        }

        /* Sin el doble espacio ni el espacio en el borde de un renglón que deja lo que se sacó. */
        $limpio = (string) preg_replace('/(?<=\S)[ \t]{2,}(?=\S)/u', ' ', $limpio);
        $limpio = (string) preg_replace('/[ \t]+(\r?\n|$)/u', '$1', $limpio);
        $limpio = (string) preg_replace('/^[ \t]+(?=\S)/u', '', $limpio);

        return trim(preg_replace('/\n{3,}/', "\n\n", str_replace("\r\n", "\n", $limpio)));
    }

    /**
     * El texto sin las marcas que el sistema le pone a las fotos en el historial (misión agente-venta-por-planilla): el modelo las lee
     * y puede repetirlas, y el dueño no las tiene que leer. Una foto se nombra con palabras. Si no había ninguna, vuelve tal cual.
     *
     * @param  string  $texto
     * @return string
     */
    protected static function sin_marcas_de_fotos($texto)
    {
        $limpio = preg_replace([
            '/[ \t]*\[imagen_id [\d,\s]+\]/u',
            '/\[Foto que mand[oó] el due[nñ]o, ya analizada:.*?\](?=\s|$)/su',
            '/\[Foto: una tabla que no es una planilla de ventas.*?\](?=\s|$)/su',
            '/\[(Foto|Tabla pegada|Archivo adjunto): planilla N°[^\]]*\]/u',
        ], '', $texto);

        if (!is_string($limpio) || $limpio === $texto) {

            return $texto;
        }

        return trim((string) preg_replace('/\n{3,}/', "\n\n", $limpio));
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
