<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

/**
 * Reconoce un texto final del asistente que le AFIRMA a la persona algo que el turno no hizo. Son dos
 * familias, con su detector cada una:
 *
 *   - LA TARJETA PROMETIDA (afirma_una_tarjeta): "Dejé la tarjeta…", "Dejo la tarjeta para agendar…",
 *     "Tocá Confirmar" — misión asistente-tarjeta-sin-accion, 10/10/2026.
 *   - LA CARGA DADA POR HECHA (afirma_una_carga_hecha): "Marqué la tarea como hecha", "Listo, quedó
 *     registrado el gasto", "La marco como hecha…" — misión asistente-carga-prometida-sin-accion, el
 *     mismo 10/10/2026.
 *
 * 🔴 EL CASO REAL DE LA TARJETA (`demo`, 4.3.8, DeepSeek "Pensando ágil", modo "Resuelto", 10/10/2026).
 * Grabando los videos del centro de recursos el asistente contestó, en dos tomas seguidas, "Dejé la
 * tarjeta para confirmar el borrado de la categoría Pruebas…", y como PRIMER mensaje de una
 * conversación nueva "Encontré la subcategoría Alquiler. Dejo la tarjeta para agendar el pago del
 * 15/10 por 400.000…". En los tres el mensaje quedó con `acciones: []`: la persona busca la tarjeta y
 * no hay nada que confirmar. El mismo pedido, en otras corridas, sí arma la tarjeta.
 *
 * 🔴 EL CASO REAL DE LA CARGA (medición real contra DeepSeek ágil, 10/10/2026, sobre la base de un
 * slot). En el modo "directo" —el default de un negocio nuevo desde el 28/9/2026— el asistente no
 * deja tarjetas: ejecuta. Y miente con esa forma: a "Ya lo llamé" contestó "Marqué la tarea como
 * hecha." en UNA vuelta `end_turn`, sin herramienta y con el mensaje en `acciones: []`; a "Cambiale el
 * teléfono a 3415559999", "El teléfono de Distribuidora Norte quedó cambiado a 3415559999.", igual; y
 * a otro "Ya lo llamé", "La marco como hecha con fecha de hoy, sábado 10/10/2026.". Ninguno nombra una
 * tarjeta, así que el detector de la tarjeta no los ve. Y en "resuelto" la variante es la inversa: el
 * texto da la carga por registrada y lo único que hay en el mensaje es la tarjeta sin confirmar.
 *
 * AsistenteIaService cruza lo que dicen los dos detectores con las `ai_message_actions` del mensaje:
 * si el texto afirma una tarjeta y el turno no creó ninguna, o da por hecha una carga y el turno no
 * ejecutó ninguna, reintenta UNA vez —un solo reintento por turno para las dos familias— con la nota
 * que corresponde y, si sigue, cambia el texto por el honesto que corresponde. El detalle está en el
 * servicio.
 *
 * 🔴 UN FALSO POSITIVO LE CAMBIA A LA PERSONA UNA RESPUESTA BUENA POR UN TEXTO HONESTO. Por eso se
 * mira por oración y cada afirmación tiene su tipo, con sus propias excepciones (chequeo adversarial
 * del 10/10/2026, que encontró los casos de cada una).
 *
 * La tarjeta (afirma_una_tarjeta):
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
 *   Y para todos: "tarjeta" como medio de pago ("ventas con tarjeta", "el recargo de la tarjeta",
 *   "tarjeta de crédito", "tarjetas SUBE") no es una tarjeta del asistente, y una oración que repasa
 *   una tarjeta VIEJA ("ayer te dejé la tarjeta y la confirmaste", "la tarjeta de arriba", "quedó
 *   confirmada") no afirma una nueva.
 *
 * La carga (afirma_una_carga_hecha):
 *
 *   - HECHO ("Marqué la tarea como hecha", "Listo, la pasé al miércoles", "Di de alta al proveedor"):
 *     la primera persona del pretérito, buscada CON TILDES: sin tilde es un subjuntivo ("¿querés que
 *     lo cargue?"). Los verbos que también sirven para otra cosa van con el objeto acotado: "pasé"
 *     sólo hacia una fecha ("la pasé al miércoles", no "pasé la tarjeta al cliente" ni "te pasé el
 *     detalle"), "armé" sólo un combo, una oferta, un presupuesto, una venta o un pedido ("te armé el
 *     resumen" no), "hice" sólo la venta, el pago, el cambio… ("hice la cuenta" no) y "dejé" sólo con
 *     un participio de carga pegado ("dejé la tarjeta" es de la otra familia). No cuenta con un
 *     condicional o una duda antes ("si cargué mal el monto, avisame").
 *   - QUEDÓ ("Quedó pasada al miércoles", "Ya está cargado", "Agendada la tarea…", "Gasto N° 3
 *     registrado"): un participio de carga detrás de "quedó" / "ya está", al empezar la oración, o
 *     detrás de lo que se cargó. "Queda" en presente no cuenta: es la explicación de una tarjeta ("al
 *     confirmar queda registrado"), y tampoco un "quedó" condicionado a confirmar.
 *   - PROMESA ("La marco como hecha…", "Te cargo el gasto"): la primera persona del presente con un
 *     pronombre pegado, que es lo que la vuelve verbo y no sustantivo ("el cargo", "el registro").
 *     Con las mismas excepciones que la promesa de la tarjeta, y además no si la ÚLTIMA oración del
 *     texto es una pregunta: ahí está preguntando ("La marco como hecha para el miércoles. … ¿Querés
 *     que la marque con fecha de hoy?", medido el 10/10/2026).
 *
 *   Y para todos: una oración que repasa algo VIEJO ("ayer te cargué…") no afirma nada nuevo; una
 *   negación pegada ("No lo cargué", "Todavía no quedó registrado") tampoco, ni una subordinada que
 *   describe ("el que quedó recién cargado"); y un renglón de lista no se mira (ahí van los datos de
 *   una consulta).
 *
 * Un falso negativo deja pasar el defecto de hoy, que ya existía; un falso positivo es un defecto
 * nuevo. Ante la duda, no dispara.
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
     * Lo que ve la persona cuando el modelo dio por hecha una carga, el reintento tampoco la hizo, el
     * mensaje no tiene ni una tarjeta para confirmar y en TODA la conversación no hay ninguna carga
     * confirmada (misión asistente-carga-prometida-sin-accion, 10/10/2026). Dice la verdad del turno
     * —no quedó nada registrado— y, como TEXTO_HONESTO, le deja a la persona la decisión de reintentar:
     * acá no hay ninguna carga de antes que se pueda duplicar.
     */
    const TEXTO_HONESTO_CARGA = 'No pude hacer esa carga: no quedó nada registrado. ¿La intento de nuevo?';

    /**
     * Lo mismo, pero el mensaje SÍ tiene una tarjeta `propuesta` (modo "resuelto", o "directo" con una
     * carga que no se auto-ejecuta: `requiere_confirmacion`, `confirmada_parecida`, una auto-ejecución
     * que falló o un tipo que nunca se auto-ejecuta). La carga no está hecha: está esperando el botón.
     * "No pude" también sería mentir; lo verdadero es que falta confirmarla.
     */
    const TEXTO_HONESTO_CARGA_CON_TARJETA = 'Todavía no quedó registrado: te dejé la tarjeta para que la confirmes.';

    /**
     * Lo mismo, sin ninguna tarjeta en el mensaje, pero con una carga CONFIRMADA en un mensaje anterior
     * de la conversación.
     *
     * 🔴 POR QUÉ NO DICE "NO PUDE" NI OFRECE REINTENTAR. Acá el texto puede estar mintiendo —el caso
     * medido del modo directo es justamente un seguimiento después de una carga hecha: "Cambiale el
     * teléfono" → "quedó cambiado", sin herramienta— o puede estar contando bien una carga de antes que
     * la excepción del repaso no reconoció. "No pude hacer esa carga" invita a repetirla, y si la de
     * antes sí se hizo la persona la DUPLICA; "¿La intento de nuevo?" le deja al modelo la orden de
     * rehacer la carga vieja. Esta frase es verdadera en los dos casos: este mensaje no registró nada,
     * y si había un pedido, la persona lo vuelve a decir. Y no se puede dejar el texto como estaba: si el
     * reintento falla, la clase entera del modo directo pasaría.
     */
    const TEXTO_HONESTO_CARGA_CON_HISTORIA = 'Con este mensaje no se registró nada nuevo. Si me pediste un cambio, decímelo de nuevo y lo hago.';

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
     * Una negación pegada antes de la afirmación, sobre texto normalizado: "No te dejé la tarjeta…",
     * "Todavía no hay tarjeta…", "ninguna tarjeta", "No lo cargué", "Todavía no quedó registrado". La
     * usan las dos familias; es el mismo patrón que la_coincidencia_afirma() tenía escrito adentro.
     */
    const NEGACION_PEGADA = '/(?:\b(?:no|nunca|ni|tampoco)\s+(?:(?:te|le|les|se|lo|la|me|hay|habia|tengo|tenes|existe|quedo|queda)\s+){0,3}(?:ninguna\s+|una\s+|la\s+)?|\bninguna\s+|\bsin\s+(?:la\s+|una\s+)?)$/u';

    /**
     * La carga: una oración que repasa algo VIEJO, sobre texto normalizado. Es el repaso de la tarjeta
     * (ver oracion_afirma_una_tarjeta()) SIN "quedó registrado": para la tarjeta, "la tarjeta del gasto
     * quedó registrada" es el repaso de una que ya se confirmó; para la carga, "Listo, quedó registrado
     * el gasto" es justamente la afirmación que se busca (el caso del gasto en "resuelto": el texto lo
     * da por registrado y la tarjeta sigue esperando el botón).
     */
    const REPASO_DE_UNA_CARGA = '/\b(?:ayer|antes(?! de)|anterior|anteriormente|de arriba|el otro dia|la otra vez|confirmaste|cancelaste|ya la confirm\w*|(?:quedo|quedaron|esta|estan) (?:confirmad|cancelad|reemplazad|vencid)\w*)\b/u';

    /**
     * La carga: la primera persona del pretérito, CON tildes (sin tilde es subjuntivo: "¿querés que lo
     * cargue?"). Sin "sumé", "agregué", "puse" ni "hice" suelto: son de cuentas y de consultas ("sumé
     * las ventas", "te agregué el detalle"). "Pasé", "armé", "hice" y "dejé" van aparte, con su objeto.
     */
    const PRETERITO_DE_CARGA = '(?:cargué|registré|agendé|anoté|apunté|asenté|ingresé|cambié(?!\s+de\b)|actualicé|modifiqué|edité|marqué|borré|eliminé|asigné|guardé|reprogramé|programé|moví|creé|subí|di de alta|di de baja|mandé a buscar)';

    /**
     * La carga: los participios, sin tildes ("cargado", "agendadas", "dado de alta"). "Cambiado" está
     * por el caso medido del modo directo: "El teléfono de Distribuidora Norte quedó cambiado a
     * 3415559999.", sin herramienta (10/10/2026).
     */
    const PARTICIPIO_DE_CARGA = '(?:(?:cargad|registrad|agendad|anotad|guardad|cread|actualizad|modificad|editad|asignad|borrad|eliminad|reprogramad|movid|pasad|marcad|cambiad)[oa]s?|dad[oa]s? de (?:alta|baja))';

    /** Los mismos sin "pasado" ni "movido", para la oración que EMPIEZA con el participio: "Pasado mañana vence el alquiler". */
    const PARTICIPIO_AL_EMPEZAR = '(?:(?:cargad|registrad|agendad|anotad|guardad|cread|actualizad|modificad|editad|asignad|borrad|eliminad|reprogramad|marcad|cambiad)[oa]s?|dad[oa]s? de (?:alta|baja))';

    /** Lo que puede abrir la oración antes de lo que se cargó: "Listo, agendada.", "Perfecto: gasto registrado". */
    const APERTURA = '¡?(?:(?:listo|perfecto|joya|hecho|ya|bien|dale)\b[\s,.:;!¡-]*){0,2}';

    /**
     * La carga: la primera persona del presente, que sólo cuenta con un pronombre pegado ("la marco",
     * "te lo cargo"): sin él, "cargo" y "registro" son sustantivos ("el cargo por usar la tarjeta").
     */
    const PRESENTE_DE_CARGA = '(?:cargo|registro|agendo|anoto|marco|paso|cambio|muevo|borro|elimino|actualizo|modifico|edito|asigno|guardo|reprogramo|doy de alta|doy de baja)';

    /** Los del presente que también cuentan con "te/le" y un artículo detrás: "te cargo el gasto", "le cambio el teléfono". */
    const PRESENTE_CON_OBJETO = '(?:cargo|registro|agendo|anoto|cambio|actualizo|modifico|edito|asigno|guardo|doy de alta)';

    /**
     * Una duda antes de la afirmación, sobre texto normalizado. Medido en "resuelto" el 10/10/2026: "No
     * encontré esa tarea en la agenda, así que no pude cambiarla. Quizás ya quedó hecha o borrada." —
     * está suponiendo, no contando una carga.
     */
    const DUDA = '/\b(?:quizas|quiza|capaz que|tal vez|puede que|a lo mejor)\b/u';

    /**
     * Una subordinada que DESCRIBE algo, justo antes de la afirmación, sobre texto normalizado. Medido
     * en "directo" el 10/10/2026: "Hay 3 proveedores que se llaman "Distribuidora Norte". Decime cuál
     * es: el que quedó recién cargado (el último) o alguno de los otros dos." — está preguntando cuál.
     */
    const RELATIVO = '/\b(?:el|la|los|las|lo) que\s+(?:(?:ya|te|le|les|lo|la|los|las|se|me)\s+)*$/u';

    /** Lo que vuelve condicional un "quedó": la explicación de una tarjeta ("quedó registrado al confirmar"). */
    const AL_CONFIRMAR = '/\b(?:al confirmar|cuando (?:la |lo )?confirmes|si (?:la |lo )?confirmas|apenas (?:la |lo )?confirmes|una vez que (?:la |lo )?confirmes|cuando toques)\b/u';

    /**
     * Las palabras de un acuse corto, normalizadas ("¡Gracias!", "Genial, mil gracias 👍", "Dale"): ver
     * es_acuse().
     *
     * @var array<int, string>
     */
    const PALABRAS_DE_ACUSE = [
        'gracias', 'muchas', 'mil', 'genial', 'perfecto', 'perfecta', 'buenisimo', 'buenisima', 'barbaro',
        'joya', 'ok', 'oka', 'okey', 'okay', 'dale', 'listo', 'lista', 'excelente', 're', 'bien', 'bueno',
        'buena', 'de', 'una', 'que', 'maquina', 'crack', 'capo', 'idolo', 'si', 'vale', 'ah',
    ];

    /** Un acuse corto tiene, como mucho, estas palabras. */
    const LARGO_MAXIMO_DE_UN_ACUSE = 6;

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
     * El tipo de la afirmación más fuerte con la que el texto da por HECHA una carga: 'hecho'
     * ("Marqué la tarea como hecha"), 'quedo' ("Quedó registrado el gasto") o 'promesa' ("La marco
     * como hecha…"), o '' si no da ninguna por hecha (ver los tipos en el docblock de la clase).
     *
     * Si una oración da 'hecho', gana 'hecho'; si no, 'quedo'; si no, 'promesa'. Es lo que necesita el
     * servicio: 'hecho' no tiene las excepciones de la pregunta ni de la consulta, y 'quedo' sí.
     *
     * Este detector no sabe si la carga se hizo: sólo lee el texto. Cruzarlo con las tarjetas del
     * mensaje, con el pedido de la persona y con las herramientas del turno es del servicio.
     *
     * @param  string  $texto  El texto final, saneado (lo que leería la persona).
     * @return string  '' | 'hecho' | 'quedo' | 'promesa'
     */
    public static function afirma_una_carga_hecha($texto)
    {
        $oraciones = self::oraciones_sin_renglones_de_lista($texto);

        $hay_quedo   = false;
        $hay_promesa = false;

        foreach ($oraciones as $indice => $oracion) {

            $anterior = $indice > 0 ? self::normalizar($oraciones[$indice - 1]) : '';

            $tipo = self::tipo_de_carga_de_la_oracion($oracion, $anterior);

            if ($tipo === 'hecho') {

                return 'hecho';
            }

            if ($tipo === 'quedo') {
                $hay_quedo = true;
            }

            if ($tipo === 'promesa') {
                $hay_promesa = true;
            }
        }

        if ($hay_quedo) {

            return 'quedo';
        }

        /*
         * La promesa no cuenta si el texto TERMINA preguntando (medido en "directo" el 10/10/2026): "La
         * marco como hecha para el miércoles 14/10/2026. Está agendada para el miércoles, que todavía no
         * llegó. ¿Querés que la marque hecha con fecha de hoy?" no promete nada: pregunta.
         */
        if ($hay_promesa && ! self::termina_preguntando($texto)) {

            return 'promesa';
        }

        return '';
    }

    /**
     * true si el texto es una pregunta (tiene "?" o "¿" en cualquier lado). Lo usa el servicio con el
     * pedido de la persona: a una pregunta, "quedó agendada para el martes" le cuenta un estado.
     *
     * @param  string  $texto
     * @return bool
     */
    public static function es_pregunta($texto)
    {
        $texto = (string) $texto;

        return mb_strpos($texto, '?') !== false || mb_strpos($texto, '¿') !== false;
    }

    /**
     * true si el texto es un acuse corto: hasta LARGO_MAXIMO_DE_UN_ACUSE palabras, todas de
     * PALABRAS_DE_ACUSE ("¡Gracias!", "Genial, mil gracias", "Dale", "Okk"), o sólo emojis y signos
     * ("👍"). Las letras repetidas se colapsan ("Graciasss" es "gracias").
     *
     * Lo usa el servicio con el pedido de la persona (medición real del 10/10/2026): en "resuelto", con
     * la tarjeta del gasto ya confirmada con el botón, a "Gracias!" el asistente contestó "Quedó
     * registrado el gasto de Comisiones bancarias por $15.000…" sin herramientas — es VERDAD.
     *
     * 🔴 Un mensaje SIN texto no es un acuse: es una foto sola, que casi siempre es el pedido de OTRA
     * carga (una factura). Sólo cuenta como acuse lo que, escrito, queda vacío al sacarle emojis y
     * signos.
     *
     * @param  string|null  $texto
     * @return bool
     */
    public static function es_acuse($texto)
    {
        $texto = trim((string) $texto);

        if ($texto === '') {

            return false;
        }

        $limpio = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', self::normalizar($texto));
        $limpio = (string) preg_replace('/(\p{L})\1+/u', '$1', $limpio);

        $palabras = preg_split('/\s+/u', trim($limpio), -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($palabras) || count($palabras) === 0) {

            return true;
        }

        if (count($palabras) > self::LARGO_MAXIMO_DE_UN_ACUSE) {

            return false;
        }

        foreach ($palabras as $palabra) {

            if (! in_array($palabra, self::PALABRAS_DE_ACUSE, true)) {

                return false;
            }
        }

        return true;
    }

    /**
     * La nota del reintento cuando el texto dio por hecha una carga que el turno no ejecutó (misión
     * asistente-carga-prometida-sin-accion). Empieza con "[El sistema revisó tu respuesta:", igual que
     * la de la tarjeta, así TextoFinalIaHelper saca el renglón si el modelo la repitiera. Dice la
     * verdad del turno, que es distinta según haya o no una tarjeta esperando el botón.
     *
     * @param  array<int, string>  $herramientas_de_carga  Las de carga que el modelo pidió en el turno.
     * @param  bool  $hay_tarjeta_propuesta  true si el mensaje tiene una tarjeta `propuesta`.
     * @return string
     */
    public static function nota_para_reintentar_carga(array $herramientas_de_carga, $hay_tarjeta_propuesta)
    {
        if ($hay_tarjeta_propuesta) {

            return '[El sistema revisó tu respuesta: dice que la carga ya quedó hecha, pero NO se ejecutó: quedó como tarjeta para que la persona la confirme. '
                . 'Volvé a contestar diciendo que dejaste la tarjeta para confirmar, sin decir que ya está hecho ni registrado. '
                . 'No le menciones este aviso a la persona.]';
        }

        $pedidas = count($herramientas_de_carga)
            ? 'Pediste ' . implode(', ', array_values(array_unique($herramientas_de_carga))) . ' y ninguna terminó en una carga: mirá lo que te contestó. '
            : '';

        return '[El sistema revisó tu respuesta: dice que la carga ya quedó hecha, pero en este turno NO se ejecutó ninguna carga y no quedó nada registrado. '
            . $pedidas
            . 'Si la persona pidió una carga, llamá AHORA a la herramienta proponer_ que corresponde con los datos que ya tenés; '
            . 'si te contesta que faltan datos, pedíselos a la persona. '
            . 'Si te referías a algo que en el historial ya figura como confirmado, volvé a contestar dejando claro que eso se hizo antes, no ahora. '
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
        if (preg_match(self::NEGACION_PEGADA, $antes)) {

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

    // -------------------------------------------------------------------------------------------
    // La carga dada por hecha (misión asistente-carga-prometida-sin-accion, 10/10/2026)
    // -------------------------------------------------------------------------------------------

    /**
     * Las oraciones del texto, sin los renglones de lista ("- ", "* ", "• ", "1. "): ahí van los datos
     * de una consulta ("- Tarea cargada: llamar a Juan"), no lo que el turno hizo. Se cortan los
     * renglones ANTES de partir en oraciones: si no, "1. Gasto registrado…" se partiría en "1." y en
     * una oración que ya no parece de lista.
     *
     * @param  string  $texto
     * @return array<int, string>
     */
    protected static function oraciones_sin_renglones_de_lista($texto)
    {
        $renglones = preg_split('/\r\n|\n/u', (string) $texto);

        if (! is_array($renglones)) {

            return self::oraciones($texto);
        }

        $oraciones = [];

        foreach ($renglones as $renglon) {

            if (trim($renglon) === '' || preg_match('/^\s*(?:[-*•·]|\d+[.)])\s/u', $renglon)) {
                continue;
            }

            foreach (self::oraciones($renglon) as $oracion) {
                $oraciones[] = $oracion;
            }
        }

        return $oraciones;
    }

    /**
     * true si la ÚLTIMA oración del texto es una pregunta.
     *
     * @param  string  $texto
     * @return bool
     */
    protected static function termina_preguntando($texto)
    {
        $oraciones = self::oraciones($texto);

        if (count($oraciones) === 0) {

            return false;
        }

        return self::es_pregunta($oraciones[count($oraciones) - 1]);
    }

    /**
     * El tipo de carga que afirma UNA oración ('hecho' > 'quedo' > 'promesa'), o ''.
     *
     * Cada tipo se busca sobre la versión que le corresponde de la misma oración: 'hecho' y 'promesa'
     * en minúscula CON tildes (la tilde es lo que separa "cargué" de "cargue", y "la cambio" de "la
     * cambió"); 'quedo', en la normalizada. Las excepciones que vienen de la tarjeta (negación, repaso,
     * condicional) se miran siempre sobre la normalizada, que es como están escritas.
     *
     * ⚠️ sin_tarjetas_de_pago() no se usa acá: ningún patrón de la carga mira la palabra "tarjeta", así
     * que no hay nada que neutralizar ("Pasé la tarjeta al cliente" no da 'hecho' porque "pasé" pide una
     * fecha, no por la tarjeta), y además reescribe la oración y correría los desplazamientos con los
     * que se arma lo de antes de cada coincidencia.
     *
     * @param  string  $oracion   La oración tal cual.
     * @param  string  $anterior  La oración de antes, normalizada ('' si es la primera).
     * @return string
     */
    protected static function tipo_de_carga_de_la_oracion($oracion, $anterior)
    {
        $minuscula   = mb_strtolower(trim((string) $oracion), 'UTF-8');
        $normalizada = self::normalizar($oracion);

        if ($normalizada === '') {

            return '';
        }

        if (preg_match(self::REPASO_DE_UNA_CARGA, $normalizada)) {

            return '';
        }

        if (self::oracion_afirma_un_hecho($minuscula, $normalizada)) {

            return 'hecho';
        }

        if (self::oracion_afirma_que_quedo($normalizada)) {

            return 'quedo';
        }

        if (self::oracion_promete_una_carga($minuscula, $normalizada, $anterior)) {

            return 'promesa';
        }

        return '';
    }

    /**
     * Los patrones de 'hecho', sobre la oración en minúscula CON tildes.
     *
     * @return array<int, string>
     */
    protected static function patrones_de_hecho()
    {
        $v = self::VENTANA;

        return [
            /* "Marqué la tarea como hecha", "Listo, lo cambié a 6000", "Di de alta al proveedor". */
            '/\b' . self::PRETERITO_DE_CARGA . '\b/u',
            /* "La pasé al miércoles", "Te pasé la tarea para el 14/10" — y no "Pasé la tarjeta al cliente". */
            '/\bpasé\b' . $v . '{0,40}?\b(?:al|para el)\s+(?:lunes|martes|mi[eé]rcoles|jueves|viernes|s[aá]bado|domingo|\d|mes\b|pr[oó]xim)/u',
            /* "Te armé el combo", "Armé la oferta" — pegado: "Te armé el resumen: 3 ventas…" no. */
            '/\barmé\s+(?:(?:el|la|los|las|un|una|tu|su)\s+)?(?:combo|oferta|presupuesto|venta|pedido)s?\b/u',
            /* "Hice la venta", "Te hice el cambio" — y no "Hice la cuenta". */
            '/\bhice\s+(?:la|el)\s+(?:venta|pago|cobro|cambio|carga|alta|baja|movimiento|presupuesto|transferencia)\b/u',
            /* "Te dejé agendada la tarea", "Te lo dejé cargado" — "Dejé la tarjeta" es de la otra familia. */
            '/\bdejé\s+' . self::PARTICIPIO_DE_CARGA . '\b/u',
        ];
    }

    /**
     * Los patrones de 'quedo', sobre la oración normalizada.
     *
     * @return array<int, string>
     */
    protected static function patrones_de_quedo()
    {
        $v = self::VENTANA;
        $participio = self::PARTICIPIO_DE_CARGA;
        $que_se_cargo = '(?:gasto|pago|cobro|venta|presupuesto|proveedor|cliente|tarea|combo|oferta|articulo)s?';

        return [
            /* "Quedó pasada al miércoles", "Ya está cargado", "Quedaron cargadas dos veces". */
            '/\b(?:quedo|quedaron|ya esta|ya estan)\b' . $v . '{0,25}?\b' . $participio . '\b/u',
            /* "Listo, quedó para el miércoles", "Quedó para el 14/10". */
            '/\b(?:quedo|quedaron) (?:para|en) el\s+(?:lunes|martes|miercoles|jueves|viernes|sabado|domingo|dia\b|\d|proxim)/u',
            /* "Agendada la tarea…", "Listo, agendada." */
            '/^' . self::APERTURA . '(?:(?:el|la|los|las) )?' . self::PARTICIPIO_AL_EMPEZAR . '\b/u',
            /* "Gasto N° 3 registrado", "Proveedor Distribuidora Norte creado, teléfono…". */
            '/^' . self::APERTURA . '(?:(?:el|la|los|las) )?' . $que_se_cargo . '\b' . $v . '{0,40}?\b' . $participio . '\b/u',
        ];
    }

    /**
     * Los patrones de 'promesa', sobre la oración en minúscula CON tildes: "lo cambio" es una promesa y
     * "lo cambió" (el proveedor, ayer) no; sin tildes se escriben igual.
     *
     * @return array<int, string>
     */
    protected static function patrones_de_promesa()
    {
        return [
            /* "La marco como hecha…", "Te lo cargo", "Lo doy de alta". */
            '/\b(?:(?:te|le|les)\s+)?(?:lo|la|los|las)\s+' . self::PRESENTE_DE_CARGA . '\b/u',
            /* "Te cargo el gasto", "Le cambio el teléfono". */
            '/\b(?:te|le|les)\s+' . self::PRESENTE_CON_OBJETO . '\s+(?:el|la|los|las|un|una)\b/u',
        ];
    }

    /**
     * true si la oración afirma un 'hecho' que no está desarmado por lo que tiene antes.
     *
     * @param  string  $minuscula    La oración en minúscula, con tildes.
     * @param  string  $normalizada  La misma, normalizada (tiene la misma cantidad de caracteres).
     * @return bool
     */
    protected static function oracion_afirma_un_hecho($minuscula, $normalizada)
    {
        foreach (self::patrones_de_hecho() as $patron) {

            if (! preg_match_all($patron, $minuscula, $coincidencias, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($coincidencias[0] as $coincidencia) {

                $antes_con_tildes = substr($minuscula, 0, $coincidencia[1]);
                $antes            = mb_substr($normalizada, 0, mb_strlen($antes_con_tildes, 'UTF-8'), 'UTF-8');

                /*
                 * El condicional se mira CON tildes: "Sí, lo cargué" y "Sí lo cargué" afirman; "si
                 * cargué mal el monto, avisame" no.
                 */
                if (! self::lo_de_antes_desarma_la_carga($antes, $antes_con_tildes)) {

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * true si la oración afirma un 'quedo' que no está desarmado por lo que tiene antes ni condicionado
     * a confirmar.
     *
     * @param  string  $normalizada
     * @return bool
     */
    protected static function oracion_afirma_que_quedo($normalizada)
    {
        /* "Quedó registrado al confirmar la tarjeta", "cuando la confirmes queda agendada". */
        if (preg_match(self::AL_CONFIRMAR, $normalizada)) {

            return false;
        }

        foreach (self::patrones_de_quedo() as $patron) {

            if (! preg_match_all($patron, $normalizada, $coincidencias, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($coincidencias[0] as $coincidencia) {

                $antes = substr($normalizada, 0, $coincidencia[1]);

                if (! self::lo_de_antes_desarma_la_carga($antes, $antes)) {

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * true si la oración promete una carga, con las MISMAS excepciones que la promesa de la tarjeta
     * (la_coincidencia_afirma() con el tipo 'promesa': pregunta en la oración o en la de antes,
     * condicional, "querés que", "puedo", "cuando", "apenas", "con eso", "decime", "pasame"…).
     *
     * @param  string  $minuscula    La oración en minúscula, con tildes.
     * @param  string  $normalizada  La misma, normalizada.
     * @param  string  $anterior     La oración de antes, normalizada.
     * @return bool
     */
    protected static function oracion_promete_una_carga($minuscula, $normalizada, $anterior)
    {
        foreach (self::patrones_de_promesa() as $patron) {

            if (! preg_match_all($patron, $minuscula, $coincidencias, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($coincidencias[0] as $coincidencia) {

                $antes = mb_substr($normalizada, 0, mb_strlen(substr($minuscula, 0, $coincidencia[1]), 'UTF-8'), 'UTF-8');

                if (self::la_coincidencia_afirma('promesa', $normalizada, $antes, $anterior)) {

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * true si lo que hay ANTES de una afirmación de carga, en la misma oración, la desarma: una
     * pregunta que arranca antes ("¿Quedó registrado?"), un condicional ("si cargué mal…"), una duda
     * ("quizás ya quedó hecha"), una negación pegada ("No lo cargué", "Todavía no quedó registrado") o
     * una subordinada que describe ("el que quedó recién cargado").
     *
     * @param  string  $antes         Lo de antes, normalizado.
     * @param  string  $antes_del_si  Lo de antes donde se busca el condicional "si" + palabra.
     * @return bool
     */
    protected static function lo_de_antes_desarma_la_carga($antes, $antes_del_si)
    {
        if (strpos($antes, '¿') !== false) {

            return true;
        }

        /* "si" + palabra, o "si" pegado al verbo: "Si cargué mal el monto, avisame". */
        if (preg_match('/\bsi\s+(?:\w|$)/u', $antes_del_si)) {

            return true;
        }

        return (bool) (preg_match(self::DUDA, $antes)
            || preg_match(self::NEGACION_PEGADA, $antes)
            || preg_match(self::RELATIVO, $antes));
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
