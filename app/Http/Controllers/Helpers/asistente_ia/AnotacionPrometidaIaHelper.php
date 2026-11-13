<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

/**
 * «Me lo anoto» SIN haber guardado nada (A5 de la misión asistente-truvari-pedidos-fallidos, 13/11/2026).
 *
 * 🔴 EL CASO REAL (Truvari, 12/11/2026, por WhatsApp). El asistente dijo «Me lo anoto» seis veces (80, 92,
 * 134, 148, 152, 156) —una caja es una unidad, una sola sucursal y un solo depósito, la cepa solo en los
 * vinos, contestar más rápido— y NUNCA llamó a guardar_en_contexto: no quedó nada en la memoria y la
 * conversación siguiente no sabía nada. La regla de la memoria le enseñaba «Me lo anoto.» como la frase
 * de cuando guarda, y el modelo la usaba de muletilla sin guardar.
 *
 * La guarda corre en todos los canales. Si el texto dice que lo anota (o que lo va a recordar) y en el
 * turno no hubo un guardar_en_contexto que contestara ok, va el único reintento del turno pidiéndole la
 * herramienta (o, sin memoria, que no lo diga) y, si sigue, se saca esa oración y se avisa la verdad.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class AnotacionPrometidaIaHelper
{
    /** El aviso que reemplaza lo que se sacó. */
    const AVISO = 'Ojo: esto no me quedó guardado en la memoria, así que en otra conversación no lo voy a saber.';

    /** Lo que afirma que lo guarda, normalizado. */
    const AFIRMA = '/\b(?:me (?:lo|la|los|las) anoto|me anoto|(?:te |ya )?(?:lo|la|los|las) anoto|anoto (?:la|el|lo|los|las|esto|eso|que|como|tu|tus|esta|este)\b|(?:lo|la|los|las) dejo anotad[oa]s?|queda anotad[oa]|(?:lo|la) tengo anotad[oa]|(?:lo|la|los|las) guardo en (?:mi|la) memoria|me (?:lo|la) guardo|(?:lo|la|los|las) voy a recordar|(?:lo|la) recuerdo para (?:la proxima|las proximas|adelante)|(?:lo|la|los|las) (?:tengo|tendre|voy a tener) en cuenta(?: para la proxima| para las proximas| para adelante| de ahora en mas| desde ahora| en adelante)?)\b/';

    /*
     * 🔴 D4 de la revisión independiente (13/11/2026): «anoto» también es cargar. «Te anoto el gasto de $5000
     * en Varios. ¿Confirmás?», «Listo, queda anotado el pago de $10.000», «Anoto la tarea para mañana», «Lo
     * tengo en cuenta para el total: $45.000» hablan de una carga o de una cuenta, no de la memoria. Si en
     * las palabras de después aparece una de éstas, o la oración trae plata, no es una anotación de la
     * memoria (y el servicio no mira la guarda si el turno armó o ejecutó alguna carga).
     */
    const OBJETO_DE_CARGA = '/^(?:\S+\s+){0,3}?(?:gastos?|pagos?|ventas?|tareas?|pedidos?|presupuestos?|cobros?|compras?|recordatorios?|turnos?|cheques?|stock|precios?|total|totales|monto|importe|clientes?|proveedores?|articulos?|productos?|cajas?|unidades|deudas?|saldos?|facturas?|movimientos?)\b/';

    /** Lo que, antes en la oración, desarma la afirmación: un condicional, una oferta, una negación. */
    const DESARMA = '/(?:^|\s)(?:si|cuando|queres que|puedo|podria|no|nunca|ni)\s/';

    /**
     * true si el texto dice que lo anota.
     *
     * @param  string  $texto
     * @return bool
     */
    public static function dice_que_lo_anota($texto)
    {
        return VeracidadDelTurnoIaHelper::alguna_oracion($texto, function ($oracion) {
            return self::oracion_lo_anota($oracion);
        });
    }

    /**
     * La nota del reintento.
     *
     * @param  bool  $tiene_memoria  true si el turno tiene guardar_en_contexto entre sus herramientas.
     * @return string
     */
    public static function nota_para_reintentar($tiene_memoria)
    {
        if (! $tiene_memoria) {

            return '[El sistema revisó tu respuesta: decís que lo anotás o que lo vas a recordar, pero en este negocio no tenés memoria entre conversaciones: '
                . 'no queda nada guardado. No digas que lo anotás ni que lo vas a tener en cuenta la próxima vez. '
                . VeracidadDelTurnoIaHelper::FRASE_DE_LA_RESPUESTA_COMPLETA;
        }

        return '[El sistema revisó tu respuesta: decís que lo anotás («me lo anoto»), pero en este turno NO llamaste a guardar_en_contexto: '
            . 'no quedó nada guardado y en la próxima conversación no lo vas a saber. '
            . 'Si es algo que conviene recordar (una corrección, una preferencia, cómo trabaja el negocio), llamá AHORA a guardar_en_contexto '
            . '(las correcciones van en aprendizajes/correcciones.md, cómo quiere que le respondan en dueno/como_responder.md) y recién con su ok decí «Me lo anoto.». '
            . 'Si no hace falta guardarlo, no digas que lo anotás. '
            . VeracidadDelTurnoIaHelper::FRASE_DE_LA_RESPUESTA_COMPLETA;
    }

    /**
     * El texto sin lo que dice que lo anota, con el AVISO. «Me lo anoto: la cepa va solo en los vinos.» deja
     * «La cepa va solo en los vinos.»: lo que viene después de los dos puntos es lo que la persona pidió.
     *
     * @param  string  $texto
     * @return string
     */
    public static function sin_lo_anotado($texto)
    {
        return VeracidadDelTurnoIaHelper::reemplazar_oraciones($texto, function ($oracion) {

            if (! self::oracion_lo_anota($oracion)) {
                return null;
            }

            /*
             * «Me lo anoto: X» o «Anoto la corrección: X» → «X»; «Me lo anoto, y ahora cargo X» → «Ahora
             * cargo X» (la afirmación al principio, cortada en la primera coma).
             */
            $dos_puntos = mb_strpos($oracion, ':');
            $coma = mb_strpos($oracion, ',');

            if ($coma !== false && ($dos_puntos === false || $coma < $dos_puntos) && $coma <= 40
                && self::oracion_lo_anota(mb_substr($oracion, 0, $coma))) {

                $dos_puntos = $coma;
            }

            if ($dos_puntos !== false) {

                $resto = trim((string) preg_replace('/^(?:y|e)\s+/iu', '', trim(mb_substr($oracion, $dos_puntos + 1))));

                if ($resto !== '' && ! self::oracion_lo_anota($resto)) {
                    return mb_strtoupper(mb_substr($resto, 0, 1)) . mb_substr($resto, 1);
                }
            }

            return '';
        }, self::AVISO);
    }

    /**
     * true si la oración dice que lo anota (y no lo ofrece, lo pregunta ni lo niega).
     *
     * @param  string  $oracion
     * @return bool
     */
    protected static function oracion_lo_anota($oracion)
    {
        if (VeracidadDelTurnoIaHelper::es_pregunta($oracion)) {
            return false;
        }

        $n = VeracidadDelTurnoIaHelper::normalizar($oracion);

        if (! preg_match(self::AFIRMA, $n, $coincidencia, PREG_OFFSET_CAPTURE)) {
            return false;
        }

        /* D4 de la revisión: una carga o una cuenta, no la memoria. */
        if (strpos((string) $oracion, '$') !== false
            || preg_match(self::OBJETO_DE_CARGA, ltrim(substr($n, $coincidencia[0][1] + strlen($coincidencia[0][0]))))) {
            return false;
        }

        return ! preg_match(self::DESARMA, ' ' . substr($n, 0, $coincidencia[0][1]));
    }
}
