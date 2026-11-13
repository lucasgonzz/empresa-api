<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

/**
 * «¿Lo cargo?» SIN una propuesta de carga que confirmar (A2 de la misión asistente-truvari-pedidos-fallidos,
 * 13/11/2026). Es la generalización de EnvioSinPropuestaIaHelper a TODA carga.
 *
 * 🔴 EL CASO REAL (Truvari, conversación 7 del 12/11/2026, por WhatsApp). «El aceite de oliva Familia
 * Zuccardi Coratina 500 ml pasa a 6 unidades de stock. ¿Lo cargo?» (152), «Me lo anoto, y ahora cargo las
 * 6 unidades del aceite Coratina. ¿Confirmás?» (156) y «Dejo el Nieto Senetiner Brut Nature en 9 unidades.
 * ¿Lo cargo?» (162): ninguna llamó a proponer_stock, así que no había nada esperando el sí. El dueño
 * contestó «Si» y el asistente inventó la carga («Quedó en 9 unidades»: lo agarra la guarda de la carga
 * dada por hecha, A1). Lo mismo con el presupuesto («¿Lo preparo así?», 124; «Corrijo el presupuesto 25…
 * ¿Lo dejo así?», 130) y el borrado («¿Los elimino?», 142).
 *
 * Corre en los canales que confirman por texto (WhatsApp y MCP): en el panel la confirmación es la
 * tarjeta, y la cubre la guarda de la tarjeta prometida. SOLO cuando se juntan
 * (AsistenteIaService::pide_confirmar_una_carga_sin_propuesta()):
 *   1. una de las dos últimas oraciones es una pregunta que pide confirmar una carga
 *      (pide_confirmar_una_carga()): «¿Lo cargo?», «¿Lo registro así?», «¿Los elimino?», «¿Confirmás?»,
 *      «¿Querés que lo registre?» — y no una pregunta por un dato («¿Con qué fecha lo cargo?») ni una que
 *      elige entre dos («¿La dejo así o preferís dos tareas?»); y el texto trae los DATOS de la carga
 *      (una cantidad, un monto, un número): «Es un aceite. ¿Lo cargo?» ofrece la carga, no la confirma;
 *   2. el turno no llamó a NINGUNA herramienta que arme o resuelva algo (proponer_*, confirmar o cancelar)
 *      y no dejó ninguna carga esperando;
 *   3. no hay una carga esperando en la respuesta anterior (la pregunta puede ser de ésa);
 *   4. no es un envío a clientes (eso es de EnvioSinPropuestaIaHelper, que corre antes).
 * Con todo eso: el único reintento del turno con la nota y, si sigue, se saca la pregunta y va
 * TEXTO_HONESTO. Del lado seguro: un falso positivo cuesta un reintento.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class CargaSinPropuestaIaHelper
{
    /** Cuántas oraciones del final se miran. */
    const ORACIONES_DEL_FINAL = 2;

    /** Lo que reemplaza la pregunta si el reintento tampoco armó la propuesta. */
    const TEXTO_HONESTO = 'Ojo: todavía no lo dejé preparado, así que un «sí» ahora no registra nada. Decime «dale» y lo preparo con estos datos.';

    /*
     * 🔴 D5 de la revisión independiente (13/11/2026): los verbos se separan en dos. Los que SOLO son una
     * carga («¿Cargo las 6?», «¿Registro el pago?», «¿Lo elimino?») valen solos; los que también ofrecen
     * información o un trabajo («¿Te paso el detalle?», «¿Te armo un presupuesto?», «¿Te hago un resumen?»,
     * «¿Te bajo el Excel?», «¿Cambio algo?») valen solo con el pronombre del objeto ya nombrado: «¿Lo dejo
     * así?», «¿Lo preparo así?», «¿Lo cambio?». Con el dueño en "directo", tomar una oferta por una
     * confirmación terminaría en una escritura que nadie pidió.
     */

    /** Los verbos de carga en primera persona del presente que no pueden ser otra cosa, normalizados. */
    const VERBO_DE_CARGA = '(?:cargo|registro|elimino|borro|agendo|anulo|doy de alta|doy de baja)';

    /** Los que necesitan el pronombre del objeto («lo», «la», «los», «las») para ser una carga. */
    const VERBO_CON_PRONOMBRE = '(?:preparo|guardo|actualizo|cambio|corrijo|confirmo|modifico|ajusto|dejo|aplico|creo|armo|hago|paso|subo|bajo)';

    /** Los mismos en subjuntivo, para «¿Querés que lo registre?». */
    const SUBJUNTIVO_DE_CARGA = '(?:cargue|registre|elimine|borre|agende|anule|de de alta|de de baja)';

    const SUBJUNTIVO_CON_PRONOMBRE = '(?:prepare|guarde|actualice|cambie|corrija|confirme|modifique|ajuste|deje|aplique|cree|arme|haga|pase|suba|baje)';

    /**
     * true si alguna de las últimas oraciones del texto pide confirmar una carga.
     *
     * @param  string  $texto  El texto final, saneado.
     * @return bool
     */
    public static function pide_confirmar_una_carga($texto)
    {
        $oraciones = VeracidadDelTurnoIaHelper::oraciones($texto);
        $total = count($oraciones);

        foreach ($oraciones as $indice => $oracion) {

            if ($indice < $total - self::ORACIONES_DEL_FINAL || ! self::oracion_pide_confirmar($oracion)) {
                continue;
            }

            /*
             * Solo una confirmación con los DATOS de la carga (lo que pide la regla de la confirmación por
             * texto: «decí los datos exactos y preguntá si lo registrás»), en la MISMA oración de la pregunta o
             * en la de antes (D5 de la revisión): un dígito en cualquier parte del texto no alcanza. «Es un
             * aceite. ¿Lo cargo?» —sin un dato— es ofrecer la carga, no confirmarla.
             */
            $con_la_de_antes = $oracion . ' ' . ($indice > 0 ? $oraciones[$indice - 1] : '');

            if (self::trae_los_datos($con_la_de_antes)) {
                return true;
            }
        }

        return false;
    }

    /**
     * true si alguna de las últimas oraciones del texto pide confirmar una carga, traiga o no los datos (D1
     * de la revisión independiente): es lo que se mira en la respuesta ANTERIOR del asistente para saber si
     * el «sí» de la persona contesta un pedido de confirmación.
     *
     * @param  string  $texto
     * @return bool
     */
    public static function termina_pidiendo_confirmar($texto)
    {
        foreach (array_slice(VeracidadDelTurnoIaHelper::oraciones($texto), -self::ORACIONES_DEL_FINAL) as $oracion) {

            if (self::oracion_pide_confirmar($oracion)) {
                return true;
            }
        }

        return false;
    }

    /**
     * La nota del reintento.
     *
     * @return string
     */
    public static function nota_para_reintentar()
    {
        return '[El sistema revisó tu respuesta: le pedís a la persona que confirme una carga («¿Lo cargo?», «¿Lo registro?», «¿Confirmás?»), '
            . 'pero en este turno NO armaste ninguna propuesta: no llamaste a ninguna herramienta proponer_. Sin la propuesta, el «sí» de la persona no tiene nada que registrar. '
            . 'Llamá AHORA a la herramienta proponer_ que corresponde con los datos que ya tenés y contá lo que te devuelva: si quedó hecha, con su número; '
            . 'si quedó esperando el sí, con los datos exactos y la pregunta. '
            . 'Si te falta un dato para armarla, preguntalo sin pedir una confirmación. '
            . 'Si no hay ninguna herramienta para eso, decí en una oración que desde acá no se puede y qué sí podés hacer. '
            . VeracidadDelTurnoIaHelper::FRASE_DE_LA_RESPUESTA_COMPLETA;
    }

    /**
     * El texto sin las preguntas de confirmación del final, con TEXTO_HONESTO.
     *
     * @param  string  $texto
     * @return string
     */
    public static function texto_honesto($texto)
    {
        return VeracidadDelTurnoIaHelper::sin_las_oraciones($texto, function ($oracion, $indice, array $todas) {
            return $indice >= count($todas) - self::ORACIONES_DEL_FINAL && self::oracion_pide_confirmar($oracion);
        }, self::TEXTO_HONESTO);
    }

    /**
     * true si el texto trae algún dato concreto de la carga: un número (cantidad, monto, N° de
     * comprobante) o una cantidad en palabras («una caja de Perro Callejero», «dos de Ricardo Santos»).
     *
     * @param  string  $texto
     * @return bool
     */
    protected static function trae_los_datos($texto)
    {
        $n = VeracidadDelTurnoIaHelper::normalizar($texto);

        return (bool) preg_match('/\d|\b(?:una|un|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|doce|media) (?:caja|cajas|unidad|unidades|botella|botellas|docena|docenas|de)\b/', $n);
    }

    /**
     * true si la oración es una pregunta que pide confirmar una carga.
     *
     * @param  string  $oracion  Con su puntuación.
     * @return bool
     */
    protected static function oracion_pide_confirmar($oracion)
    {
        if (! VeracidadDelTurnoIaHelper::es_pregunta($oracion)) {
            return false;
        }

        /* Solo la pregunta: «Te propongo esto, ¿lo cargo?» → «lo cargo». */
        $pregunta = $oracion;
        $abre = mb_strpos($oracion, '¿');

        if ($abre !== false) {
            $pregunta = mb_substr($oracion, $abre);
        }

        $n = VeracidadDelTurnoIaHelper::normalizar($pregunta);

        if ($n === '') {
            return false;
        }

        /* Una pregunta por un dato («¿Con qué fecha lo cargo?», «¿A cuál lo paso?») es legítima antes de proponer. */
        if (preg_match('/\b(?:que|cual|cuales|cuanto|cuanta|cuantos|cuantas|cuando|donde|a quien|a quienes)\b/', (string) preg_replace('/\bqueres que\b/', ' ', $n))) {
            return false;
        }

        /* Elegir entre dos («¿La dejo así o preferís cuatro tareas?», «¿Lo cargo en efectivo o con tarjeta?»). */
        if (preg_match('/\bo\s+(?:prefer|quer|mejor|lo|la|los|las|con|en|por|a|al|solo|cuatro|dos|tres|separad)\w*/', $n)) {
            return false;
        }

        /* Envíos: son de EnvioSinPropuestaIaHelper. */
        if (preg_match('/\b(?:mando|mande|envio|envie|enviar|mandar)\b/', $n)) {
            return false;
        }

        $apertura = '^(?:(?:y|entonces|listo|dale|ok|bueno|bien)\s+)?(?:(?:te|se|le|les)\s+)?';

        /* «¿Lo cargo?», «¿Cargo las 6 unidades?», «¿Los elimino?», «¿Lo registro así?». */
        if (preg_match('/' . $apertura . '(?:(?:lo|la|los|las)\s+)?' . self::VERBO_DE_CARGA . '\b/', $n)) {
            return true;
        }

        /* «¿Lo dejo así?», «¿Te lo preparo?», «¿Lo cambio?» — y no «¿Te armo un presupuesto?» ni «¿Cambio algo?». */
        if (preg_match('/' . $apertura . '(?:lo|la|los|las)\s+' . self::VERBO_CON_PRONOMBRE . '\b/', $n)) {
            return true;
        }

        /*
         * «¿Confirmás?», «¿Me confirmás?», «¿Lo confirmás así?», «¿Confirmás estos datos del alta?» — no «¿Me
         * confirmás que figura así?» ni, desde D5 de la revisión, «¿Me confirmás el nombre?» (un artículo
         * detrás: pregunta por un dato).
         */
        if (preg_match('/^(?:(?:y|entonces|listo|dale|ok|bueno)\s+)?(?:(?:me|lo|la|los|las|me lo|me la)\s+)?confirmas\b(?!\s+(?:que|si|cual|como|donde|el|la|los|las|su|sus|tu|tus|un|una)\b)/', $n)) {
            return true;
        }

        /* «¿Querés que lo registre?», «¿Querés que te lo cargue así?» — no «¿Querés que te arme un presupuesto?». */
        return (bool) preg_match('/\bqueres que\s+(?:(?:te|se|le|les)\s+)?(?:(?:(?:lo|la|los|las)\s+)?' . self::SUBJUNTIVO_DE_CARGA . '|(?:lo|la|los|las)\s+' . self::SUBJUNTIVO_CON_PRONOMBRE . ')\b/', $n);
    }
}
