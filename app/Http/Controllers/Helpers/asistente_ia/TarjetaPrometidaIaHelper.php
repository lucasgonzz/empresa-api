<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

/**
 * Reconoce un texto final del asistente que le AFIRMA a la persona una tarjeta para confirmar
 * ("Dejé la tarjeta…", "Dejo la tarjeta para agendar…", "Tocá Confirmar") — misión
 * asistente-tarjeta-sin-accion, 10/10/2026.
 *
 * 🔴 EL CASO REAL (`demo`, 4.3.8, DeepSeek "Pensando ágil", modo "Resuelto", 10/10/2026). Grabando
 * los videos del centro de recursos el asistente contestó, en dos tomas seguidas, "Dejé la tarjeta
 * para confirmar el borrado de la categoría Pruebas…", y como PRIMER mensaje de una conversación
 * nueva "Encontré la subcategoría Alquiler. Dejo la tarjeta para agendar el pago del 15/10 por
 * 400.000…". En los tres el mensaje quedó con `acciones: []`: la persona busca la tarjeta y no hay
 * nada que confirmar. El mismo pedido, en otras corridas, sí arma la tarjeta.
 *
 * AsistenteIaService cruza lo que dice este helper con las `ai_message_actions` del mensaje: si el
 * texto afirma una tarjeta y el turno no creó ninguna, reintenta una vez con la nota de abajo y, si
 * sigue sin tarjeta, cambia el texto por TEXTO_HONESTO. El detalle está en el servicio.
 *
 * 🔴 UN FALSO POSITIVO LE CAMBIA A LA PERSONA UNA RESPUESTA BUENA POR EL TEXTO HONESTO. Por eso se
 * mira por oración y cada afirmación tiene su tipo, con sus propias excepciones (chequeo adversarial
 * del 10/10/2026, que encontró los casos de cada una):
 *
 *   - PASADO ("Dejé la tarjeta…"): afirma aunque después venga una pregunta o un ofrecimiento ("Te
 *     dejé la tarjeta, ¿la confirmás?"). No cuenta un subjuntivo ("para que te deje la tarjeta":
 *     sin tildes se escriben igual) ni una negación pegada al verbo ("No te dejé la tarjeta").
 *   - PROMESA ("Dejo la tarjeta…", "Te voy a dejar la tarjeta…"): es el caso 2 de `demo`, pero
 *     también la forma de PEDIR UN DATO ("Si me pasás el monto, te dejo la tarjeta", "¿Cuánto fue?
 *     Con eso te dejo la tarjeta"). No cuenta si antes del verbo hay un condicional o un pedido, ni
 *     si la oración —o la de antes— es una pregunta.
 *   - ESTADO ("La tarjeta queda para que la confirmes", "Quedó lista la tarjeta", "Ahí tenés la
 *     tarjeta").
 *   - BOTÓN ("Tocá Confirmar"): no cuenta "te toca confirmar" ni una explicación de cómo hacerlo
 *     desde una pantalla ("entrá a Presupuestos, abrilo y tocá Confirmar").
 *
 * Y para todos: "tarjeta" como medio de pago ("ventas con tarjeta", "el recargo de la tarjeta",
 * "tarjeta de crédito", "tarjetas SUBE") no es una tarjeta del asistente, y una oración que repasa
 * una tarjeta VIEJA ("ayer te dejé la tarjeta y la confirmaste", "la tarjeta de arriba", "quedó
 * confirmada") no afirma una nueva. Un falso negativo deja pasar el defecto de hoy, que ya existía;
 * un falso positivo es un defecto nuevo. Ante la duda, no dispara.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class TarjetaPrometidaIaHelper
{
    /**
     * Lo que ve la persona cuando el modelo afirmó una tarjeta, no la armó y el reintento tampoco.
     * Es la frase que propuso Lucas: dice la verdad y le deja a la persona la decisión de reintentar.
     */
    const TEXTO_HONESTO = 'No pude armar la tarjeta para esto. ¿Lo intento de nuevo?';

    /**
     * Cualquier carácter de la misma oración. Un punto entre dígitos ("400.000", "$ 1.500") no corta:
     * tampoco lo corta oraciones().
     */
    const VENTANA = '(?:[^.!?\n]|(?<=\d)\.(?=\d))';

    /** El artículo pegado a "tarjeta": "te dejo EL DETALLE de las ventas con tarjeta" no lo tiene. */
    const DETERMINANTE = '(?:la|las|una|tu|tus|esta|esa|otra|nueva)';

    /**
     * Verbos en pasado (sin tildes): "dejé", "armé", "creé"… Varios se escriben igual que su subjuntivo.
     * Sin los genéricos ("pasé", "cargué", "puse"): "Pasé la tarjeta al cliente" o "el cargo por usar la
     * tarjeta" no hablan de una tarjeta del asistente (tercer chequeo, 10/10/2026).
     */
    const PASADO = '(?:deje|dejamos|arme|prepare|genere|propuse|cree)';

    /** Verbos en presente o futuro: el preámbulo de una llamada ("Dejo la tarjeta para agendar…"). */
    const PRESENTE = '(?:dejo|armo|preparo|propongo|voy a (?:dejar|armar|preparar)(?:te|le|les)?)';

    /**
     * Las afirmaciones, sobre el texto normalizado (minúscula y sin tildes), cada una con su tipo.
     *
     * @return array<int, array{0: string, 1: string}>  [patrón, tipo]
     */
    protected static function patrones()
    {
        $v = self::VENTANA;
        $det = self::DETERMINANTE;

        return [
            /* "Dejé la tarjeta…", "Te armé una tarjeta…", "Te creé la tarjeta del gasto…". */
            ['/\b' . self::PASADO . '\b' . $v . '{0,50}\b' . $det . ' tarjetas?\b/u', 'pasado'],
            /* "Dejé para confirmar el borrado…", "Te dejé el gasto listo para que lo confirmes". */
            ['/\b' . self::PASADO . '\b' . $v . '{0,60}\bpara (?:que )?(?:la |lo |las |los )?confirm/u', 'pasado'],
            /* "Dejo la tarjeta para agendar…", "Te voy a dejar la tarjeta…". */
            ['/\b' . self::PRESENTE . '\b' . $v . '{0,50}\b' . $det . ' tarjetas?\b/u', 'promesa'],
            /* "Ahí tenés la tarjeta", "Abajo te queda la tarjeta". */
            ['/\b(?:aca|ahi|abajo) (?:te |la )?(?:tenes|va|esta|queda|quedo)\b' . $v . '{0,30}\b' . $det . ' tarjetas?\b/u', 'estado'],
            /* "Ya tenés la tarjeta abajo", "Tenés la tarjeta para revisar". */
            ['/\btenes\b' . $v . '{0,10}\b' . $det . ' tarjetas?\b/u', 'estado'],
            /* "La tarjeta queda para que la confirmes", "La tarjeta está lista". */
            ['/\btarjetas?\b' . $v . '{0,40}\b(?:queda|quedo|quedan|quedaron|esta|estan) (?:lista|listas|armada|armadas|pendiente de|esperando|para (?:que|confirm|revis|aprob))/u', 'estado'],
            /* "Quedó lista la tarjeta", "Te quedó armada la tarjeta del alquiler". */
            ['/\b(?:queda|quedo|quedan|quedaron|esta|estan) (?:lista|listas|armada|armadas)\b' . $v . '{0,20}\b' . $det . ' tarjetas?\b/u', 'estado'],
            /* "Tenés la tarjeta para confirmar", "La tarjeta para que la revises". */
            ['/\btarjetas?\b' . $v . '{0,30}\bpara (?:que (?:la |lo )?(?:confirmes|revises|apruebes)|confirmar|revisar|aprobar)\b/u', 'estado'],
            /* "Tocá Confirmar", "Tocá en la tarjeta el botón Confirmar", "Dale a Confirmar". */
            ['/\b(?:toca|toques|apreta|presiona|pulsa|hace clic|hace click|dale clic|dale click|dale a)\b' . $v . '{0,30}\bconfirmar\b/u', 'boton'],
        ];
    }

    /**
     * true si alguna oración del texto le afirma a la persona una tarjeta para confirmar.
     *
     * @param  string  $texto  El texto final, saneado (lo que leería la persona).
     * @return bool
     */
    public static function afirma_una_tarjeta($texto)
    {
        $oraciones = self::oraciones($texto);

        foreach ($oraciones as $indice => $oracion) {

            $anterior = $indice > 0 ? self::sin_tarjetas_de_pago(self::normalizar($oraciones[$indice - 1])) : '';

            if (self::oracion_afirma_una_tarjeta(self::normalizar($oracion), $anterior)) {

                return true;
            }
        }

        return false;
    }

    /**
     * La nota que viaja como turno de la persona en el reintento. Va entre corchetes y empieza con
     * "[El sistema", igual que la de la confirmación determinista: si el modelo la repitiera,
     * TextoFinalIaHelper saca ese renglón.
     *
     * @param  array<int, string>  $herramientas_de_carga  Las de carga que el modelo pidió en el turno.
     * @return string
     */
    public static function nota_para_reintentar(array $herramientas_de_carga = [])
    {
        $pedidas = count($herramientas_de_carga)
            ? 'Pediste ' . implode(', ', array_values(array_unique($herramientas_de_carga))) . ' y ninguna terminó armando una tarjeta: mirá lo que te contestó. '
            : 'No llamaste a ninguna herramienta proponer_. ';

        return '[El sistema revisó tu respuesta: dice que dejaste una tarjeta para confirmar, pero en este turno NO se creó ninguna. '
            . $pedidas
            . 'Si lo que pidió la persona necesita una tarjeta, llamá AHORA a la herramienta proponer_ que corresponde con los datos que ya tenés; '
            . 'si te contesta que faltan datos, pedíselos a la persona. '
            . 'Si te referías a una tarjeta que ya estaba pendiente de antes, o no podés armarla, volvé a contestar sin decir que dejaste una tarjeta nueva. '
            . 'No le menciones este aviso a la persona.]';
    }

    /**
     * Las oraciones del texto: se corta después de un punto, un signo de cierre o un salto de línea.
     * "400.000" o "15/10" no se cortan (no hay espacio después del punto).
     *
     * @param  string  $texto
     * @return array<int, string>
     */
    protected static function oraciones($texto)
    {
        $texto = trim((string) $texto);

        if ($texto === '') {

            return [];
        }

        $partes = preg_split('/(?<=[.!?…])\s+|\n+/u', $texto);

        if (! is_array($partes)) {

            return [$texto];
        }

        $oraciones = [];

        foreach ($partes as $parte) {

            if (trim($parte) !== '') {
                $oraciones[] = trim($parte);
            }
        }

        return $oraciones;
    }

    /**
     * true si UNA oración afirma una tarjeta nueva (ver los tipos en el docblock de la clase).
     *
     * @param  string  $oracion   Normalizada.
     * @param  string  $anterior  La oración de antes, normalizada ('' si es la primera).
     * @return bool
     */
    protected static function oracion_afirma_una_tarjeta($oracion, $anterior)
    {
        if ($oracion === '') {

            return false;
        }

        /*
         * Repasa una tarjeta VIEJA: "ayer te dejé la tarjeta y la confirmaste", "la tarjeta de
         * arriba", "quedó confirmada". No afirma una nueva. "antes de confirmar" sí puede ser nueva.
         */
        if (preg_match('/\b(?:ayer|antes(?! de)|anterior|anteriormente|de arriba|el otro dia|la otra vez|confirmaste|cancelaste|ya la confirm\w*|(?:quedo|quedaron|esta|estan) (?:confirmad|registrad|cancelad|reemplazad|vencid)\w*)\b/u', $oracion)) {

            return false;
        }

        $oracion = self::sin_tarjetas_de_pago($oracion);

        foreach (self::patrones() as $patron) {

            if (! preg_match_all($patron[0], $oracion, $coincidencias, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($coincidencias[0] as $coincidencia) {

                if (self::la_coincidencia_afirma($patron[1], $oracion, substr($oracion, 0, $coincidencia[1]), $anterior)) {

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Las excepciones de cada tipo, mirando lo que hay ANTES de la coincidencia en la oración.
     *
     * @param  string  $tipo      pasado | promesa | estado | boton
     * @param  string  $oracion   La oración normalizada.
     * @param  string  $antes     Lo que hay antes de la coincidencia.
     * @param  string  $anterior  La oración de antes, normalizada.
     * @return bool
     */
    protected static function la_coincidencia_afirma($tipo, $oracion, $antes, $anterior)
    {
        /* Una pregunta que arranca antes: "¿Te dejo la tarjeta…?", "¿Tenés la tarjeta…?". */
        if (strpos($antes, '¿') !== false) {

            return false;
        }

        /* Una negación pegada: "No te dejé la tarjeta…", "Todavía no hay tarjeta…", "ninguna tarjeta". */
        if (preg_match('/(?:\b(?:no|nunca|ni|tampoco)\s+(?:(?:te|le|les|se|lo|la|me|hay|habia|tengo|tenes|existe|quedo|queda)\s+){0,3}(?:ninguna\s+|una\s+|la\s+)?|\bninguna\s+|\bsin\s+(?:la\s+|una\s+)?)$/u', $antes)) {

            return false;
        }

        if ($tipo === 'pasado') {

            /*
             * "…para que te deje la tarjeta": subjuntivo, no un hecho. Sólo las construcciones que lo
             * piden: "así que te dejé la tarjeta" o "ya que…" SÍ afirman.
             */
            return ! preg_match('/\b(?:para|hasta|antes de|despues de|a menos|sin|necesitas|preferis|esperas|pedis|queres|quieras|hace falta|falta) que (?:te |le |les |se )?(?:la |lo |las |los )?$/u', $antes);
        }

        if ($tipo === 'promesa') {

            /* Una pregunta (con o sin "¿"), o justo después de una: está pidiendo un dato. */
            if (substr(rtrim($oracion), -1) === '?' || substr(rtrim($anterior), -1) === '?') {

                return false;
            }

            /*
             * Un condicional o un pedido antes del verbo: "Si me pasás el monto, te dejo la tarjeta".
             * El "si" va seguido de una palabra: sin tildes, "Sí, te dejo la tarjeta" (con la coma) es
             * un sí, y afirma.
             */
            return ! preg_match('/(?:\bsi\s+\w|\b(?:queres que|puedo|podria|podemos|cuando|apenas|en cuanto|una vez que|con eso|con ese dato|con esos datos|decime|pasame|mandame|confirmame|avisame|contame|indicame)\b)/u', $antes);
        }

        if ($tipo === 'boton') {

            /* "Mañana te toca confirmar el pedido": la tercera persona, no el botón. */
            if (preg_match('/\b(?:te|le|les|nos|me)\s+$/u', $antes)) {

                return false;
            }

            /*
             * Sin una tarjeta nombrada en la oración o en la de antes, "Tocá Confirmar" es una
             * explicación de cómo se hace algo en una pantalla ("Para guardar, tocá Confirmar"), no
             * una tarjeta del asistente (tercer chequeo, 10/10/2026).
             */
            if (! preg_match('/\btarjetas?\b/u', $oracion . ' ' . $anterior)) {

                return false;
            }

            /* Una explicación de cómo se hace desde una pantalla. */
            return ! preg_match('/\b(?:entra a|entra en|anda a|abri|abrila|abrilo|desde la pantalla|en la pantalla|de la pantalla|desde el menu|en el menu)\b/u', $oracion);
        }

        return true;
    }

    /**
     * Saca de la oración las "tarjetas" que son un medio de pago o un producto: "ventas con tarjeta",
     * "el recargo de la tarjeta", "tarjeta de crédito", "tarjetas SUBE". Las tarjetas del asistente
     * quedan como estaban.
     *
     * @param  string  $oracion  Normalizada.
     * @return string
     */
    protected static function sin_tarjetas_de_pago($oracion)
    {
        /*
         * "con (la) tarjeta", "por tarjeta", "de la tarjeta" (el recargo de la tarjeta) y "en tarjeta"
         * SIN artículo (pagó en tarjeta). "en LA tarjeta" no: "tocá Confirmar en la tarjeta" es la del
         * asistente.
         */
        $oracion = preg_replace('/\b(?:(?:con|por|sin|de)\s+(?:la |las |una |tu |tus |su |sus )?|en\s+)tarjetas?\b/u', ' medio_de_pago', $oracion);

        return (string) preg_replace('/\btarjetas?\s+(?:de credito|de debito|credito|debito|naranja|visa|master\w*|amex|american|cabal|sube|de memoria|de regalo|de puntos|de descuentos?|de fidelidad|de socio|de presentacion|prepagas?)\b/u', 'medio_de_pago', (string) $oracion);
    }

    /**
     * Minúscula y sin tildes, para que "Dejé", "deje" y "DEJÉ" sean lo mismo.
     *
     * @param  string  $texto
     * @return string
     */
    protected static function normalizar($texto)
    {
        $minuscula = mb_strtolower(trim((string) $texto), 'UTF-8');

        return strtr($minuscula, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
        ]);
    }
}
