<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Services\AsistenteIa\HerramientasDeTareasDelAsistente;

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

    /**
     * Lo que se dice haber hecho, normalizado, por tipo. Cada tipo trae dos grupos de patrones:
     *   - 'fuerte': el verbo en primera persona del pasado («la programé», «la pasé al viernes», «lo cancelé»);
     *   - 'estado': el resultado dicho como estado («quedó programada», «ya está cancelada», «no te va a
     *     escribir más»). Si el turno consultó las tareas con éxito, el estado puede ser la descripción de lo
     *     que devolvió la consulta, así que en ese caso sólo cuentan los fuertes.
     */
    const PATRONES = [
        self::PROGRAMAR => [
            'fuerte' => '/\b(?:(?:te )?(?:la|lo|las|los) (?:programe|agende)|programe (?:la|el|una|un|esa|ese)|agende (?:la|el|una|un|esa|ese)|(?:la|lo) deje (?:programad|agendad)[oa]|ya programe)\b/u',
            'estado' => '/\b(?:qued(?:o|a) (?:programad|agendad)[oa]s?|ya (?:esta|queda) (?:programad|agendad)[oa]s?|te (?:escribo|aviso|pregunto|consulto|recuerdo) (?:(?:hoy|manana) )?a las \d{1,2})\b/u',
        ],
        self::REPROGRAMAR => [
            'fuerte' => '/\b(?:(?:la|lo) (?:pase|movi|corri|cambie|adelante|atrase|pospuse|postergue|reprograme|reagende|ajuste) (?:para|al|a la|a las|el|de|hasta)|reprograme (?:la|el|esa|ese)|ajuste (?:la|el|esa|ese) (?:tarea|aviso|recordatorio|hora|horario|dia)|(?:pase|movi|corri|cambie|pospuse|postergue|adelante|atrase) (?:la|el|esa|ese) (?:tarea|aviso|recordatorio))\b/u',
            'estado' => '/\b(?:qued(?:o|a) (?:para (?:el|este|la|las|hoy|manana|pasado)|reprogramad[oa]|pospuest[oa]|postergad[oa]|movid[oa]))\b/u',
        ],
        self::CANCELAR => [
            'fuerte' => '/\b(?:(?:la|lo) (?:cancele|elimine|borre|desactive|pare)|cancele (?:la|el|esa|ese|las|los)|(?:la|lo) di de baja|di de baja (?:la|el|esa|ese))\b/u',
            'estado' => '/\b(?:ya esta cancelad[oa]s?|qued(?:o|a) cancelad[oa]s?|no te (?:va a|voy a) (?:escribir|avisar|preguntar) mas|no vuelv[ea] a (?:correr|escribir|avisar)|no (?:te )?(?:escribo|aviso|pregunto) mas)\b/u',
        ],
    ];

    /** Lo que, en la oración, la refiere a una tarea o aviso del asistente (normalizado). */
    const REFERENCIA_A_TAREA = '/\b(?:tareas?|avisos?|recordatorios?|te (?:escribo|aviso|pregunto|consulto|recuerdo)|escribirte|avisarte|preguntarte|consultarte|no te (?:va a|voy a) (?:escribir|avisar|preguntar)|no vuelv[ea] a (?:correr|escribir|avisar)|no (?:te )?(?:escribo|aviso|pregunto) mas)\b/u';

    /** Lo que, en lo que escribió la persona, indica que venían hablando de tareas del asistente (normalizado). */
    const CONTEXTO_DE_TAREA = '/\b(?:tareas?|recordatorios?|programa(?:me|la|lo|r)|reprograma(?:me|la|lo|r)?|posponela|pospone(?:la|r)?|postergala|posterga(?:r)?)\b/u';

    /** Objetos que NO son tareas del asistente: «cancelé el pedido», «quedó programada la entrega». */
    const OTRO_OBJETO = '/\b(?:pedidos?|ventas?|facturas?|compras?|entregas?|envios?|pagos?|cobros?|cheques?|cargas?|cargad[oa]s?|turnos?|reuniones?|reunion|visitas?|cuotas?|presupuestos?|remitos?|ordenes|orden|ofertas?|promociones?|publicaciones?|campanas?|produccion|citas?|clases?|viajes?|vacaciones|evento|gastos?|articulos?|productos?)\b/u';

    /** Lo que, antes del verbo, desarma la afirmación: un condicional, una oferta, una negación. */
    const DESARMA = '/(?:^|\s)(?:si|cuando|queres que|quieres que|para que|puedo|podria|podes|no|nunca|ni|capaz|quiza|quizas|tal vez|todavia no|aun no)\s/u';

    /**
     * Los tipos de afirmación que el texto hace SIN que el turno los respalde.
     *
     * Un tipo queda respaldado si la herramienta correspondiente salió OK: programar y reprogramar se respaldan
     * entre sí (mover una tarea puede ser reprogramarla o cancelarla y crear otra); cancelar sólo con cancelar.
     * Con alguna carga o aviso armado en el turno (herramientas `proponer_*`), «te lo agendé» es esa carga y no
     * se mira. Las herramientas de tareas que NO se ofrecen en el turno las decide quien llama.
     *
     * @param  string  $texto  El texto de la respuesta, ya saneado.
     * @param  string  $pedido  Lo que escribió la persona en el turno (contexto de la conversación).
     * @param  array<int, string>  $herramientas_ok  Nombres de las herramientas que contestaron bien en el turno.
     * @param  array<int, string>  $herramientas_pedidas  Nombres de todas las herramientas que pidió el modelo.
     * @return array<int, string>  Subconjunto de [programar, reprogramar, cancelar].
     */
    public static function acciones_no_hechas($texto, $pedido, array $herramientas_ok, array $herramientas_pedidas)
    {
        foreach ($herramientas_pedidas as $herramienta) {

            if (strpos((string) $herramienta, 'proponer_') === 0) {
                return [];
            }
        }

        return self::sin_las_respaldadas(self::que_afirma($texto, $pedido, in_array(HerramientasDeTareasDelAsistente::CONSULTAR, $herramientas_ok, true)), $herramientas_ok);
    }

    /**
     * Los tipos de afirmación que hace el texto, sin mirar si el turno los respalda.
     *
     * @param  string  $texto
     * @param  string  $pedido
     * @param  bool  $consulto  true si el turno consultó las tareas con éxito (el estado puede ser lo que devolvió).
     * @return array<int, string>
     */
    public static function que_afirma($texto, $pedido, $consulto)
    {
        $tipos = [];

        foreach (VeracidadDelTurnoIaHelper::oraciones($texto) as $oracion) {

            foreach (self::tipos_de_la_oracion($oracion, $pedido, $consulto) as $tipo) {

                if (! in_array($tipo, $tipos, true)) {
                    $tipos[] = $tipo;
                }
            }
        }

        return $tipos;
    }

    /**
     * La nota del reintento: no hizo lo que dice. Genérica a propósito (no depende del texto).
     *
     * @return string
     */
    public static function nota_para_reintentar()
    {
        return '[El sistema revisó tu respuesta: decís que programaste, cambiaste o cancelaste una tarea (o que le vas a escribir a una hora), '
            . 'pero en este turno NO llamaste a programar_tarea_del_asistente, reprogramar_tarea_del_asistente ni cancelar_tarea_del_asistente con éxito: '
            . 'no se hizo, la tarea sigue como estaba. Si es lo que te pidió, llamá AHORA a la herramienta que corresponde y recién con su resultado decí cómo quedó; '
            . 'si no se puede o no es eso lo que te pidió, decí claramente que no lo hiciste. '
            . VeracidadDelTurnoIaHelper::FRASE_DE_LA_RESPUESTA_COMPLETA;
    }

    /**
     * El texto sin las oraciones que afirman algo que el turno no respalda, con un aviso honesto por cada cosa.
     *
     * @param  string  $texto
     * @param  string  $pedido
     * @param  array<int, string>  $herramientas_ok
     * @return string
     */
    public static function sin_lo_afirmado($texto, $pedido, array $herramientas_ok)
    {
        $consulto = in_array(HerramientasDeTareasDelAsistente::CONSULTAR, $herramientas_ok, true);
        $sin_hacer = [];

        $limpio = VeracidadDelTurnoIaHelper::reemplazar_oraciones($texto, function ($oracion) use ($pedido, $consulto, $herramientas_ok, &$sin_hacer) {

            $faltan = self::sin_las_respaldadas(self::tipos_de_la_oracion($oracion, $pedido, $consulto), $herramientas_ok);

            if (! count($faltan)) {
                return null;
            }

            $sin_hacer = array_merge($sin_hacer, $faltan);

            return '';
        }, '');

        $avisos = [];

        foreach (array_unique($sin_hacer) as $tipo) {
            $avisos[] = self::aviso_de($tipo);
        }

        $aviso = implode(' ', $avisos);

        if ($aviso === '') {
            return $limpio;
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
     * De los tipos afirmados, los que el turno NO respalda con una herramienta OK.
     *
     * @param  array<int, string>  $tipos
     * @param  array<int, string>  $herramientas_ok
     * @return array<int, string>
     */
    protected static function sin_las_respaldadas(array $tipos, array $herramientas_ok)
    {
        $programo = in_array(HerramientasDeTareasDelAsistente::PROGRAMAR, $herramientas_ok, true)
            || in_array(HerramientasDeTareasDelAsistente::REPROGRAMAR, $herramientas_ok, true);

        $cancelo = in_array(HerramientasDeTareasDelAsistente::CANCELAR, $herramientas_ok, true);

        $faltan = [];

        foreach ($tipos as $tipo) {

            $respaldado = $tipo === self::CANCELAR ? $cancelo : $programo;

            if (! $respaldado) {
                $faltan[] = $tipo;
            }
        }

        return $faltan;
    }

    /**
     * Los tipos de afirmación de UNA oración. Una pregunta nunca afirma. La oración se parte en cláusulas
     * (coma, punto y coma, dos puntos que no son de una hora) para que una negación o un «si querés» de una
     * cláusula no desarme la afirmación de otra.
     *
     * @param  string  $oracion
     * @param  string  $pedido
     * @param  bool  $consulto
     * @return array<int, string>
     */
    protected static function tipos_de_la_oracion($oracion, $pedido, $consulto)
    {
        if (VeracidadDelTurnoIaHelper::es_pregunta($oracion)) {
            return [];
        }

        /* Refiere a una tarea si la oración lo dice, o si la persona venía hablando de tareas. */
        $habla_de_tareas = preg_match(self::REFERENCIA_A_TAREA, VeracidadDelTurnoIaHelper::normalizar($oracion))
            || preg_match(self::CONTEXTO_DE_TAREA, VeracidadDelTurnoIaHelper::normalizar($pedido));

        if (! $habla_de_tareas) {
            return [];
        }

        $clausulas = preg_split('/[,;]|:(?!\d)/u', (string) $oracion);

        if (! is_array($clausulas)) {
            $clausulas = [(string) $oracion];
        }

        $tipos = [];

        foreach ($clausulas as $clausula) {

            $normalizada = VeracidadDelTurnoIaHelper::normalizar($clausula);

            foreach (self::PATRONES as $tipo => $grupos) {

                foreach ($grupos as $grupo => $regex) {

                    if ($consulto && $grupo === 'estado') {
                        continue;
                    }

                    if (self::coincide_y_afirma($regex, $normalizada)) {
                        $tipos[] = $tipo;
                        break;
                    }
                }
            }
        }

        return array_values(array_unique($tipos));
    }

    /**
     * true si la cláusula (normalizada) coincide con el patrón, no la desarma nada de lo que viene antes y el
     * objeto del verbo no es otra cosa que una tarea («cancelé el pedido»).
     *
     * @param  string  $regex
     * @param  string  $clausula  Normalizada.
     * @return bool
     */
    protected static function coincide_y_afirma($regex, $clausula)
    {
        if (! preg_match($regex, $clausula, $coincidencia, PREG_OFFSET_CAPTURE)) {
            return false;
        }

        $antes = substr($clausula, 0, $coincidencia[0][1]);
        $despues = substr($clausula, $coincidencia[0][1] + strlen($coincidencia[0][0]));

        if (preg_match(self::DESARMA, ' ' . $antes . ' ')) {
            return false;
        }

        /* Las palabras pegadas al verbo: las tres de antes y las cuatro de después. */
        $palabras_antes = array_slice(array_filter(explode(' ', $antes)), -3);
        $palabras_despues = array_slice(array_filter(explode(' ', $despues)), 0, 4);
        $alrededor = implode(' ', array_merge($palabras_antes, [$coincidencia[0][0]], $palabras_despues));

        if (preg_match(self::OTRO_OBJETO, $alrededor) && ! preg_match('/\b(?:tareas?|avisos?|recordatorios?)\b/u', $alrededor)) {
            return false;
        }

        return true;
    }
}
