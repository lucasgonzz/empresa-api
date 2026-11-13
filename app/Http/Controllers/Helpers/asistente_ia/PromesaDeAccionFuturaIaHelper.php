<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

/**
 * «Reviso y te confirmo en un momento», «Busco y te paso el link», «Dame un momento»: la PROMESA DE UNA
 * ACCIÓN FUTURA (A4 de la misión asistente-truvari-pedidos-fallidos, 13/11/2026).
 *
 * 🔴 NO EXISTE UN «DESPUÉS». Cada respuesta del asistente termina el turno: no queda nada corriendo en
 * segundo plano y el asistente no le vuelve a escribir a nadie por su cuenta. Truvari, conversación 7 del
 * 12/11/2026, por WhatsApp, seis veces en media hora: «Reviso cómo está publicado el artículo en la tienda
 * y te confirmo…» (166), «Busco la publicación del Nieto Senetiner Brut Nature en la tienda y te paso el
 * link.» (172), «Reviso y te confirmo en un momento.» (178), «…la reviso en la conversación anterior antes
 * de contestarte cualquier cosa. Dame un momento.» (188), «Reviso esa conversación para ver de dónde salió
 * el link…» (198), «Voy a revisar la consulta de ese artículo. Dame un momento.» (202). Nunca volvió. El
 * dueño: «estás muy lento, así no me sirve».
 *
 * La guarda corre en TODOS los canales (en el panel tampoco hay un después). Si el texto final promete
 * algo para más tarde, va el único reintento del turno con la nota («este mensaje es tu única respuesta:
 * llamá ahora a la herramienta o decí que no podés»); si sigue, se cambian esas oraciones por
 * TEXTO_HONESTO.
 *
 * Qué cuenta como promesa (en una oración que NO es pregunta, sin condicional ni pedido de un dato antes):
 *   - las esperas: «dame un momento», «un segundo», «ya vuelvo», «aguardame»;
 *   - «te confirmo / te aviso / te paso…» con un tiempo: «en un rato», «enseguida», «apenas lo tenga»;
 *   - un verbo de búsqueda en primera persona del presente al empezar una cláusula («Reviso…», «Lo
 *     busco…», «…y la reviso…», «Voy a revisar…», «Ahora me fijo…») si la misma oración trae la entrega
 *     para después («y te confirmo», «y te paso el link») o si es la ÚLTIMA oración del texto: lo último
 *     que dice el turno es que va a buscar, y no hay nada después.
 * Qué no: «Decime el nombre y lo busco» o «pedímelo de nuevo y lo busco» (espera a la persona), «Si querés,
 * lo reviso», «¿Querés que lo revise?», «No puedo revisarlo», «lo verifico la próxima vez que me lo pidas»,
 * «Te paso el link:» (lo está pasando) y el pasado («Revisé…»).
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class PromesaDeAccionFuturaIaHelper
{
    /** Lo que reemplaza la promesa si el reintento tampoco cumple. */
    const TEXTO_HONESTO = 'Ojo: no sigo trabajando después de contestarte, así que eso todavía no lo revisé. Pedímelo de nuevo y lo consulto en la misma respuesta.';

    /** Los verbos de buscar en primera persona del presente (normalizados). */
    const VERBO_DE_BUSQUEDA = '(?:reviso|busco|consulto|verifico|chequeo|averiguo|me fijo|miro|investigo|rastreo|pruebo|vuelvo a revisar|vuelvo a buscar|vuelvo a consultar)(?!\s+que\b)';

    /** «Voy a …» con un verbo de búsqueda. */
    const VOY_A = 'voy a (?:revisar|buscar|consultar|verificar|chequear|averiguar|fijarme|mirar|investigar|probar|ver)(?:lo|la|los|las)?';

    /** La entrega para después, en la misma oración. */
    const ENTREGA_DESPUES = '/\by\s+(?:despues\s+|enseguida\s+|ahi\s+)?(?:te|le|les)\s+(?:(?:lo|la|los|las)\s+)?(?:confirmo|paso|aviso|cuento|digo|mando|escribo|respondo|contesto|muestro)\b/';

    /** Una espera o una entrega con tiempo. */
    const ESPERA = '/\b(?:dame un (?:momento|segundo|minuto|ratito|rato)|dame unos (?:minutos|segundos)|ya vuelvo|ahora vuelvo|enseguida vuelvo|aguardame|esperame un|(?:te|le|les) (?:(?:lo|la|los|las) )?(?:confirmo|aviso|paso|mando|cuento|escribo|respondo|contesto|digo) (?:en un (?:momento|rato|ratito|segundo|minuto)|enseguida|en breve|mas tarde|despues|apenas (?:lo|la|los|las) tenga|cuando (?:lo|la|los|las) tenga|en cuanto (?:lo|la|los|las) tenga))\b|^(?:un|un segundo|un momento|un minuto)$/';

    /** Lo que desarma la promesa si va antes en la oración: un condicional, un pedido a la persona, una oferta, una negación. */
    const DESARMA = '/(?:^|\s)(?:si|cuando|apenas|en cuanto|una vez que|queres que|puedo|podria|podes|preferis|decime|pasame|mandame|confirmame|avisame|indicame|contame|escribime|pedimelo|pedime|no|nunca|sin)\s/';

    /** Un pedido a la persona en voseo («Subí el Excel», «Mandame la foto»), con tildes: la promesa espera su respuesta. */
    const IMPERATIVO_DE_VOSEO = '/(?<!\p{L})(?:mandá|mandame|mandámelo|pasá|pasame|pasámelo|subí|subilo|subila|cargá|escribí|escribime|abrí|elegí|decí|decime|pedí|pedime|pedímelo|poné|hacé|fijate|mirá|revisá|buscá|enviá|enviame|reenviá|reenviame|sacá|tocá|entrá|andá|probá|confirmá|confirmame|avisá|avisame|contestá|respondé|copiá|pegá|compartí|adjuntá)(?!\p{L})/u';

    /** Lo que la hace no ser de ahora: la próxima vez que la persona lo pida. */
    const PROXIMA_VEZ = '/\b(?:la proxima vez|la proxima que|cuando me lo (?:pidas|vuelvas a pedir))\b/';

    /**
     * true si el texto promete hacer algo después de esta respuesta.
     *
     * @param  string  $texto  El texto final, saneado.
     * @return bool
     */
    public static function promete_una_accion_futura($texto)
    {
        return VeracidadDelTurnoIaHelper::alguna_oracion($texto, function ($oracion, $indice, array $todas) {
            return self::oracion_promete($oracion, $indice === count($todas) - 1);
        });
    }

    /**
     * La nota del reintento.
     *
     * @return string
     */
    public static function nota_para_reintentar()
    {
        return '[El sistema revisó tu respuesta: prometés hacer algo después («reviso y te confirmo», «busco y te paso», «dame un momento»), '
            . 'pero este mensaje es tu ÚNICA respuesta: cuando termina no seguís trabajando en segundo plano ni le volvés a escribir a la persona. '
            . 'Llamá AHORA a la herramienta que corresponde y contestá con el resultado. '
            . 'Si no tenés ninguna herramienta para eso, decí en una oración que desde acá no lo podés hacer y qué sí podés. '
            . 'Si te falta un dato de la persona, pedíselo sin prometer nada para después. '
            . VeracidadDelTurnoIaHelper::FRASE_DE_LA_RESPUESTA_COMPLETA;
    }

    /**
     * El texto sin las oraciones que prometen, con TEXTO_HONESTO.
     *
     * @param  string  $texto
     * @return string
     */
    public static function sin_lo_prometido($texto)
    {
        return VeracidadDelTurnoIaHelper::sin_las_oraciones($texto, function ($oracion, $indice, array $todas) {
            return self::oracion_promete($oracion, $indice === count($todas) - 1);
        }, self::TEXTO_HONESTO);
    }

    /**
     * true si la oración promete una acción para después.
     *
     * @param  string  $oracion
     * @param  bool  $es_la_ultima  Si es la última oración del texto.
     * @return bool
     */
    protected static function oracion_promete($oracion, $es_la_ultima)
    {
        if (VeracidadDelTurnoIaHelper::es_pregunta($oracion)) {
            return false;
        }

        $n = VeracidadDelTurnoIaHelper::normalizar($oracion);

        if ($n === '' || preg_match(self::PROXIMA_VEZ, $n)) {
            return false;
        }

        /* «Subí el Excel y lo reviso», «Mandame la foto y la busco»: espera algo de la persona. */
        if (preg_match(self::IMPERATIVO_DE_VOSEO, mb_strtolower((string) $oracion, 'UTF-8'))) {
            return false;
        }

        if (preg_match(self::ESPERA, $n, $espera, PREG_OFFSET_CAPTURE)) {

            if (! preg_match(self::DESARMA, ' ' . substr($n, 0, $espera[0][1]))) {
                return true;
            }
        }

        /* El verbo de búsqueda al empezar una cláusula: al principio, después de una coma o de «y», «ahora», «ya», «primero»… */
        $patron = '/(?:^|,\s*|\b(?:y|ahora|ya|enseguida|primero|asi que|entonces)\s+)(?:(?:te|se|le|les)\s+)?(?:(?:lo|la|los|las)\s+)?(?:' . self::VERBO_DE_BUSQUEDA . '|' . self::VOY_A . ')\b/';

        if (! preg_match_all($patron, $n, $coincidencias, PREG_OFFSET_CAPTURE)) {
            return false;
        }

        foreach ($coincidencias[0] as $coincidencia) {

            $antes = ' ' . substr($n, 0, $coincidencia[1]);

            if (preg_match(self::DESARMA, $antes)) {
                continue;
            }

            if ($es_la_ultima || preg_match(self::ENTREGA_DESPUES, substr($n, $coincidencia[1]))) {
                return true;
            }
        }

        return false;
    }
}
