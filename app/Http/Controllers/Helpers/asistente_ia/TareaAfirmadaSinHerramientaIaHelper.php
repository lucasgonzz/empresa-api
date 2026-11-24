<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiTareaDelAsistente;
use App\Services\AsistenteIa\HerramientasDeTareasDelAsistente;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * «Quedó programada», «la reprogramé», «ya está cancelada» SIN haber llamado a la herramienta de tareas
 * (arreglo de veracidad de la misión agente-tareas-programadas, 23/11/2026).
 *
 * 🔴 EL CASO REAL (demo3, deepseek-flash). Cuatro turnos de UNA sola llamada al modelo, sin herramienta,
 * contestaron «Lo cancelé: no te va a escribir más…», «Ya está cancelada: la de ventas diarias no vuelve a
 * correr», «Quedó programada para hoy a las 15:45: te pregunto si ya llamaste al proveedor» y «Quedó
 * programada para hoy a las 15:43: te aviso cómo viene la caja». En la base la tarea seguía activa y las
 * nuevas no existían: el dueño se quedaba esperando un aviso que nunca iba a llegar.
 *
 * La guarda corre en TODOS los turnos del asistente de TODOS los clientes, así que prefiere la precisión:
 * una oración dispara sólo si (1) afirma la acción en pasado o como resultado («la programé», «quedó
 * programada», «ya está cancelada») y (2) se refiere a una tarea o aviso del asistente (la palabra tarea /
 * aviso / recordatorio, un «te aviso a las 15:45», un «no te va a escribir más») o la persona venía hablando
 * de tareas. Nunca dispara por la sola palabra «programada» ni «cancelé»: «cancelé el pedido» y «quedó
 * programada la entrega» no son tareas del asistente.
 *
 * RONDA 2 (revisión independiente): la verdad no es «hubo herramienta en ESTE turno» sino «lo que dice coincide
 * con la BASE». Un «sí, ya está cancelada» o un «te escribo mañana a las 8» dichos en un turno posterior son
 * verdad si la tarea está (o ya no está) en `ai_tareas_del_asistente`. Cuando el texto afirma y el turno no tuvo
 * la herramienta, se contrasta con las tareas del dueño antes de disparar; así tampoco hace falta mirar si hubo
 * una consulta en el turno. Si la base no se puede leer, la guarda no dispara (nunca rompe el turno).
 *
 * RONDA 3 (segunda revisión): la guarda se achicó a las afirmaciones EXPLÍCITAS sobre tareas. Las promesas sueltas
 * («te recuerdo que a las 17 tenés la reunión», «te escribo mañana a las 8») ya no disparan nunca: pueden ser el
 * contexto de una oración que sí afirma («quedó programada…: te pregunto si…») pero no una afirmación. La base se
 * lee recién cuando el detector de texto encontró una candidata (un texto neutro hace cero consultas) y el pedido
 * de la persona se pide a demanda (puede llegar como función).
 *
 * El mecanismo es el de las guardas hermanas (AnotacionPrometidaIaHelper, PedidoDeAyudaASoporteIaHelper): un
 * reintento con una nota que dice la verdad y, si el modelo insiste, se cambian SOLO esas oraciones por un
 * aviso honesto. Acá vive la detección; el Service sólo decide cuándo mirar (herramientas ofrecidas, turno
 * atendido, resultados OK del turno).
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class TareaAfirmadaSinHerramientaIaHelper
{
    /** El nombre de la guarda en GUARDAS_DE_VERACIDAD del Service. */
    const GUARDA = 'tarea_afirmada';

    /** Los tipos de afirmación que se distinguen. */
    const PROGRAMAR = 'programar';
    const REPROGRAMAR = 'reprogramar';
    const CANCELAR = 'cancelar';

    /** Cuánto cuenta como «hace un rato» una cancelación para dar por cierto un «ya la cancelé». */
    const HORAS_DE_CANCELACION_RECIENTE = 24;

    /**
     * Lo que se dice haber hecho, normalizado, por tipo. Tres grupos de patrones:
     *   - 'fuerte': el verbo en primera persona del pasado («la programé», «la pasé al viernes», «lo cancelé»);
     *   - 'estado': el resultado dicho como estado («quedó programada», «ya está cancelada», «no te va a escribir más»);
     *   - 'debil' (sólo cancelar): «hoy no te escribo más», que sin una referencia a tarea o a lo pedido es sólo una
     *     despedida.
     * Las promesas sueltas («te escribo a las 9», «a las 20 te paso las ventas») NO disparan nunca: sirven de contexto
     * de una oración que sí afirma, no como afirmación.
     */
    const PATRONES = [
        self::PROGRAMAR => [
            'fuerte' => '/\b(?:(?:te )?(?:la|lo|las|los) (?:programe|agende)|programe (?:la|el|una|un|esa|ese)|agende (?:la|el|una|un|esa|ese)|(?:la|lo) deje (?:programad|agendad)[oa]|ya programe)\b/u',
            'estado' => '/\b(?:qued(?:o|a|aron) (?:programad|agendad)[oa]s?|ya (?:esta|queda) (?:programad|agendad)[oa]s?)\b/u',
        ],
        self::REPROGRAMAR => [
            'fuerte' => '/\b(?:(?:la|lo) (?:pase|movi|corri|cambie|adelante|atrase|pospuse|postergue|reprograme|reagende|ajuste) (?:para|al|a la|a las|el|de|hasta)|reprograme (?:la|el|esa|ese)|ajuste (?:la|el|esa|ese) (?:tarea|aviso|recordatorio|hora|horario|dia)|(?:pase|movi|corri|cambie|pospuse|postergue|adelante|atrase) (?:la|el|esa|ese) (?:tarea|aviso|recordatorio))\b/u',
            'estado' => '/\b(?:qued(?:o|a) (?:para (?:el|este|la|las|hoy|manana|pasado)|reprogramad[oa]|pospuest[oa]|postergad[oa]|movid[oa])|vuelv[ea] (?:el|este|manana|hoy|a las))\b/u',
        ],
        self::CANCELAR => [
            'fuerte' => '/\b(?:(?:la|lo) (?:cancele|elimine|borre|desactive|pare)|cancele (?:la|el|esa|ese|las|los)|(?:la|lo) di de baja|di de baja (?:la|el|esa|ese))\b/u',
            'estado' => '/\b(?:ya esta cancelad[oa]s?|qued(?:o|a|aron) cancelad[oa]s?|no te (?:va a|voy a) (?:escribir|avisar|preguntar) mas|no vuelv[ea] a (?:correr|escribir|avisar))\b/u',
            'debil'  => '/\b(?:no (?:te )?(?:escribo|aviso|pregunto) mas)\b/u',
        ],
    ];

    /** Lo que, en la oración, la refiere a una tarea o aviso del asistente (normalizado). */
    const REFERENCIA_A_TAREA = '/\b(?:tareas?|avisos?|recordatorios?|te (?:escribo|aviso|pregunto|consulto|recuerdo)|escribirte|avisarte|preguntarte|consultarte|no te (?:va a|voy a) (?:escribir|avisar|preguntar)|no vuelv[ea] a (?:correr|escribir|avisar))\b/u';

    /** Lo que, en la oración, hace de «no te escribo más» una cancelación y no una despedida (normalizado). */
    const REFERENCIA_DE_CANCELACION = '/\b(?:tareas?|avisos?|recordatorios?|por eso|sobre eso|con las|con los|todos los dias|todas las|cada (?:dia|lunes|semana))\b/u';

    /** Lo que, en lo que pidió la persona, es pedir cancelar o sacar algo o que no le escriban (normalizado). */
    const PEDIDO_DE_CANCELAR = '/\b(?:cancel\w*|saca\w*|elimin\w*|borr\w*|no me (?:escribas|avises|preguntes)|deja(?:r)? de|basta)\b/u';

    /** Lo que, en lo que escribió la persona, indica que venían hablando de tareas del asistente (normalizado). */
    const CONTEXTO_DE_TAREA = '/\b(?:tareas?|recordatorios?|avisos?|avisame|escribime|preguntame|no me (?:escribas|avises)|programa(?:me|la|lo|r|d[oa]s?)|reprograma(?:me|la|lo|r)?|posponela|pospone(?:la|r)?|postergala|posterga(?:r)?)\b/u';

    /** Lo que, en lo que escribió la persona, es hablar de una tarea y no de un registro (normalizado). */
    const PEDIDO_DE_TAREA = '/\b(?:tareas?|avisos?|recordatorios?|lo de|no me (?:escribas|avises)|avisame|escribime)\b/u';

    /**
     * Un registro CONCRETO como objeto del verbo o como sujeto: «cancelé el pedido 123», «la venta quedó cancelada».
     * Sólo el singular con artículo; «las ventas», «los cobros» o «lo de las ventas» son temas de las tareas.
     */
    const REGISTRO_CONCRETO = '(?:pedido|venta|factura|compra|entrega|envio|pago|cobro|cheque|turno|reunion|visita|cuota|presupuesto|remito|orden|oferta|promocion|publicacion|cita|clase|viaje|evento|campana|jornada|capacitacion|llamada|junta)';

    /** Lo que, en cualquier parte de la oración, la vuelve una oferta, un ejemplo o un condicional: nunca afirma. */
    const OFERTA = '/\b(?:si queres|si quieres|si te parece|si preferis|si te sirve|si te late|por ejemplo|cuando (?:la|lo) programe|(?:la|lo) programo|(?:la|lo) agendo|decime y|avisame y)\b/u';

    /** Lo que, antes del verbo, desarma la afirmación: un condicional, una negación. */
    const DESARMA = '/(?:^|\s)(?:si|cuando|queres que|quieres que|para que|puedo|podria|podes|no|nunca|ni|capaz|quiza|quizas|tal vez|todavia no|aun no)\s/u';

    /** Palabras que no distinguen una tarea de otra al compararla con un título. */
    const PALABRAS_VACIAS = ['para', 'todos', 'todas', 'toda', 'todo', 'tarea', 'tareas', 'aviso', 'avisos', 'recordatorio', 'recordatorios', 'cancele', 'cancelada', 'cancelado', 'canceladas', 'cancelados', 'cancelar', 'cancelame', 'escribir', 'escribas', 'escribe', 'escribo', 'vuelve', 'correr', 'listo', 'sobre', 'esta', 'este', 'esas', 'esos', 'ese', 'esa', 'eso', 'como', 'mismo', 'primera', 'hora', 'horas', 'dias', 'quedo', 'queda', 'quedaron', 'programada', 'programado', 'sacar', 'saca', 'pasa', 'pase', 'pasar', 'pedime', 'decime', 'gracias', 'nada', 'ahora', 'tengo', 'tenes', 'quiero', 'quiere', 'mas', 'con', 'del', 'los', 'las', 'una', 'uno', 'que', 'por', 'sin'];

    /**
     * Los tipos de afirmación que el texto hace y que la BASE no respalda.
     *
     * Un tipo queda respaldado si la herramienta correspondiente salió OK en el turno (programar y reprogramar se
     * respaldan entre sí; cancelar sólo con cancelar). Si no, se contrasta con las tareas del dueño (ver
     * es_mentira_de()). Con alguna carga o aviso armado en el turno (herramientas `proponer_*`) no se mira. La base
     * (y el pedido de la persona) se leen sólo si el texto trae una afirmación candidata.
     *
     * @param  string  $texto  El texto de la respuesta, ya saneado.
     * @param  string|\Closure  $pedido  Lo que escribió la persona, o una función que lo devuelve (se pide a demanda).
     * @param  array<int, string>  $herramientas_ok  Herramientas que contestaron bien en el turno.
     * @param  array<int, string>  $herramientas_pedidas  Todas las herramientas que pidió el modelo.
     * @param  int|null  $owner_id  El dueño: sin él no hay base que contrastar y manda sólo el texto.
     * @return array<int, string>  Subconjunto de [programar, reprogramar, cancelar].
     */
    public static function acciones_no_hechas($texto, $pedido, array $herramientas_ok, array $herramientas_pedidas, $owner_id = null)
    {
        foreach ($herramientas_pedidas as $herramienta) {

            if (strpos((string) $herramienta, 'proponer_') === 0) {
                return [];
            }
        }

        $dame_pedido = self::perezoso($pedido);

        $candidatas = self::candidatas($texto, $dame_pedido, $herramientas_ok);

        if (! count($candidatas)) {
            return [];
        }

        $tareas = self::tareas_del_dueno($owner_id);
        $tipos = [];

        foreach ($candidatas as $afirmacion) {

            if (self::es_mentira_de($afirmacion, $dame_pedido(), $owner_id, $tareas)) {
                $tipos[] = $afirmacion['tipo'];
            }
        }

        return array_values(array_unique($tipos));
    }

    /**
     * Los tipos de afirmación que hace el texto, sin mirar el turno ni la base (el detector solo). Una frase sobre un
     * registro concreto («cancelé el pedido 123») o sin referencia a una tarea no cuenta. `$consulto` se conserva por
     * compatibilidad y no cambia nada: la consulta no es un salvoconducto.
     *
     * @param  string  $texto
     * @param  string  $pedido
     * @param  bool  $consulto
     * @return array<int, string>
     */
    public static function que_afirma($texto, $pedido, $consulto = false)
    {
        $tipos = [];

        foreach (self::afirmaciones($texto, self::perezoso($pedido)) as $afirmacion) {

            if (! $afirmacion['concreto'] && $afirmacion['referida'] && ! in_array($afirmacion['tipo'], $tipos, true)) {
                $tipos[] = $afirmacion['tipo'];
            }
        }

        return $tipos;
    }

    /**
     * La nota del reintento: en este turno no llamaste a la herramienta y en la base no figura. Genérica a
     * propósito y sin empujar a duplicar: si ya estaba hecho de antes, que lo diga.
     *
     * @return string
     */
    public static function nota_para_reintentar()
    {
        return '[El sistema revisó tu respuesta: decís que programaste, cambiaste o cancelaste una tarea (o que le vas a escribir a una hora), '
            . 'pero en este turno no llamaste a la herramienta de tareas y en la base eso no figura. '
            . 'Si el dueño lo pidió, llamá a la herramienta que corresponde (programar, reprogramar o cancelar_tarea_del_asistente; '
            . 'si no sabés el id, consultá antes con consultar_tareas_del_asistente) y recién con su resultado decí cómo quedó; '
            . 'si no lo hiciste, decilo. '
            . VeracidadDelTurnoIaHelper::FRASE_DE_LA_RESPUESTA_COMPLETA;
    }

    /**
     * El texto sin las oraciones que afirman algo que ni el turno ni la base respaldan, con un aviso honesto por cada
     * cosa (sólo aparece cuando la base dice que no está hecho: es verdadero). Un texto sin candidatas vuelve igual y
     * no consulta nada.
     *
     * @param  string  $texto
     * @param  string|\Closure  $pedido
     * @param  array<int, string>  $herramientas_ok
     * @param  int|null  $owner_id
     * @return string
     */
    public static function sin_lo_afirmado($texto, $pedido, array $herramientas_ok, $owner_id = null)
    {
        $dame_pedido = self::perezoso($pedido);

        if (! count(self::candidatas($texto, $dame_pedido, $herramientas_ok))) {
            return (string) $texto;
        }

        $tareas = self::tareas_del_dueno($owner_id);
        $sin_hacer = [];

        $limpio = VeracidadDelTurnoIaHelper::reemplazar_oraciones($texto, function ($oracion) use ($dame_pedido, $herramientas_ok, $owner_id, $tareas, &$sin_hacer) {

            $miente = false;

            foreach (self::candidatas($oracion, $dame_pedido, $herramientas_ok) as $afirmacion) {

                if (self::es_mentira_de($afirmacion, $dame_pedido(), $owner_id, $tareas)) {
                    $sin_hacer[] = $afirmacion['tipo'];
                    $miente = true;
                }
            }

            return $miente ? '' : null;
        }, '');

        $avisos = [];

        foreach (array_unique($sin_hacer) as $tipo) {
            $avisos[] = self::aviso_de($tipo);
        }

        $aviso = implode(' ', $avisos);

        if ($aviso === '') {
            return (string) $texto;
        }

        return $limpio === '' ? $aviso : $limpio . "\n\n" . $aviso;
    }

    /**
     * El aviso honesto de un tipo: lo que NO pasó y cómo pedirlo de nuevo.
     *
     * @param  string  $tipo
     * @return string
     */
    protected static function aviso_de($tipo)
    {
        if ($tipo === self::CANCELAR) {
            return 'Todavía no cancelé esa tarea: decímelo de nuevo y la cancelo.';
        }

        if ($tipo === self::REPROGRAMAR) {
            return 'Todavía no cambié esa tarea de horario: decímelo de nuevo y lo hago.';
        }

        return 'Todavía no programé esa tarea: decímelo de nuevo y la programo.';
    }

    /**
     * Una función que devuelve el pedido de la persona y lo calcula una sola vez, y sólo si alguien lo pide.
     *
     * @param  string|\Closure  $pedido
     * @return \Closure
     */
    protected static function perezoso($pedido)
    {
        if ($pedido instanceof \Closure) {

            $valor = '';
            $resuelto = false;

            return function () use ($pedido, &$valor, &$resuelto) {

                if (! $resuelto) {
                    $valor = (string) call_user_func($pedido);
                    $resuelto = true;
                }

                return $valor;
            };
        }

        $texto = (string) $pedido;

        return function () use ($texto) {
            return $texto;
        };
    }

    /**
     * Las afirmaciones candidatas: las del detector de texto que el turno no respalda con una herramienta OK y que no
     * son un registro concreto sobre el que la persona no hablaba de tareas. Todavía sin mirar la base.
     *
     * @param  string  $texto
     * @param  \Closure  $dame_pedido
     * @param  array<int, string>  $herramientas_ok
     * @return array<int, array<string, mixed>>
     */
    protected static function candidatas($texto, \Closure $dame_pedido, array $herramientas_ok)
    {
        $programo = in_array(HerramientasDeTareasDelAsistente::PROGRAMAR, $herramientas_ok, true)
            || in_array(HerramientasDeTareasDelAsistente::REPROGRAMAR, $herramientas_ok, true);

        $cancelo = in_array(HerramientasDeTareasDelAsistente::CANCELAR, $herramientas_ok, true);

        $candidatas = [];

        foreach (self::afirmaciones($texto, $dame_pedido) as $afirmacion) {

            if ($afirmacion['tipo'] === self::CANCELAR ? $cancelo : $programo) {
                continue;
            }

            if ($afirmacion['concreto'] && ! preg_match(self::PEDIDO_DE_TAREA, VeracidadDelTurnoIaHelper::normalizar($dame_pedido()))) {
                continue;
            }

            $candidatas[] = $afirmacion;
        }

        return $candidatas;
    }

    /**
     * true si la afirmación candidata no la respalda la base.
     *
     * @param  array  $afirmacion
     * @param  string  $pedido
     * @param  int|null  $owner_id
     * @param  array<int, array<string, mixed>>|null  $tareas  Las del dueño; null si la base no se pudo leer.
     * @return bool
     */
    protected static function es_mentira_de(array $afirmacion, $pedido, $owner_id, $tareas)
    {
        /* La base no se pudo leer: nunca se rompe el turno ni se cambia una respuesta por una duda. */
        if (is_null($tareas)) {
            return false;
        }

        $palabras_del_pedido = self::palabras($pedido);

        /* Sin referencia propia a una tarea: sólo vale si una tarea VIVA comparte una palabra con la oración. */
        if (! $afirmacion['referida'] && ! count(self::con_palabras_en_comun(self::vivas($tareas), $afirmacion['palabras']))) {
            return false;
        }

        /*
         * Un registro concreto («cancelé el pedido 123») no es una tarea... salvo que la persona venía hablando de
         * una tarea que comparte palabra con lo que pidió: ahí gana la tarea.
         */
        if ($afirmacion['concreto'] && ! count(self::con_palabras_en_comun(self::vivas($tareas), $palabras_del_pedido))) {
            return false;
        }

        /* Sin dueño no hay base: manda el texto. */
        if (is_null($owner_id)) {
            return true;
        }

        $palabras = array_unique(array_merge($afirmacion['palabras'], $palabras_del_pedido));

        return $afirmacion['tipo'] === self::CANCELAR
            ? self::cancelacion_sin_respaldo($tareas, $palabras)
            : self::programacion_sin_respaldo($afirmacion, $tareas, $palabras);
    }

    /**
     * true si «ya la cancelé» NO está respaldado: queda algo vivo que la mentira deja vivo y nada cancelado que
     * coincida. Sin ninguna tarea activa ni pausada no hay nada que dejar vivo.
     *
     * @param  array<int, array<string, mixed>>  $tareas
     * @param  array<int, string>  $palabras  Las de la oración y las del pedido.
     * @return bool
     */
    protected static function cancelacion_sin_respaldo(array $tareas, array $palabras)
    {
        $vivas = self::vivas($tareas);

        if (! count($vivas)) {
            return false;
        }

        $canceladas = [];

        foreach ($tareas as $tarea) {

            if ($tarea['estado'] === AiTareaDelAsistente::ESTADO_CANCELADA) {
                $canceladas[] = $tarea;
            }
        }

        if (count(self::con_palabras_en_comun($canceladas, $palabras))) {
            return false;
        }

        if (count(self::con_palabras_en_comun($vivas, $palabras))) {
            return true;
        }

        /* Ni la oración ni el pedido nombran ninguna: vale si se canceló algo hace poco. */
        $limite = Carbon::now()->subHours(self::HORAS_DE_CANCELACION_RECIENTE);

        foreach ($canceladas as $tarea) {

            if (! is_null($tarea['actualizada']) && $tarea['actualizada']->gte($limite)) {
                return false;
            }
        }

        return true;
    }

    /**
     * true si «quedó programada / la pasé al viernes a las 17» NO está respaldado. Con hora, tiene que haber una
     * tarea activa a esa hora y, si la oración nombra un día, que corra ese día (una diaria a esa hora respalda
     * cualquier día). Sin hora, alguna activa (y, para reprogramar con un día nombrado, una que corra ese día).
     *
     * @param  array  $afirmacion
     * @param  array<int, array<string, mixed>>  $tareas
     * @param  array<int, string>  $palabras
     * @return bool
     */
    protected static function programacion_sin_respaldo(array $afirmacion, array $tareas, array $palabras)
    {
        $activas = [];

        foreach ($tareas as $tarea) {

            if ($tarea['estado'] === AiTareaDelAsistente::ESTADO_ACTIVA) {
                $activas[] = $tarea;
            }
        }

        if (count($afirmacion['horas'])) {

            foreach ($activas as $tarea) {

                if (! in_array($tarea['hora'], $afirmacion['horas'], true)) {
                    continue;
                }

                if (is_null($afirmacion['dia']) || $tarea['recurrencia'] === AiTareaDelAsistente::RECURRENCIA_DIARIA
                    || (! is_null($tarea['proxima']) && self::corre_ese_dia($tarea['proxima'], $afirmacion['dia']))) {
                    return false;
                }
            }

            return true;
        }

        if ($afirmacion['tipo'] === self::REPROGRAMAR && ! is_null($afirmacion['dia']) && count($activas)) {

            $candidatas = self::con_palabras_en_comun($activas, $palabras);

            foreach (count($candidatas) ? $candidatas : $activas as $tarea) {

                if (! is_null($tarea['proxima']) && self::corre_ese_dia($tarea['proxima'], $afirmacion['dia'])) {
                    return false;
                }
            }

            return true;
        }

        return ! count($activas);
    }

    /**
     * true si el momento cae en el día nombrado: un día de la semana (1 = lunes … 7 = domingo) o «hoy» / «manana».
     *
     * @param  \Carbon\Carbon  $momento
     * @param  string|int  $dia  'hoy', 'manana' o el número ISO del día de la semana.
     * @return bool
     */
    protected static function corre_ese_dia(Carbon $momento, $dia)
    {
        if ($dia === 'hoy') {
            return $momento->isSameDay(Carbon::now());
        }

        if ($dia === 'manana') {
            return $momento->isSameDay(Carbon::now()->addDay());
        }

        return (int) $momento->dayOfWeekIso === (int) $dia;
    }

    /**
     * Las tareas del dueño que importan para contrastar (activas, pausadas y canceladas), o null si la base no se
     * pudo leer. Sin dueño, [].
     *
     * @param  int|null  $owner_id
     * @return array<int, array<string, mixed>>|null
     */
    protected static function tareas_del_dueno($owner_id)
    {
        if (is_null($owner_id)) {
            return [];
        }

        try {

            $tareas = [];

            $filas = AiTareaDelAsistente::query()
                ->where('owner_id', (int) $owner_id)
                ->whereIn('estado', [AiTareaDelAsistente::ESTADO_ACTIVA, AiTareaDelAsistente::ESTADO_PAUSADA, AiTareaDelAsistente::ESTADO_CANCELADA])
                ->get();

            foreach ($filas as $fila) {

                $tareas[] = [
                    'estado'      => (string) $fila->estado,
                    'recurrencia' => (string) $fila->recurrencia,
                    'palabras'    => self::palabras($fila->titulo),
                    'hora'        => substr((string) $fila->hora, 0, 5),
                    'proxima'     => $fila->proxima_ejecucion_at,
                    'actualizada' => $fila->updated_at,
                ];
            }

            return $tareas;
        } catch (\Throwable $e) {
            Log::warning('TareaAfirmadaSinHerramientaIaHelper: no se pudieron leer las tareas del dueño; la guarda no dispara.', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * De las tareas, las activas o pausadas.
     *
     * @param  array<int, array<string, mixed>>  $tareas
     * @return array<int, array<string, mixed>>
     */
    protected static function vivas(array $tareas)
    {
        $vivas = [];

        foreach ($tareas as $tarea) {

            if ($tarea['estado'] !== AiTareaDelAsistente::ESTADO_CANCELADA) {
                $vivas[] = $tarea;
            }
        }

        return $vivas;
    }

    /**
     * De las tareas, las que comparten alguna palabra significativa con las dadas.
     *
     * @param  array<int, array<string, mixed>>  $tareas
     * @param  array<int, string>  $palabras
     * @return array<int, array<string, mixed>>
     */
    protected static function con_palabras_en_comun(array $tareas, array $palabras)
    {
        $comunes = [];

        foreach ($tareas as $tarea) {

            if (count(array_intersect($tarea['palabras'], $palabras))) {
                $comunes[] = $tarea;
            }
        }

        return $comunes;
    }

    /**
     * Las palabras significativas de un texto: de 4 letras o más, sin tildes, sin artículos ni preposiciones ni
     * las del propio verbo, y en singular (sin la «s» final) para que «ventas» y «venta» coincidan.
     *
     * @param  string  $texto
     * @return array<int, string>
     */
    protected static function palabras($texto)
    {
        $palabras = [];

        foreach (explode(' ', VeracidadDelTurnoIaHelper::normalizar($texto)) as $palabra) {

            if (strlen($palabra) < 4 || in_array($palabra, self::PALABRAS_VACIAS, true) || ctype_digit($palabra)) {
                continue;
            }

            $palabras[] = rtrim($palabra, 's');
        }

        return array_values(array_unique($palabras));
    }

    /**
     * Las horas («HH:MM») que la oración nombra con «a las 9», «para las 20:00», «a las 15:45».
     *
     * @param  string  $oracion
     * @return array<int, string>
     */
    protected static function horas_de($oracion)
    {
        $horas = [];

        if (preg_match_all('/\b(?:a|para|desde|hasta) las? (\d{1,2})(?:[:.h](\d{2}))?/iu', (string) $oracion, $coincidencias, PREG_SET_ORDER)) {

            foreach ($coincidencias as $c) {

                if ((int) $c[1] <= 23) {
                    $horas[] = sprintf('%02d:%02d', (int) $c[1], isset($c[2]) ? (int) $c[2] : 0);
                }
            }
        }

        return array_values(array_unique($horas));
    }

    /**
     * El día que la oración nombra (normalizada): 'hoy', 'manana' o el número ISO de un día de la semana; null si
     * no nombra ninguno.
     *
     * @param  string  $normalizada
     * @return string|int|null
     */
    protected static function dia_de($normalizada)
    {
        $dias = ['lunes' => 1, 'martes' => 2, 'miercoles' => 3, 'jueves' => 4, 'viernes' => 5, 'sabado' => 6, 'domingo' => 7];

        foreach ($dias as $nombre => $numero) {

            if (preg_match('/\b' . $nombre . '\b/', $normalizada)) {
                return $numero;
            }
        }

        if (preg_match('/\bmanana\b/', $normalizada)) {
            return 'manana';
        }

        return preg_match('/\bhoy\b/', $normalizada) ? 'hoy' : null;
    }

    /**
     * Las afirmaciones del texto: una por tipo y por oración que la hace (sin mirar el turno ni la base). Una
     * pregunta, una oferta, un ejemplo o un condicional nunca afirman. Las oraciones se parten en cláusulas
     * (coma, punto y coma, dos puntos que no son de una hora) para que una negación de una cláusula no desarme otra.
     *
     * `referida` dice si la oración (o lo que pidió la persona) habla de una tarea. Una afirmación sin referencia sólo
     * se conserva si es de la forma «fuerte» («La de ventas diarias la cancelé»): después se exige que una tarea viva
     * comparta una palabra con ella. El pedido de la persona sólo se pide si hace falta.
     *
     * @param  string  $texto
     * @param  \Closure  $dame_pedido
     * @return array<int, array{tipo: string, concreto: bool, referida: bool, horas: array<int,string>, dia: string|int|null, palabras: array<int,string>}>
     */
    protected static function afirmaciones($texto, \Closure $dame_pedido)
    {
        $afirmaciones = [];

        foreach (VeracidadDelTurnoIaHelper::oraciones($texto) as $oracion) {

            $normalizada = VeracidadDelTurnoIaHelper::normalizar($oracion);

            if (VeracidadDelTurnoIaHelper::es_pregunta($oracion) || preg_match(self::OFERTA, $normalizada)) {
                continue;
            }

            $referencia_propia = (bool) preg_match(self::REFERENCIA_A_TAREA, $normalizada);

            $clausulas = preg_split('/[,;]|:(?!\d)/u', (string) $oracion);

            if (! is_array($clausulas)) {
                $clausulas = [(string) $oracion];
            }

            $tipos_vistos = [];

            foreach ($clausulas as $clausula) {

                $clausula_normalizada = VeracidadDelTurnoIaHelper::normalizar($clausula);

                foreach (self::PATRONES as $tipo => $grupos) {

                    foreach ($grupos as $grupo => $regex) {

                        if (isset($tipos_vistos[$tipo]) || ! preg_match($regex, $clausula_normalizada)) {
                            continue;
                        }

                        $veredicto = self::coincide_y_afirma($regex, $clausula_normalizada, $normalizada);

                        if ($veredicto === 'no') {
                            continue;
                        }

                        /* «Hoy no te escribo más» es una despedida salvo que se hable de una tarea o se haya pedido cancelar. */
                        if ($grupo === 'debil'
                            && ! preg_match(self::REFERENCIA_DE_CANCELACION, $normalizada)
                            && ! preg_match(self::PEDIDO_DE_CANCELAR, VeracidadDelTurnoIaHelper::normalizar($dame_pedido()))) {
                            continue;
                        }

                        $referida = $referencia_propia || (bool) preg_match(self::CONTEXTO_DE_TAREA, VeracidadDelTurnoIaHelper::normalizar($dame_pedido()));

                        if (! $referida && $grupo !== 'fuerte') {
                            continue;
                        }

                        $tipos_vistos[$tipo] = true;

                        $afirmaciones[] = [
                            'tipo'     => $tipo,
                            'concreto' => $veredicto === 'concreto',
                            'referida' => $referida,
                            'horas'    => self::horas_de($oracion),
                            'dia'      => self::dia_de($normalizada),
                            'palabras' => self::palabras($oracion),
                        ];
                    }
                }
            }
        }

        return $afirmaciones;
    }

    /**
     * Si la cláusula (normalizada) afirma con el patrón: 'no' si la desarma lo que viene antes; 'concreto' si el verbo
     * recae en un registro («cancelé el pedido 123», «la venta quedó cancelada») y la oración no habla de tareas; 'si'
     * en el resto. El patrón ya se sabe que coincide.
     *
     * @param  string  $regex
     * @param  string  $clausula  Normalizada.
     * @param  string  $oracion  La oración entera, normalizada.
     * @return string  'si' | 'concreto' | 'no'
     */
    protected static function coincide_y_afirma($regex, $clausula, $oracion)
    {
        if (! preg_match($regex, $clausula, $coincidencia, PREG_OFFSET_CAPTURE)) {
            return 'no';
        }

        $antes = substr($clausula, 0, $coincidencia[0][1]);
        $despues = substr($clausula, $coincidencia[0][1] + strlen($coincidencia[0][0]));

        if (preg_match(self::DESARMA, ' ' . $antes . ' ')) {
            return 'no';
        }

        if (preg_match('/\b(?:tareas?|avisos?|recordatorios?)\b/u', $oracion)) {
            return 'si';
        }

        /* El registro concreto: lo que sigue al verbo («el pedido 123») o el sujeto que lo precede («la venta»). */
        $palabras_antes = implode(' ', array_slice(array_values(array_filter(explode(' ', $antes))), -4));

        if (preg_match('/^(?:(?:el|la|esa|ese|este|esta|mi|tu) )?' . self::REGISTRO_CONCRETO . '\b/u', ltrim($despues))
            || preg_match('/\b(?:el|la|esa|ese|mi|tu) ' . self::REGISTRO_CONCRETO . '\b/u', $palabras_antes)) {
            return 'concreto';
        }

        return 'si';
    }
}
