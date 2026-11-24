<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiConversation;
use App\Models\AiMessage;

/**
 * Detecta si el pedido del dueño habla de TAREAS PROGRAMADAS del asistente (misión agente-tareas-programadas,
 * arreglo «pensamiento», 24/11/2026): programar un aviso, pedir que le escriba o le cuente algo a una hora,
 * posponer, cancelar, cambiar horarios o consultar lo que tiene programado.
 *
 * 🔴 PARA QUÉ. Medición real en demo3 (misma conversación de 12 pedidos): con el modelo rápido 5 a 6 de 12
 * respuestas afirmaban haber programado o cancelado sin hacerlo, aun con la lista real de tareas en el contexto;
 * con el modo «pensamiento» (profundo) fue 1 de 12. AsistenteIaService usa este detector UNA vez por turno para
 * correr en profundo los turnos donde el dueño pide algo sobre tareas. No se toca el texto de ninguna respuesta.
 *
 * 🔴 DETECCIÓN GENEROSA, SOBRE TEXTO PURO. Un falso positivo sólo cuesta una respuesta más lenta y más cara, así
 * que ante la duda entre disparar o no (un pedido que nombra un momento futuro más un verbo de aviso) dispara. El
 * texto se normaliza (minúsculas, sin acentos, sin signos) y se mira con expresiones regulares: cero consultas.
 * La única lectura de la base es la de aplica() para un «sí» corto, y es UN mensaje anterior del asistente.
 *
 * PHP 7.4: sin nullsafe, sin union types, sin argumentos nombrados, sin las funciones str_* de PHP 8.
 */
class PedidoSobreTareasIaHelper
{
    /** Los días de la semana, sin acentos (el plural se tolera donde importa: «los domingos»). */
    const DIAS = 'lunes|martes|miercoles|jueves|viernes|sabado|domingo';

    /** Cantidades que se dicen en un horario o en un plazo: «a las 9», «a la una», «en 20 minutos», «en media hora». */
    const CANTIDADES = '\d{1,2}|una|un|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|once|doce|quince|veinte|treinta|media';

    /** Verbos con los que se le pide algo al asistente en voseo, ya normalizados (el pedido de aviso, de cuenta, de recuerdo). */
    const VERBOS_DE_AVISO = 'avisa|avisame|avisanos|escribi|escribime|escribinos|pregunta|preguntame|preguntanos|conta|contame|contanos|manda|mandame|mandanos|envia|enviame|recorda|recordame|recuerda|recuerdame|acordate|informa|informame|reporta|reportame|resumi|resumime|programa|agenda|agendame';

    /** Verbos de cancelar o sacar algo, ya normalizados. */
    const VERBOS_DE_CANCELAR = 'cancela|cancelame|cancelalo|cancelala|cancelalas|cancelalos|cancelar|cancelas|saca|sacame|sacalo|sacala|sacar|borra|borrame|borralo|borrala|borrar|elimina|eliminalo|eliminala|eliminar|anula|anulalo|anulala|anular|pausa|pausalo|pausala|pausar|frena|frenalo|frenala|desactiva|desactivalo|desactivala|suspende|suspendelo|suspendela|quita|quitalo|quitala';

    /**
     * true si el texto del dueño habla de tareas programadas (ver el docblock de la clase y las reglas de
     * momento, programar, cancelar, no molestar, posponer, cambiar horarios y consultar).
     *
     * @param  string|null  $texto  Lo último que escribió la persona.
     * @return bool
     */
    public static function habla_de_tareas($texto): bool
    {
        $normalizado = self::normalizar((string) $texto);

        if ($normalizado === '') {

            return false;
        }

        return self::pide_avisar_en_un_momento($normalizado)
            || self::pide_programar($normalizado)
            || self::pide_cancelar($normalizado)
            || self::pide_no_ser_molestado($normalizado)
            || self::pide_posponer($normalizado)
            || self::cambia_un_horario($normalizado)
            || self::consulta_lo_programado($normalizado);
    }

    /**
     * true si el turno tiene que correr en profundo por lo que escribió la persona: el pedido habla de tareas, o
     * es un «sí» corto que contesta a una pregunta del asistente sobre tareas («¿La cancelo?»). Lee a lo sumo UN
     * mensaje anterior del asistente, y sólo cuando el pedido es una confirmación corta.
     *
     * @param  \App\Models\AiConversation  $conversation  La conversación del turno.
     * @param  \App\Models\AiMessage  $assistant_message  El `assistant` del turno que se está respondiendo.
     * @param  string  $pedido  El último mensaje de la persona (AsistenteIaService::pedido_de_la_persona()).
     * @return bool
     */
    public static function aplica(AiConversation $conversation, AiMessage $assistant_message, $pedido): bool
    {
        if (self::habla_de_tareas($pedido)) {

            return true;
        }

        if (!self::es_confirmacion_corta($pedido)) {

            return false;
        }

        /* La respuesta inmediatamente anterior del asistente: la pregunta a la que la persona contesta «sí». */
        $anterior = AiMessage::where('ai_conversation_id', $conversation->id)
                             ->where('rol', 'assistant')
                             ->where('id', '<', $assistant_message->id)
                             ->orderBy('id', 'DESC')
                             ->value('contenido');

        return self::el_asistente_hablo_de_tareas((string) $anterior);
    }

    /**
     * true si el texto es un «sí» corto: una afirmación suelta («sí», «dale», «ok») o un mensaje breve que empieza
     * con una («Sí, cancelala»). Con signo de pregunta nunca lo es.
     *
     * @param  string|null  $texto
     * @return bool
     */
    public static function es_confirmacion_corta($texto): bool
    {
        $texto = trim((string) $texto);

        if ($texto === '' || preg_match('/[?¿]/u', $texto)) {

            return false;
        }

        if (ConfirmacionDeterministaIaHelper::es_afirmacion($texto)) {

            return true;
        }

        /* «Sí, cancelala»: la primera cláusula es la afirmación y el resto es corto. */
        $partes = preg_split('/[,.;:!\n]/u', $texto, 2);
        $primera = is_array($partes) ? trim((string) $partes[0]) : '';

        return $primera !== '' && $primera !== $texto && mb_strlen($texto) <= 60
            && ConfirmacionDeterministaIaHelper::es_afirmacion($primera);
    }

    /**
     * true si la respuesta anterior del asistente habló de tareas: el mismo detector aplicado a su texto, o alguna
     * de las palabras «tarea», «programad» o «cancel» (la pregunta «¿La cancelo?» es de esta clase).
     *
     * @param  string  $texto  El contenido de la respuesta anterior del asistente.
     * @return bool
     */
    public static function el_asistente_hablo_de_tareas($texto): bool
    {
        $normalizado = self::normalizar((string) $texto);

        if ($normalizado === '') {

            return false;
        }

        return preg_match('/tarea|programad|cancel/u', $normalizado) === 1 || self::habla_de_tareas($texto);
    }

    /* ─────────────────────────────── reglas (sobre el texto ya normalizado) ─────────────────────────────── */

    /**
     * Pide que le escriba, avise, pregunte, cuente, mande o recuerde algo, y nombra un momento («a las 9»,
     * «mañana», «el viernes», «todos los días», «en 20 minutos»). «Recordame» alcanza solo: es un recordatorio.
     *
     * @param  string  $normalizado
     * @return bool
     */
    protected static function pide_avisar_en_un_momento(string $normalizado): bool
    {
        if (preg_match('/\b(?:recordame|recuerdame|acordate)\b/u', $normalizado) === 1) {

            return true;
        }

        return preg_match('/\b(?:' . self::VERBOS_DE_AVISO . ')\b/u', $normalizado) === 1
            && self::nombra_un_momento($normalizado);
    }

    /**
     * Pide programar o agendar algo («programame», «agendalo»), o habla de lo que quedó programado.
     *
     * @param  string  $normalizado
     * @return bool
     */
    protected static function pide_programar(string $normalizado): bool
    {
        return preg_match('/\b(?:programame|programar|programalo|programala|programalas|programalos|programaste|programes|programe|agendame|agendar|agendalo|agendala|agendaste)\b/u', $normalizado) === 1;
    }

    /**
     * Pide cancelar, sacar o borrar una tarea o un aviso: «la tarea», «el aviso», «lo de…», «la de…», «eso» o «todo
     * lo programado». «Cancelá la venta» o «cancelá el pedido 123» no entran: no son tareas.
     *
     * @param  string  $normalizado
     * @return bool
     */
    protected static function pide_cancelar(string $normalizado): bool
    {
        $verbo = '\b(?:' . self::VERBOS_DE_CANCELAR . ')\b';

        /* Relleno que se puede colar entre el verbo y lo que se cancela: «cancelá también la de logística». */
        $relleno = '(?: (?:tambien|ya|por favor|nomas|mejor|ahora|todo|todos|todas))*';

        /* «Cancelá lo de las ventas», «sacá la del proveedor», «borrá lo del viernes». */
        if (preg_match('/' . $verbo . $relleno . ' (?:la|el|lo|las|los) (?:de|del)\b/u', $normalizado) === 1) {

            return true;
        }

        /* «Cancelá eso». */
        if (preg_match('/' . $verbo . $relleno . ' eso\b/u', $normalizado) === 1) {

            return true;
        }

        /* «Sacá la tarea», «borrá el aviso», «cancelá todo lo que tengas programado». */
        if (preg_match('/' . $verbo . '(?: \w+){0,4} (?:tareas?|avisos?|recordatorios?|programad\w*)\b/u', $normalizado) === 1) {

            return true;
        }

        /* «Dejá de avisarme», «dejá de escribirme». */
        return preg_match('/\bdeja(?:r|lo|la)? de (?:avis|escrib|pregunt|mand|record|cont|inform|moles)\w*/u', $normalizado) === 1;
    }

    /**
     * «No me escribas / avises / preguntes / mandes / recuerdes (más)»: pide que deje de avisarle.
     *
     * @param  string  $normalizado
     * @return bool
     */
    protected static function pide_no_ser_molestado(string $normalizado): bool
    {
        return preg_match('/\bno me (?:escribas|avises|preguntes|mandes|recuerdes|cuentes|informes|molestes|hables|llames)\b/u', $normalizado) === 1;
    }

    /**
     * Pide posponer: «posponé», «reprogramá», «hoy no», o mover algo («pasala para el lunes», «movelo al viernes»).
     *
     * @param  string  $normalizado
     * @return bool
     */
    protected static function pide_posponer(string $normalizado): bool
    {
        if (preg_match('/\b(?:pospone|posponer|posponelo|posponela|posponeme|posterga|postergar|postergalo|postergala|reprograma|reprogramar|reprogramalo|reprogramala|reprogramame|reagenda|reagendar|reagendalo|reagendala)\b/u', $normalizado) === 1) {

            return true;
        }

        /* «Hoy no, mandame recién el viernes». */
        if (preg_match('/^hoy no\b/u', $normalizado) === 1) {

            return true;
        }

        /* Mover algo a otro momento: el verbo solo es ambiguo, con un momento no. */
        return preg_match('/\b(?:pasala|pasalo|pasalas|pasalos|movela|movelo|mudala|mudalo|cambiala|cambialo|cambiale)\b/u', $normalizado) === 1
            && self::nombra_un_momento($normalizado);
    }

    /**
     * Cambia el horario de una franja: «para mí a la mañana es a las 8:30».
     *
     * @param  string  $normalizado
     * @return bool
     */
    protected static function cambia_un_horario(string $normalizado): bool
    {
        return preg_match('/\b(?:manana|tarde|noche|primera hora|mediodia)\b.{0,15}\bes\b.{0,6}\ba las? \d/u', $normalizado) === 1;
    }

    /**
     * Consulta lo programado: «qué tenés programado», «qué quedó programado», «qué tareas tengo», «mis avisos».
     *
     * @param  string  $normalizado
     * @return bool
     */
    protected static function consulta_lo_programado(string $normalizado): bool
    {
        if (preg_match('/\bprogramad[oa]s?\b/u', $normalizado) === 1) {

            return true;
        }

        return preg_match('/\b(?:tareas?|avisos?|recordatorios?)\b/u', $normalizado) === 1
            && preg_match('/\b(?:que|cuales|cuantas|mis|tengo|tenes|mostrame|listame|lista|ver|quedo|quedaron)\b/u', $normalizado) === 1;
    }

    /**
     * true si el texto nombra un momento futuro: una hora («a las 9»), «mañana», un día de la semana, una franja
     * («a la tarde», «a primera hora»), una repetición («todos los días», «cada lunes») o un plazo («en 20 minutos»).
     * «Hoy» solo no cuenta: «¿cuánto vendí hoy?» no es una tarea.
     *
     * @param  string  $normalizado
     * @return bool
     */
    protected static function nombra_un_momento(string $normalizado): bool
    {
        $dias = self::DIAS;
        $cantidades = self::CANTIDADES;

        $momentos = [
            '\ba las? (?:' . $cantidades . ')\b',
            '\bmanana\b',
            '\b(?:el|este|proximo|los|cada) (?:' . $dias . ')s?\b',
            '\btodos los (?:dias|meses|' . $dias . ')s?\b',
            '\btodas las (?:semanas|tardes|mananas|noches)\b',
            '\b(?:a|por|en|esta) la (?:manana|tarde|noche)\b',
            '\besta (?:tarde|noche|manana)\b',
            '\bmas tarde\b',
            '\b(?:a )?(?:primera|ultima) hora\b',
            '\bal mediodia\b',
            '\bcada (?:(?:\d+|un|una) )?(?:dia|dias|semana|semanas|mes|meses|hora|horas|minuto|minutos|manana|tarde|noche)\b',
            '\ben (?:' . $cantidades . ') (?:minuto|minutos|hora|horas|dia|dias|semana|semanas|mes|meses|rato)\b',
            '\ben media hora\b',
            '\bdentro de\b',
            '\b(?:diariamente|semanalmente|mensualmente)\b',
            '\b(?:fin|principio|principios|comienzo) de (?:mes|semana)\b',
            '\bsemana (?:que viene|proxima)\b',
            '\bmes que viene\b',
            '\bproxim[oa] (?:semana|mes)\b',
        ];

        return preg_match('/' . implode('|', $momentos) . '/u', $normalizado) === 1;
    }

    /**
     * El texto en minúsculas, sin acentos ni signos y con los espacios simples: la forma en que se miran todas las
     * reglas («Mañana a las 11:15» pasa a «manana a las 11 15»).
     *
     * @param  string  $texto
     * @return string
     */
    protected static function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));

        $texto = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);

        /* Todo lo que no es letra o número pasa a espacio: signos, comillas, emojis. */
        $texto = preg_replace('/[^a-z0-9]+/u', ' ', $texto);

        return trim((string) $texto);
    }
}
