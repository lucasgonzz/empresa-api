<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Illuminate\Support\Str;

/**
 * «¿Confirmás el envío?» SIN una propuesta de envío que confirmar (misión
 * asistente-marca-de-foto-y-envio-sin-bloque, 20/10/2026).
 *
 * 🔴 EL CASO REAL (repetición de la segunda prueba contra demo3, 20/10/2026 ~01:15, mensaje 150).
 * Después de un presupuesto, el dueño dijo «mandaselo por mail y por whatsapp asi lo ve» y el modelo
 * contestó en 3 segundos «Lo mando por WhatsApp y por mail a Marcos Perez con el PDF del presupuesto
 * 900006. ¿Confirmás el envío?» SIN llamar a proponer_envio_de_comprobante: no se creó ninguna acción,
 * así que no había bloque (a quiénes, por dónde, el texto exacto) ni nada que un «sí» pudiera mandar.
 * Recién con el «ok» siguiente propuso y mostró el bloque. Sin riesgo (nada salió), pero el dueño
 * contestaba una pregunta que no tenía contenido.
 *
 * Es la misma clase de falla que la tarjeta prometida (TarjetaPrometidaIaHelper): el texto afirma algo
 * que el turno no armó. La guarda de la tarjeta solo corre en el panel; esta es la de los canales que
 * confirman por texto (WhatsApp y MCP), y SOLO corre cuando se juntan todas estas condiciones
 * (AsistenteIaService::pide_confirmar_un_envio_sin_propuesta()):
 *   1. el texto final TERMINA pidiendo confirmar un envío (pide_confirmar_un_envio());
 *   2. el dueño pidió mandar algo en su último mensaje, o contestó «sí/dale» a una oferta de mandar
 *      (el_dueno_pidio_mandar()): sin eso, «¿te lo mando?» puede ser una oferta de información o un
 *      «envío» de otra cosa (un flete en una venta);
 *   3. el turno no llamó a NINGUNA herramienta que arme o resuelva algo (`proponer_*`,
 *      `confirmar_carga_pendiente`, `cancelar_carga_pendiente`) y no dejó ninguna tarjeta esperando:
 *      si hubo una propuesta fallida, el texto del modelo cuenta por qué y no se toca; si se acaba de
 *      ejecutar o cancelar un envío, un «¿querés que lo mande también por mail?» es una oferta nueva;
 *   4. no hay un envío esperando en la respuesta anterior (el texto puede hablar de ése).
 * Con todo eso: UN reintento con la nota (escalado como cualquier turno que toca una carga); si el
 * reintento tampoco arma la propuesta, se sacan del texto las oraciones que piden confirmar y se
 * agrega TEXTO_HONESTO (el resto de lo que dijo el modelo —el número y el total de un presupuesto— se
 * conserva).
 *
 * Del lado seguro: un falso positivo cuesta un reintento; un falso negativo deja el comportamiento
 * de antes de esta misión.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class EnvioSinPropuestaIaHelper
{
    /** Cuántas oraciones del final del mensaje se miran. */
    const ORACIONES_DEL_FINAL = 3;

    /**
     * Lo que se agrega al texto cuando el modelo pidió confirmar un envío que no armó y el reintento
     * tampoco. Dice la verdad (nada salió, nada está esperando) y da la salida: «dale» y se arma.
     */
    const TEXTO_HONESTO = 'Todavía no armé el envío, así que no hay nada para confirmar. Decime «dale» y te lo muestro completo (a quién le llega, por dónde y el texto exacto) para que lo confirmes antes de que salga.';

    /** Las herramientas que, si el turno las llamó, apagan la guarda (ver el docblock de la clase). */
    const HERRAMIENTAS_QUE_RESUELVEN = ['confirmar_carga_pendiente', 'cancelar_carga_pendiente'];

    /**
     * true si el texto TERMINA pidiendo confirmar un envío a clientes (ver el docblock de la clase).
     *
     * @param  string  $texto  El texto final, saneado (lo que leería la persona).
     * @return bool
     */
    public static function pide_confirmar_un_envio($texto)
    {
        $final = array_slice(self::oraciones((string) $texto), -self::ORACIONES_DEL_FINAL);

        $habla_de_un_envio = false;

        foreach ($final as $oracion) {

            if (self::menciona_un_envio(self::normalizar($oracion))) {

                $habla_de_un_envio = true;
            }
        }

        foreach ($final as $oracion) {

            if (self::oracion_pide_confirmar($oracion, $habla_de_un_envio)) {

                return true;
            }
        }

        return false;
    }

    /**
     * true si el dueño pidió mandar algo: su último mensaje lo dice («mandaselo», «mandales un
     * recordatorio», «enviá el presupuesto») o es un «sí/dale/ok» a una oferta de mandar del asistente.
     *
     * @param  string  $pedido  El último mensaje de la persona.
     * @param  string  $anterior_del_asistente  El mensaje del asistente al que contesta (vacío si no hay).
     * @return bool
     */
    public static function el_dueno_pidio_mandar($pedido, $anterior_del_asistente = '')
    {
        $normalizado = self::normalizar($pedido);

        if ($normalizado === '') {

            return false;
        }

        if (preg_match('/\b(?:mand|env[ií]|envi[ae])\w*|\bpasa(?:le|les|selo|sela|selos|selas)\b/', $normalizado) === 1) {

            return true;
        }

        return ConfirmacionDeterministaIaHelper::es_afirmacion($pedido)
            && self::menciona_un_envio(self::normalizar($anterior_del_asistente));
    }

    /**
     * true si este mensaje dejó una propuesta de envío (comprobante, recordatorios o promo) esperando
     * el sí. Una vencida no cuenta: no se puede confirmar.
     *
     * @param  AiMessage  $assistant_message
     * @return bool
     */
    public static function hay_propuesta_de_envio(AiMessage $assistant_message)
    {
        return AiMessageAction::where('ai_message_id', $assistant_message->id)
                              ->whereIn('tipo', HerramientasDeCarga::TIPOS_DE_ENVIO)
                              ->where('estado', AiMessageAction::ESTADO_PROPUESTA)
                              ->get()
                              ->contains(function ($accion) {
                                  return !$accion->vencio();
                              });
    }

    /**
     * true si el mensaje dejó CUALQUIER tarjeta esperando (de envío o de otra cosa): ahí el «¿confirmás?»
     * puede ser de ésa y la guarda no corre.
     *
     * @param  AiMessage  $assistant_message
     * @return bool
     */
    public static function hay_alguna_tarjeta_esperando(AiMessage $assistant_message)
    {
        return AiMessageAction::where('ai_message_id', $assistant_message->id)
                              ->where('estado', AiMessageAction::ESTADO_PROPUESTA)
                              ->exists();
    }

    /**
     * true si alguna de las herramientas que el turno llamó arma o resuelve algo: cualquier `proponer_*`,
     * `confirmar_carga_pendiente` o `cancelar_carga_pendiente`.
     *
     * @param  array<int, string>  $herramientas_pedidas
     * @return bool
     */
    public static function el_turno_ya_armo_o_resolvio_algo(array $herramientas_pedidas)
    {
        foreach ($herramientas_pedidas as $nombre) {

            $nombre = (string) $nombre;

            if (strpos($nombre, 'proponer_') === 0 || in_array($nombre, self::HERRAMIENTAS_QUE_RESUELVEN, true)) {

                return true;
            }
        }

        return false;
    }

    /**
     * La nota del sistema que se le pasa al modelo en el reintento (va como turno de la persona).
     *
     * @return string
     */
    public static function nota_para_reintentar()
    {
        return '[El sistema revisó tu respuesta: le pedís a la persona que confirme un envío a un cliente, pero en este turno NO armaste ninguna propuesta de envío '
            . '(no llamaste a proponer_envio_de_comprobante, proponer_recordatorios_de_cobro ni proponer_promocion_por_whatsapp). '
            . 'Sin la propuesta no hay bloque con a quién le llega y el texto exacto, y un «sí» no manda nada. '
            . 'Si la persona te pidió mandar algo, llamá AHORA a la herramienta de envío que corresponde con los datos que ya tenés (el número del comprobante, los canales que pidió) '
            . 'y contestá con una frase corta: el sistema agrega solo el bloque y la pregunta de confirmación. '
            . 'Si te falta un dato para armarla (a quién, qué comprobante o por qué canal), preguntalo, sin pedir una confirmación. '
            . 'No le menciones este aviso a la persona.]';
    }

    /**
     * El texto sin las oraciones que piden confirmar el envío ni las que lo prometen, más TEXTO_HONESTO.
     * Lo demás que dijo el modelo (el número y el total del presupuesto recién creado, por ejemplo) se
     * conserva: borrarlo invitaría a repetir lo que sí se hizo.
     *
     * @param  string  $texto
     * @return string
     */
    public static function texto_honesto($texto = '')
    {
        $oraciones = self::oraciones((string) $texto);

        $final = array_slice($oraciones, -self::ORACIONES_DEL_FINAL, null, true);

        $habla_de_un_envio = false;

        foreach ($final as $oracion) {

            if (self::menciona_un_envio(self::normalizar($oracion))) {

                $habla_de_un_envio = true;
            }
        }

        $quedan = [];

        foreach ($oraciones as $indice => $oracion) {

            if (isset($final[$indice]) && (self::oracion_pide_confirmar($oracion, $habla_de_un_envio) || self::promete_un_envio(self::normalizar($oracion)))) {

                continue;
            }

            $quedan[] = $oracion;
        }

        $quedan[] = self::TEXTO_HONESTO;

        return implode(' ', $quedan);
    }

    /**
     * true si la oración pide confirmar un envío.
     *
     * @param  string  $oracion  Con su puntuación original.
     * @param  bool  $hay_envio_en_el_final  Si alguna oración del final habla de mandar algo.
     * @return bool
     */
    protected static function oracion_pide_confirmar($oracion, $hay_envio_en_el_final)
    {
        $normalizada = self::normalizar($oracion);

        if ($normalizada === '') {

            return false;
        }

        $es_pregunta = mb_strpos($oracion, '?') !== false;

        /* Las preguntas por un dato son legítimas antes de proponer: «¿a quién se lo mando?». */
        if ($es_pregunta && preg_match('/\b(?:a quien|a quienes|a cual|cual|cuales|por donde|por cual|que canal|cuando|cuantos|cuantas|a cuantos)\b/', $normalizada) === 1) {

            return false;
        }

        /* «¿Por mail o por WhatsApp lo mando?» elige un canal: tampoco es una confirmación. */
        if ($es_pregunta && preg_match('/\b(?:mail|email|correo|whatsapp|wasap)\b.*\bo\b.*\b(?:mail|email|correo|whatsapp|wasap)\b/', $normalizada) === 1) {

            return false;
        }

        /* Un ofrecimiento de mandarle algo AL DUEÑO («¿querés que te lo mande también por mail?») no es un envío a clientes. */
        if (preg_match('/\bte (?:lo|la|los|las)\b/', $normalizada) === 1) {

            return false;
        }

        $pronombre = '(?:se lo|se los|se la|se las|le|les|lo|los|la|las)';

        $verbo = '(?:mando|mandamos|envio|enviamos|mande|mandemos|envie|enviemos|mandar|enviar)';

        /* Las que traen el verbo de mandar adentro: «¿Se lo mando?», «¿Les mando el recordatorio?», «¿Querés que le mande…?». */
        if ($es_pregunta) {

            if (preg_match('/\b' . $pronombre . '\s+' . $verbo . '\b/', $normalizada) === 1) {

                return true;
            }

            if (preg_match('/^(?:(?:y|entonces|listo|dale|ok)\s+)?(?:' . $pronombre . '\s+)?' . $verbo . '\b/', $normalizada) === 1) {

                return true;
            }

            if (preg_match('/\bquer(?:es|as|e)\s+que\s+(?:' . $pronombre . '\s+)?(?:mande|mandemos|envie|enviemos)\b/', $normalizada) === 1) {

                return true;
            }
        }

        /* «Contestame «sí» para mandarlo», «escribí sí para enviarlo». */
        $minusculas = mb_strtolower($oracion);

        if (preg_match('/\b(?:respond\w*|contest\w*|decime|avisame|escribime|escrib\w*)\b[^.?!\n]{0,30}(?:[«"“]\s*s[ií]\s*[»"”]|\bsí\b)[^.?!\n]{0,40}\b(?:env\w*|mand\w*)/u', $minusculas) === 1) {

            return true;
        }

        /* Las que piden confirmar sin nombrar el envío: valen si el final del mensaje habla de mandar algo. */
        if (!$hay_envio_en_el_final) {

            return false;
        }

        /* «Confirmame y sale», «confirmalo». */
        if (preg_match('/\bconfirm(?:ame|alo|ala|amelo)\b/', $normalizada) === 1) {

            return true;
        }

        if (!$es_pregunta) {

            return false;
        }

        /* «¿Confirmás el envío?», «¿Confirmás?», «¿Confirmo?», «¿Procedo?», «¿Autorizás?». «Te confirmo…» no es pregunta. */
        if (preg_match('/\b(?:confirm(?:as|ar|a|an|en|o)|procedo|avanzo|autorizas|autorizar)\b/', $normalizada) === 1) {

            return true;
        }

        /* La pregunta suelta de cierre: «¿Dale?», «¿Te parece bien?», «¿Va?». */
        return preg_match('/^(?:dale|va|ok|okey|te parece|te parece bien|de acuerdo|esta bien|lo hago)$/', $normalizada) === 1;
    }

    /**
     * true si el texto (normalizado) menciona mandar algo.
     *
     * @param  string  $normalizada
     * @return bool
     */
    protected static function menciona_un_envio($normalizada)
    {
        return preg_match('/\b(?:env\w*|mand\w*)\b/', (string) $normalizada) === 1;
    }

    /**
     * true si el texto (normalizado) dice que se va a mandar algo a alguien («Lo mando por WhatsApp…»).
     *
     * @param  string  $normalizada
     * @return bool
     */
    protected static function promete_un_envio($normalizada)
    {
        return preg_match('/\b(?:lo|la|los|las|se lo|se la|se los|se las|le|les)\s+(?:mando|mandamos|envio|enviamos)\b|\bvoy a (?:mandar|enviar)\b/', $normalizada) === 1;
    }

    /**
     * El texto partido en oraciones (cortadas en . ? ! y saltos de línea), sin las vacías.
     *
     * @param  string  $texto
     * @return array<int, string>
     */
    protected static function oraciones($texto)
    {
        /* Un punto seguido de un dígito («$130.436,74») no corta la oración. */
        $partes = preg_split('/(?<=[?!\n])|(?<=\.)(?!\d)/u', $texto, -1, PREG_SPLIT_NO_EMPTY);

        if (!is_array($partes)) {

            return [];
        }

        return array_values(array_filter(array_map('trim', $partes), function ($parte) {
            return $parte !== '';
        }));
    }

    /**
     * Minúsculas, sin tildes, sin signos ni comillas y con un solo espacio entre palabras.
     *
     * @param  string  $texto
     * @return string
     */
    protected static function normalizar($texto)
    {
        $limpio = Str::lower(Str::ascii((string) $texto));

        return trim((string) preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]+/', ' ', $limpio)));
    }
}
