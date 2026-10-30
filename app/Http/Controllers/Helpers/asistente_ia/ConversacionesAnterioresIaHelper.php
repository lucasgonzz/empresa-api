<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * LA MEMORIA DE LAS CONVERSACIONES ANTERIORES DEL AGENTE (misión agente-memoria-y-sin-techo,
 * 30/10/2026): buscar entre las charlas que la persona ya tuvo con el agente, leer una, y armar la
 * lista corta de las últimas que viaja en el system de cada turno.
 *
 * 🔴 EL CASO REAL (Ferre Total, WhatsApp, Pablo). El 29/10, en la conversación 23, el agente registró
 * el Pago N° 1706 a Pinturas Sudamericanas con 5 e-cheq. Esa misma noche (conv 27: «Hoy cuando
 * cargaste el pago de Pinturería Sudamericana, ¿le cargaste la fecha de pago a los cheques?») y al
 * otro día (conv 28: «¿Podemos seguir con lo último de ayer? ¿Lo del pago de Pinturería
 * Sudamericana?») el agente contestó «No tengo el detalle de lo que hablamos ayer: esta conversación
 * no trae el historial anterior». Por qué: en WhatsApp la charla se parte en varias conversaciones
 * (HiloPorTemaIaHelper por tema, y el corte de AsistenteCanalHelper::HORAS_CORTE sin hablar), y
 * AsistenteIaService::build_messages_payload() arma SOLO la conversación actual. Ninguna herramienta
 * permitía mirar otra. Para el dueño es una sola charla con su asistente: que el agente «se olvide»
 * de lo que hizo ayer es lo peor que le puede pasar a la confianza.
 *
 * Lo que hay acá (las herramientas las declara y despacha HerramientasDeConversaciones):
 *   - buscar(): las conversaciones de la persona por palabras clave y/o fechas, con un resumen corto
 *     de cada una (cargas confirmadas, extractos donde aparece lo buscado).
 *   - leer(): los mensajes de una conversación —también la actual, para releer su principio cuando
 *     ya no entra en el historial—, de los más nuevos hacia atrás con un techo de caracteres y
 *     paginación hacia atrás.
 *   - bloque_para_prompt(): «Tus últimas conversaciones con esta persona…», las últimas 8 de los
 *     últimos 30 días, para que el modelo sepa QUÉ abrir sin tener que buscar.
 *
 * 🔴 TENENCIA ESTRICTA (la regla de AiConversation): solo conversaciones con el MISMO `user_id` (el
 * dueño de la cuenta) Y el MISMO `auth_user_id` (la persona) que la conversación actual. Las
 * respuestas del agente traen saldos de clientes y lo que el dueño charló: un empleado nunca lee las
 * del dueño ni al revés, ni un negocio las de otro. Una conversación ajena o que no existe contesta
 * lo mismo («No encontré esa conversación entre las tuyas.»), sin decir cuál de las dos es. Se
 * excluyen las de origen `mcp`: son el registro técnico de las tools que pidió un cliente MCP, no una
 * charla con la persona.
 *
 * Todo sale de ai_conversations, ai_messages, ai_message_actions y ai_message_imagenes: sin
 * migraciones.
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class ConversacionesAnterioresIaHelper
{
    /**
     * Cuántas conversaciones devuelve la búsqueda si el modelo no pide otra cosa. Diez alcanzan para
     * «lo de ayer» o «lo del pago de tal proveedor» (casi siempre está entre las primeras dos o tres) y
     * mantienen el tool_result chico: cada una viaja con hasta 3 cargas y 2 extractos.
     */
    const LIMITE_POR_DEFECTO = 10;

    /**
     * El máximo que puede pedir el modelo. Veinte resúmenes rondan los 8-10 mil caracteres: más que eso
     * es leer media historia del negocio de una, y para eso está acotar por fechas o por palabras.
     */
    const LIMITE_MAXIMO = 20;

    /**
     * Cuántas palabras del `texto` se usan como mucho. Cada palabra es un EXISTS más en la consulta
     * (contra los mensajes y las tarjetas): seis cubren cualquier cosa que tenga sentido buscar
     * («pago Pinturería Sudamericana cheques 1706») y ponen un techo a lo que le cuesta a la base.
     */
    const MAX_PALABRAS = 6;

    /**
     * Largo mínimo de una palabra significativa. Con menos de 3 letras («de», «la», «al») matchea
     * cualquier mensaje y la búsqueda deja de filtrar.
     */
    const LARGO_MINIMO_DE_PALABRA = 3;

    /**
     * Palabras de 3 letras o más que no sirven para buscar: aparecen en casi cualquier mensaje y, como
     * TODAS las palabras tienen que aparecer, una sola de estas no filtra pero tampoco ayuda. Se
     * descartan. Igual, si el modelo manda la frase entera («lo que hablamos del pago») queda
     * «hablamos», que casi nunca está escrita: por eso la descripción de la herramienta le pide una a
     * tres palabras distintivas (el proveedor, el número del pago) y no la frase.
     */
    const PALABRAS_VACIAS = [
        'que', 'los', 'las', 'del', 'con', 'por', 'para', 'una', 'uno', 'unos', 'unas', 'como', 'pero',
        'este', 'esta', 'esto', 'ese', 'esa', 'eso', 'mas', 'más', 'muy', 'sus', 'les', 'nos', 'hay',
        'fue', 'son', 'era', 'sin', 'sobre', 'entre', 'cuando', 'donde', 'todo', 'toda', 'todos', 'todas',
        'ayer', 'hoy', 'qué', 'cómo',
    ];

    /** Cuántas cargas confirmadas (su resultado) se muestran por conversación en la búsqueda. */
    const CARGAS_POR_CONVERSACION = 3;

    /** Cuántos extractos con lo buscado se muestran por conversación en la búsqueda. */
    const COINCIDENCIAS_POR_CONVERSACION = 2;

    /**
     * Largo máximo de un extracto de la búsqueda (con los «…» incluidos). Doscientos caracteres
     * alcanzan para reconocer de qué se habló («…el Pago N° 1706 a Pinturas Sudamericanas con 5
     * e-cheq…») sin traer el mensaje entero, que para eso está leer.
     */
    const LARGO_DE_COINCIDENCIA = 200;

    /**
     * Cuántos caracteres de contexto se dejan ANTES de la palabra encontrada en el extracto, para que
     * no arranque justo en ella y se entienda de qué venía la frase.
     */
    const CONTEXTO_ANTES_DE_LA_COINCIDENCIA = 60;

    /** Cuántos mensajes con lo buscado se miran por conversación para elegir los extractos. */
    const MENSAJES_PARA_COINCIDENCIAS = 50;

    /**
     * Techo de caracteres de una lectura: la suma del texto, las fotos y las cargas de los mensajes
     * que vuelven. Se eligió por debajo del techo del historial propio de la conversación
     * (AsistenteIaService::MAX_CARACTERES_HISTORIAL, 24.000): leer una charla vieja no puede costar
     * más que la charla actual entera, y el modelo puede abrir varias en el mismo turno. 16.000
     * caracteres son ≈ 4-5 mil tokens: entran 30-60 mensajes normales de WhatsApp, que es una charla
     * completa de un día. Lo que no entra se pide con antes_de_mensaje.
     */
    const TECHO_CARACTERES_DE_LECTURA = 16000;

    /**
     * Techo de caracteres del texto de UN mensaje en la lectura. Un mensaje con el texto de un PDF o
     * un listado largo del agente se comería solo el techo de la lectura y no dejaría ver nada más:
     * 2.000 caracteres alcanzan para saber qué se dijo, y el detalle de los datos se vuelve a
     * consultar con las herramientas.
     */
    const TECHO_CARACTERES_POR_MENSAJE = 2000;

    /**
     * Cuántos mensajes se traen de la base por lectura como mucho, antes de aplicar el techo de
     * caracteres. Es la red para una conversación de cientos de mensajes cortos («sí», «dale»): sin
     * él se cargarían todos con sus tarjetas y fotos para quedarse con unos pocos.
     */
    const MAX_MENSAJES_POR_LECTURA = 60;

    /** Cuántas conversaciones van en la lista del system (bloque_para_prompt). */
    const CONVERSACIONES_EN_EL_PROMPT = 8;

    /**
     * Hasta cuántos días hacia atrás se miran para la lista del system. Más viejo que un mes ya no es
     * «lo de ayer» ni «lo último que hablamos»: si hace falta, el modelo lo busca con la herramienta.
     */
    const DIAS_EN_EL_PROMPT = 30;

    /** Largo del título de reemplazo (el primer mensaje de la persona) cuando la conversación no tiene título. */
    const LARGO_DEL_TITULO_DE_REEMPLAZO = 60;

    /** La respuesta para una conversación ajena o inexistente: la misma para las dos, a propósito. */
    const NO_ENCONTRADA = 'No encontré esa conversación entre las tuyas.';

    /**
     * La actividad de una conversación: su último mensaje, o cuándo se creó si nunca se marcó (las de
     * antes de que existiera la columna, o las que creó un proceso que no la toca).
     */
    const ACTIVIDAD = 'COALESCE(ai_conversations.last_message_at, ai_conversations.created_at)';

    /**
     * buscar_conversaciones_anteriores: las conversaciones de la persona (sin la actual) que tienen
     * TODAS las palabras del texto —en el título, en algún mensaje o en el resultado de alguna carga
     * confirmada— y/o algún mensaje en el rango de fechas, de la actividad más reciente a la más vieja.
     *
     * La comparación es un LIKE: la colación de las tablas (utf8mb4_unicode_ci) ya ignora mayúsculas y
     * tildes, así que «pintureria» encuentra «Pinturería» en el texto de un mensaje.
     *
     * @param  \App\Models\AiConversation  $actual  La conversación del turno (define la tenencia).
     * @param  array  $input  {texto?: string, desde?: 'AAAA-MM-DD', hasta?: 'AAAA-MM-DD', limite?: int}
     * @return array
     */
    public static function buscar(AiConversation $actual, array $input)
    {
        $texto = isset($input['texto']) && is_scalar($input['texto']) ? trim((string) $input['texto']) : '';
        $palabras = self::palabras($texto);

        $desde = self::fecha_del_input($input, 'desde', false);
        $hasta = self::fecha_del_input($input, 'hasta', true);

        if ($desde === false || $hasta === false) {

            return ['error' => 'Las fechas van como AAAA-MM-DD (por ejemplo "2026-10-29").'];
        }

        $limite = isset($input['limite']) && is_numeric($input['limite']) ? (int) $input['limite'] : self::LIMITE_POR_DEFECTO;
        $limite = max(1, min(self::LIMITE_MAXIMO, $limite));

        $query = self::con_mensajes(self::de_la_misma_persona($actual))
            ->where('ai_conversations.id', '!=', (int) $actual->id);

        if (!is_null($desde) || !is_null($hasta)) {
            $query->whereExists(function ($en_el_rango) use ($desde, $hasta) {
                $en_el_rango->selectRaw('1')
                    ->from('ai_messages')
                    ->whereColumn('ai_messages.ai_conversation_id', 'ai_conversations.id');

                if (!is_null($desde)) {
                    $en_el_rango->where('ai_messages.created_at', '>=', $desde->toDateTimeString());
                }

                if (!is_null($hasta)) {
                    $en_el_rango->where('ai_messages.created_at', '<=', $hasta->toDateTimeString());
                }
            });
        }

        foreach ($palabras as $palabra) {
            self::con_la_palabra($query, $palabra);
        }

        $encontradas = (clone $query)->count();

        $conversaciones = $query
            ->select('ai_conversations.*')
            ->orderByRaw(self::ACTIVIDAD . ' DESC')
            ->orderBy('ai_conversations.id', 'DESC')
            ->limit($limite)
            ->get();

        $resumenes = self::resumenes($conversaciones);

        $resultado = [];

        foreach ($conversaciones as $conversacion) {
            $resumen = $resumenes[(int) $conversacion->id];

            $resultado[] = [
                'conversacion_id' => (int) $conversacion->id,
                'titulo'          => self::titulo_de($conversacion),
                'empezo'          => self::fecha($conversacion->created_at),
                'ultimo_mensaje'  => self::fecha(self::actividad_de($conversacion)),
                'canal'           => $resumen['canal'],
                'mensajes'        => $resumen['mensajes'],
                'cargas'          => self::cargas_confirmadas($conversacion),
                'coincidencias'   => count($palabras) ? self::coincidencias($conversacion, $palabras) : [],
            ];
        }

        $respuesta = [
            'conversaciones' => $resultado,
            'encontradas'    => $encontradas,
        ];

        if ($texto !== '' && !count($palabras)) {
            $respuesta['nota'] = 'No usé el texto: no tenía palabras de ' . self::LARGO_MINIMO_DE_PALABRA . ' letras o más que sirvan para buscar. Estas son las más recientes.';
        } elseif (!count($resultado)) {
            $respuesta['nota'] = 'No encontré conversaciones tuyas con eso (esta no cuenta). Probá con menos palabras, con otra forma de decirlo (el nombre del proveedor, el número del pago) o solo por fechas.';
        } else {
            $respuesta['nota'] = 'Para ver lo que se habló, abrí la que corresponda con leer_conversacion_anterior y su conversacion_id.';
        }

        return $respuesta;
    }

    /**
     * leer_conversacion_anterior: los mensajes de una conversación de la persona —la actual también—,
     * los más nuevos que entran en TECHO_CARACTERES_DE_LECTURA, en orden cronológico. Si quedan
     * anteriores, la respuesta dice desde qué mensaje pedirlos (paginación hacia atrás).
     *
     * Sin conversacion_id (o con el de la actual) lee la conversación actual: sirve para releer el
     * principio de esta charla cuando ya no entra en el historial (AsistenteIaService::
     * MAX_MENSAJES_HISTORIAL / MAX_CARACTERES_HISTORIAL). El mensaje que se está generando no viaja:
     * está 'pendiente'.
     *
     * @param  \App\Models\AiConversation  $actual  La conversación del turno (define la tenencia).
     * @param  array  $input  {conversacion_id?: int, antes_de_mensaje?: int}
     * @return array
     */
    public static function leer(AiConversation $actual, array $input)
    {
        $id = self::entero($input, 'conversacion_id');
        $crudo = isset($input['conversacion_id']) ? $input['conversacion_id'] : null;

        /*
         * Un conversacion_id que vino pero no es un número («la de ayer») no se lee como «no vino»: eso le
         * abriría ESTA conversación al modelo cuando pidió otra. Vacío o 0 sí es «esta».
         */
        if ($id <= 0 && !is_null($crudo) && !(is_scalar($crudo) && preg_match('/^\s*#?\s*\d*\s*$/', (string) $crudo))) {

            return ['error' => 'conversacion_id va como número: el que va después de # en la lista, o el que te devolvió la búsqueda.'];
        }

        if ($id <= 0) {
            $id = (int) $actual->id;
        }

        $conversacion = self::de_la_misma_persona($actual)
            ->where('ai_conversations.id', $id)
            ->first();

        if (is_null($conversacion)) {

            return ['error' => self::NO_ENCONTRADA];
        }

        $antes_de = self::entero($input, 'antes_de_mensaje');
        $antes_de = $antes_de > 0 ? $antes_de : null;

        $es_la_actual = (int) $conversacion->id === (int) $actual->id;

        $query = AiMessage::where('ai_conversation_id', $conversacion->id)
            ->whereIn('estado', ['listo', 'error'])
            ->with(['acciones', 'imagenes'])
            ->orderBy('id', 'DESC')
            ->limit(self::MAX_MENSAJES_POR_LECTURA);

        if (!is_null($antes_de)) {
            $query->where('id', '<', $antes_de);
        }

        $seleccionados = [];
        $acumulado = 0;

        /*
         * Ronda de arreglos R5: si lo leído de OTRA conversación trae fotos o tarjetas esperando el sí, la
         * nota dice que desde acá no se usan ni se confirman (ver nota_de_la_lectura()).
         */
        $con_fotos = false;
        $con_propuestas = false;

        foreach ($query->get() as $mensaje) {
            $leido = self::mensaje_para_leer($mensaje, $es_la_actual);

            if (is_null($leido)) {
                continue;
            }

            $largo = self::largo_de($leido);

            /*
             * El mensaje que hace pasar el techo queda afuera, salvo que sea el primero (el más nuevo):
             * sin él la lectura volvería vacía y la paginación no avanzaría nunca.
             */
            if ($acumulado + $largo > self::TECHO_CARACTERES_DE_LECTURA && count($seleccionados) > 0) {
                break;
            }

            $acumulado += $largo;
            $seleccionados[] = $leido;

            $con_fotos = $con_fotos || !is_null($leido['fotos']);
            $con_propuestas = $con_propuestas || $mensaje->acciones->contains(function ($accion) {
                return (string) $accion->estado === AiMessageAction::ESTADO_PROPUESTA;
            });
        }

        // Venían del más nuevo al más viejo: se dan vuelta para leerlos en orden.
        $seleccionados = array_reverse($seleccionados);

        $hay_anteriores = false;
        $para_seguir = null;

        if (count($seleccionados)) {
            $mas_viejo = (int) $seleccionados[0]['id'];

            $hay_anteriores = AiMessage::where('ai_conversation_id', $conversacion->id)
                ->whereIn('estado', ['listo', 'error'])
                ->where('id', '<', $mas_viejo)
                ->exists();

            $para_seguir = $hay_anteriores ? $mas_viejo : null;
        }

        return [
            'conversacion_id'              => (int) $conversacion->id,
            'titulo'                       => self::titulo_de($conversacion),
            'es_esta_conversacion'         => $es_la_actual,
            'empezo'                       => self::fecha($conversacion->created_at),
            'termino'                      => self::fecha(self::actividad_de($conversacion)),
            'mensajes'                     => $seleccionados,
            'hay_anteriores'               => $hay_anteriores,
            'antes_de_mensaje_para_seguir' => $para_seguir,
            'nota'                         => self::nota_de_la_lectura(count($seleccionados), $hay_anteriores, $para_seguir, $es_la_actual, !is_null($antes_de), $con_fotos, $con_propuestas),
        ];
    }

    /**
     * El bloque del system con las últimas conversaciones de la persona: hasta
     * CONVERSACIONES_EN_EL_PROMPT de los últimos DIAS_EN_EL_PROMPT días, sin la actual, sin las de MCP
     * y con al menos un mensaje. '' si no hay ninguna.
     *
     * Va en un bloque SIN cache_control (cambia con cada conversación nueva): ver
     * AsistenteIaService::build_system_payload(). 🔴 NUNCA ROMPE EL TURNO: si la consulta falla, se
     * registra y el turno sigue sin la lista (el modelo igual tiene buscar_conversaciones_anteriores).
     *
     * Cada línea: `- #27 · 29/10 23:49 · «Fecha de pago cheques diferidos» · WhatsApp` (la fecha es la
     * del último mensaje). El número es el conversacion_id que el modelo le pasa a leer.
     *
     * @param  \App\Models\AiConversation  $actual
     * @return string
     */
    public static function bloque_para_prompt(AiConversation $actual)
    {
        try {
            $desde = Carbon::now()->subDays(self::DIAS_EN_EL_PROMPT);

            $conversaciones = self::con_mensajes(self::de_la_misma_persona($actual))
                ->where('ai_conversations.id', '!=', (int) $actual->id)
                ->whereRaw(self::ACTIVIDAD . ' >= ?', [$desde->toDateTimeString()])
                ->orderByRaw(self::ACTIVIDAD . ' DESC')
                ->orderBy('ai_conversations.id', 'DESC')
                ->limit(self::CONVERSACIONES_EN_EL_PROMPT)
                ->get();

            if ($conversaciones->isEmpty()) {

                return '';
            }

            $resumenes = self::resumenes($conversaciones);

            $lineas = [];

            foreach ($conversaciones as $conversacion) {
                $momento = self::momento(self::actividad_de($conversacion));

                $lineas[] = '- #' . (int) $conversacion->id
                    . ' · ' . (is_null($momento) ? 'sin fecha' : $momento->format('d/m H:i'))
                    . ' · «' . self::titulo_de($conversacion) . '»'
                    . ' · ' . self::etiqueta_del_canal($resumenes[(int) $conversacion->id]['canal']);
            }

            return 'Tus últimas conversaciones con esta persona, de la más reciente a la más vieja. No viajan en esta'
                . ' conversación: si te habla de algo de una de ellas, abrila con leer_conversacion_anterior pasando el'
                . ' número que va después de #.' . "\n"
                . implode("\n", $lineas);

        } catch (\Throwable $e) {
            Log::warning('ConversacionesAnterioresIaHelper: no se pudo armar la lista de conversaciones anteriores; el turno sigue sin ella.', [
                'ai_conversation_id' => $actual->id,
                'error'              => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Los registros (entidad + número: «pago» 1706, «gasto» 88) de las cargas CONFIRMADAS de las
     * conversaciones que el modelo abrió o encontró en este turno (ronda de arreglos de la revisión
     * independiente, R2, 30/10/2026). Salen del texto del resultado de cada tarjeta («Pago N° 1706
     * registrado…»); una carga sin número en su resultado (una tarea agendada) no da ninguno.
     *
     * 🔴 PARA QUÉ. La guarda de la carga prometida (AsistenteIaService::afirma_una_carga_que_no_se_hizo)
     * mira si el texto da por hecha una carga que ESTE mensaje no ejecutó. Medido el 30/10 con
     * Http::fake: después de leer la conversación de ayer, «El Pago N° 1706 a Pinturas Sudamericanas
     * quedó registrado con los 5 e-cheq.» a un pedido como «Seguí con lo del pago de Pinturería
     * Sudamericana» disparaba el reintento (una llamada más, al Profundo, con la nota que le pide cargar)
     * y, si el modelo lo repetía, el «Ojo: con este mensaje no registré ningún cambio…». Es verdad lo que
     * dice: está contando lo que leyó. Con estos registros, las oraciones que nombran uno se sacan antes
     * de mirar (sin_oraciones_sobre_registros()).
     *
     * Tenencia: solo conversaciones de la misma persona (de_la_misma_persona()), sin la actual (lo de
     * esta conversación lo cubre su historial, como siempre). Nunca lanza: si algo falla, ninguno.
     *
     * @param  \App\Models\AiConversation  $actual
     * @param  array<int, int>  $conversacion_ids  Las que mostraron leer_ / buscar_ en este turno.
     * @return array<int, array{entidad: string, numero: string}>
     */
    public static function registros_confirmados(AiConversation $actual, array $conversacion_ids)
    {
        try {
            $ids = array_values(array_unique(array_filter(array_map('intval', $conversacion_ids))));

            if (!count($ids)) {

                return [];
            }

            $visibles = self::de_la_misma_persona($actual)
                ->whereIn('ai_conversations.id', $ids)
                ->where('ai_conversations.id', '!=', (int) $actual->id)
                ->pluck('ai_conversations.id')
                ->all();

            if (!count($visibles)) {

                return [];
            }

            $registros = [];

            $acciones = AiMessageAction::whereIn('ai_conversation_id', $visibles)
                ->where('estado', AiMessageAction::ESTADO_CONFIRMADA)
                ->get(['id', 'resultado']);

            foreach ($acciones as $accion) {
                $texto = is_object($accion->resultado) && isset($accion->resultado->texto) ? (string) $accion->resultado->texto : '';

                if (!preg_match_all('/(\p{L}+)\s+(?:N\s*[°º.o]|n[uú]mero|nro\.?|#)\s*(\d[\d.]*)/iu', $texto, $coincidencias, PREG_SET_ORDER)) {
                    continue;
                }

                foreach ($coincidencias as $coincidencia) {
                    $numero = str_replace('.', '', $coincidencia[2]);

                    if ($numero === '') {
                        continue;
                    }

                    $entidad = mb_strtolower(self::sin_tildes($coincidencia[1]));

                    $registros[$entidad . '#' . $numero] = ['entidad' => $entidad, 'numero' => $numero];
                }
            }

            return array_values($registros);

        } catch (\Throwable $e) {
            Log::warning('ConversacionesAnterioresIaHelper: no se pudieron leer los registros de las conversaciones leídas; la guarda mira el texto entero.', [
                'ai_conversation_id' => $actual->id,
                'error'              => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * El texto sin las oraciones que nombran alguno de los registros (R2, ver registros_confirmados()):
     * una oración se saca si tiene la entidad («pago», también «pagos») Y el número («1706», también
     * «1.706») del mismo registro. Las demás quedan tal cual, una por renglón, así que una mentira en
     * OTRA oración («Ya te agendé los recordatorios.») la guarda la sigue viendo. Se parte igual que la
     * guarda (TarjetaPrometidaIaHelper::oraciones(): después de un signo de cierre y un espacio, o en un
     * salto de línea).
     *
     * ⚠️ Lo que no distingue: una oración que cuenta un cambio NUEVO sobre ese mismo registro sin haberlo
     * hecho («le cargué la fecha a los cheques del Pago N° 1706») también se saca. Es el precio de no
     * mirar el verbo, y queda acotado a registros de una conversación que el modelo abrió EN ESTE TURNO.
     *
     * @param  string  $texto
     * @param  array<int, array{entidad: string, numero: string}>  $registros
     * @return string
     */
    public static function sin_oraciones_sobre_registros($texto, array $registros)
    {
        $texto = (string) $texto;

        if (!count($registros) || trim($texto) === '') {

            return $texto;
        }

        $oraciones = preg_split('/(?<=[.!?…])\s+|\n+/u', $texto);

        if (!is_array($oraciones)) {

            return $texto;
        }

        $quedan = [];

        foreach ($oraciones as $oracion) {
            $normalizada = mb_strtolower(self::sin_tildes((string) $oracion));
            $normalizada = (string) preg_replace('/(?<=\d)\.(?=\d{3}(?!\d))/u', '', $normalizada);

            $nombra_un_registro = false;

            foreach ($registros as $registro) {
                if (preg_match('/\b' . preg_quote($registro['entidad'], '/') . '/u', $normalizada)
                    && preg_match('/(?<!\d)' . preg_quote($registro['numero'], '/') . '(?!\d)/u', $normalizada)) {
                    $nombra_un_registro = true;
                    break;
                }
            }

            if (!$nombra_un_registro) {
                $quedan[] = $oracion;
            }
        }

        return implode("\n", $quedan);
    }

    /**
     * Las conversaciones que la persona de la conversación actual puede ver: mismo dueño, misma
     * persona, sin las de MCP. 🔴 Es el ÚNICO punto de entrada de las tres funciones públicas: la
     * tenencia no se arma en otro lado.
     *
     * Una conversación sin dueño o sin persona (no debería existir) no ve ninguna: con
     * where(columna, 0) no hay riesgo de que un null termine en un whereNull que matchee otras.
     *
     * @param  \App\Models\AiConversation  $actual
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected static function de_la_misma_persona(AiConversation $actual)
    {
        $query = AiConversation::query()
            ->where('ai_conversations.user_id', (int) $actual->user_id)
            ->where('ai_conversations.auth_user_id', (int) $actual->auth_user_id)
            ->where(function ($sin_mcp) {
                $sin_mcp->whereNull('ai_conversations.origen')
                        ->orWhere('ai_conversations.origen', '!=', AiConversation::ORIGEN_MCP);
            });

        if ((int) $actual->user_id <= 0 || (int) $actual->auth_user_id <= 0) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    /**
     * Solo las conversaciones con al menos un mensaje que no esté generándose (una conversación vacía,
     * la que se abrió y nunca se usó, no tiene nada que leer).
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected static function con_mensajes($query)
    {
        return $query->whereExists(function ($con_mensajes) {
            $con_mensajes->selectRaw('1')
                ->from('ai_messages')
                ->whereColumn('ai_messages.ai_conversation_id', 'ai_conversations.id')
                ->where('ai_messages.estado', '!=', 'pendiente');
        });
    }

    /**
     * Suma a la consulta la condición de UNA palabra: tiene que estar en el título, en algún mensaje o
     * en el resultado de alguna carga confirmada de la conversación.
     *
     * 🔴 EL RESULTADO DE LA TARJETA SE BUSCA DOS VECES. La columna `resultado` es JSON guardado por el
     * cast 'object' de Eloquent, que escapa lo que no es ASCII: «Pinturería» queda «Pinturería»
     * adentro de la base, y la colación no sabe que son lo mismo. Por eso, además del LIKE común, va
     * el LIKE con la palabra escapada igual que la escribe json_encode (con la barra invertida
     * duplicada, que en un LIKE es el carácter de escape).
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $palabra  Solo letras y números (ver palabras()): no lleva % ni _.
     * @return void
     */
    protected static function con_la_palabra($query, $palabra)
    {
        $like = '%' . $palabra . '%';

        $escapada = (string) json_encode($palabra);
        $escapada = mb_substr($escapada, 1, mb_strlen($escapada) - 2);
        $like_json = '%' . str_replace('\\', '\\\\', $escapada) . '%';

        $query->where(function ($donde) use ($like, $like_json) {
            $donde->where('ai_conversations.titulo', 'like', $like)
                ->orWhereExists(function ($en_un_mensaje) use ($like) {
                    $en_un_mensaje->selectRaw('1')
                        ->from('ai_messages')
                        ->whereColumn('ai_messages.ai_conversation_id', 'ai_conversations.id')
                        ->where('ai_messages.contenido', 'like', $like);
                })
                ->orWhereExists(function ($en_una_carga) use ($like, $like_json) {
                    $en_una_carga->selectRaw('1')
                        ->from('ai_message_actions')
                        ->whereColumn('ai_message_actions.ai_conversation_id', 'ai_conversations.id')
                        ->where('ai_message_actions.estado', AiMessageAction::ESTADO_CONFIRMADA)
                        ->where(function ($en_el_resultado) use ($like, $like_json) {
                            $en_el_resultado->where('ai_message_actions.resultado', 'like', $like);

                            if ($like_json !== $like) {
                                $en_el_resultado->orWhere('ai_message_actions.resultado', 'like', $like_json);
                            }
                        });
                });
        });
    }

    /**
     * Las palabras significativas del texto: solo letras y números, de LARGO_MINIMO_DE_PALABRA o más,
     * sin PALABRAS_VACIAS ni repetidas, hasta MAX_PALABRAS. En minúscula.
     *
     * @param  string  $texto
     * @return array<int, string>
     */
    protected static function palabras($texto)
    {
        $partes = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string) $texto), -1, PREG_SPLIT_NO_EMPTY);

        if (!is_array($partes)) {

            return [];
        }

        $palabras = [];

        foreach ($partes as $parte) {
            if (mb_strlen($parte) < self::LARGO_MINIMO_DE_PALABRA || in_array($parte, self::PALABRAS_VACIAS, true)) {
                continue;
            }

            if (in_array($parte, $palabras, true)) {
                continue;
            }

            $palabras[] = $parte;

            if (count($palabras) >= self::MAX_PALABRAS) {
                break;
            }
        }

        return $palabras;
    }

    /**
     * Un id del input como entero; 0 si no vino o no trae ningún número. Acepta el número como texto y
     * también con el «#» de la lista del system («#27»): el modelo lo copia tal cual lo ve, y leerlo
     * como «no vino» le abriría la conversación actual en vez de la que pidió.
     *
     * @param  array  $input
     * @param  string  $clave
     * @return int
     */
    protected static function entero(array $input, $clave)
    {
        if (!isset($input[$clave]) || !is_scalar($input[$clave])) {

            return 0;
        }

        if (is_int($input[$clave]) || is_float($input[$clave])) {

            return (int) $input[$clave];
        }

        $valor = trim((string) $input[$clave]);

        if (preg_match('/^#?\s*(\d+)$/', $valor, $partes)) {

            return (int) $partes[1];
        }

        return 0;
    }

    /**
     * Una fecha del input como el principio (o el final) de ese día; null si no vino; false si vino
     * mal escrita (la búsqueda contesta el formato en vez de ignorarla callada).
     *
     * @param  array  $input
     * @param  string  $clave  'desde' o 'hasta'.
     * @param  bool  $fin_del_dia  true para 'hasta' (incluye todo ese día).
     * @return \Carbon\Carbon|null|false
     */
    protected static function fecha_del_input(array $input, $clave, $fin_del_dia)
    {
        if (!isset($input[$clave]) || !is_scalar($input[$clave]) || trim((string) $input[$clave]) === '') {

            return null;
        }

        $valor = trim((string) $input[$clave]);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {

            return false;
        }

        try {
            $fecha = Carbon::createFromFormat('Y-m-d', $valor);
        } catch (\Throwable $e) {

            return false;
        }

        if ($fecha === false || $fecha->format('Y-m-d') !== $valor) {

            return false;
        }

        return $fin_del_dia ? $fecha->endOfDay() : $fecha->startOfDay();
    }

    /**
     * El canal y la cantidad de mensajes de cada conversación, en dos consultas para todas juntas (no
     * una por conversación).
     *
     * El canal es el del último mensaje (una conversación de WhatsApp puede seguirse desde el panel);
     * una de origen whatsapp sin canal en sus mensajes, WhatsApp; si no, el sistema.
     *
     * @param  \Illuminate\Support\Collection  $conversaciones
     * @return array<int, array{canal: string, mensajes: int}>  Por id de conversación.
     */
    protected static function resumenes($conversaciones)
    {
        $ids = [];

        foreach ($conversaciones as $conversacion) {
            $ids[] = (int) $conversacion->id;
        }

        $resumenes = [];

        foreach ($conversaciones as $conversacion) {
            $resumenes[(int) $conversacion->id] = [
                'canal'    => (string) $conversacion->origen === AiConversation::ORIGEN_WHATSAPP ? AiMessage::CANAL_WHATSAPP : AiMessage::CANAL_SISTEMA,
                'mensajes' => 0,
            ];
        }

        if (!count($ids)) {

            return $resumenes;
        }

        $totales = AiMessage::whereIn('ai_conversation_id', $ids)
            ->where('estado', '!=', 'pendiente')
            ->groupBy('ai_conversation_id')
            ->selectRaw('ai_conversation_id, COUNT(*) AS total, MAX(id) AS ultimo_id')
            ->get();

        $ultimos = [];

        foreach ($totales as $fila) {
            $resumenes[(int) $fila->ai_conversation_id]['mensajes'] = (int) $fila->total;
            $ultimos[] = (int) $fila->ultimo_id;
        }

        if (count($ultimos)) {
            foreach (AiMessage::whereIn('id', $ultimos)->get(['id', 'ai_conversation_id', 'canal']) as $ultimo) {
                $canal = trim((string) $ultimo->canal);

                if ($canal !== '') {
                    $resumenes[(int) $ultimo->ai_conversation_id]['canal'] = $canal;
                }
            }
        }

        return $resumenes;
    }

    /**
     * Las últimas CARGAS_POR_CONVERSACION cargas confirmadas de la conversación, en orden, con el texto
     * de su resultado («Pago N° 1706 registrado…»): es lo que más rápido dice de qué se trató.
     *
     * @param  \App\Models\AiConversation  $conversacion
     * @return array<int, string>
     */
    protected static function cargas_confirmadas(AiConversation $conversacion)
    {
        $acciones = AiMessageAction::where('ai_conversation_id', $conversacion->id)
            ->where('estado', AiMessageAction::ESTADO_CONFIRMADA)
            ->orderBy('id', 'DESC')
            ->limit(self::CARGAS_POR_CONVERSACION)
            ->get()
            ->reverse();

        $cargas = [];

        foreach ($acciones as $accion) {
            $texto = is_object($accion->resultado) && isset($accion->resultado->texto) ? (string) $accion->resultado->texto : '';

            if (trim($texto) === '') {
                $presentacion = is_array($accion->presentacion) ? $accion->presentacion : [];
                $texto = isset($presentacion['titulo']) ? (string) $presentacion['titulo'] : (string) $accion->tipo;
            }

            $cargas[] = self::recortar(self::en_una_linea($texto), self::LARGO_DE_COINCIDENCIA);
        }

        return $cargas;
    }

    /**
     * Hasta COINCIDENCIAS_POR_CONVERSACION extractos de los mensajes donde aparece lo buscado, primero
     * los que tienen más palabras y, a igualdad, los más viejos (el orden de la charla). Cada uno dice
     * quién lo escribió: «persona: …» o «asistente: …».
     *
     * @param  \App\Models\AiConversation  $conversacion
     * @param  array<int, string>  $palabras
     * @return array<int, string>
     */
    protected static function coincidencias(AiConversation $conversacion, array $palabras)
    {
        $mensajes = AiMessage::where('ai_conversation_id', $conversacion->id)
            ->where('estado', '!=', 'pendiente')
            ->where(function ($con_alguna) use ($palabras) {
                foreach ($palabras as $palabra) {
                    $con_alguna->orWhere('contenido', 'like', '%' . $palabra . '%');
                }
            })
            ->orderBy('id')
            ->limit(self::MENSAJES_PARA_COINCIDENCIAS)
            ->get(['id', 'rol', 'contenido']);

        $candidatos = [];

        foreach ($mensajes as $mensaje) {
            $texto = self::en_una_linea((string) $mensaje->contenido);
            $normalizado = mb_strtolower(self::sin_tildes($texto));

            $puntaje = 0;
            $primera = null;

            foreach ($palabras as $palabra) {
                $posicion = mb_strpos($normalizado, mb_strtolower(self::sin_tildes($palabra)));

                if ($posicion === false) {
                    continue;
                }

                $puntaje++;
                $primera = is_null($primera) ? $posicion : min($primera, $posicion);
            }

            if ($puntaje === 0) {
                continue;
            }

            $candidatos[] = [
                'id'      => (int) $mensaje->id,
                'puntaje' => $puntaje,
                'texto'   => ($mensaje->rol === 'user' ? 'persona: ' : 'asistente: ') . self::extracto($texto, (int) $primera),
            ];
        }

        usort($candidatos, function ($a, $b) {
            if ($a['puntaje'] !== $b['puntaje']) {
                return $b['puntaje'] - $a['puntaje'];
            }

            return $a['id'] - $b['id'];
        });

        $extractos = [];

        foreach (array_slice($candidatos, 0, self::COINCIDENCIAS_POR_CONVERSACION) as $candidato) {
            $extractos[] = $candidato['texto'];
        }

        return $extractos;
    }

    /**
     * El pedazo de un texto alrededor de una posición, de LARGO_DE_COINCIDENCIA caracteres como mucho
     * con los «…» incluidos.
     *
     * @param  string  $texto  Ya en una línea.
     * @param  int  $posicion  Dónde está la palabra (en caracteres).
     * @return string
     */
    protected static function extracto($texto, $posicion)
    {
        $largo_total = mb_strlen($texto);

        if ($largo_total <= self::LARGO_DE_COINCIDENCIA) {

            return $texto;
        }

        // Dos lugares para los «…» de cada punta.
        $largo = self::LARGO_DE_COINCIDENCIA - 2;
        $inicio = max(0, $posicion - self::CONTEXTO_ANTES_DE_LA_COINCIDENCIA);
        $inicio = min($inicio, max(0, $largo_total - $largo));

        $pedazo = mb_substr($texto, $inicio, $largo);

        return ($inicio > 0 ? '…' : '') . $pedazo . ($inicio + $largo < $largo_total ? '…' : '');
    }

    /**
     * Un mensaje como lo ve el modelo en la lectura, o null si no tiene nada que mostrar.
     *
     * Reúsa lo que ya arma el historial de la conversación (AsistenteIaService::
     * contenido_para_el_historial) sin cambiarlo: las tarjetas con AccionesIaHelper::linea_de_historial,
     * los avisos de la guarda de cargas fuera (TarjetaPrometidaIaHelper::sin_avisos: son para la
     * persona y, leídos, el modelo los copia), los gráficos y tablas de la app en una línea
     * (BloquesIaHelper), el extracto de los archivos del dueño y las fotos, igual que la marca de un
     * mensaje anterior del historial (con su imagen_id, para usarlas por su id, solo en la actual).
     *
     * 🔴 Una respuesta del asistente en `error` va MARCADA: su texto es el aviso que vio la persona
     * («La consulta se hizo larga…»), y sin la marca el modelo lo leería como algo que sí se hizo. Las
     * tarjetas `descartada` (las de un mensaje que falló) no van: la persona nunca las vio.
     *
     * Las fotos de OTRA conversación van sin su imagen_id (ronda de arreglos R5): ver marca_de_fotos().
     *
     * @param  \App\Models\AiMessage  $mensaje  Con `acciones` e `imagenes` cargadas.
     * @param  bool  $es_la_actual  true si el mensaje es de la conversación del turno.
     * @return array|null
     */
    protected static function mensaje_para_leer(AiMessage $mensaje, $es_la_actual = true)
    {
        $es_persona = $mensaje->rol === 'user';
        $texto = (string) $mensaje->contenido;

        if ($es_persona) {
            $de_los_archivos = ArchivosAdjuntosIaHelper::texto_para_el_modelo($mensaje, false);

            if ($de_los_archivos !== '') {
                $texto = trim($texto) === '' ? $de_los_archivos : rtrim($texto) . "\n\n" . $de_los_archivos;
            }
        } else {
            $texto = TarjetaPrometidaIaHelper::sin_avisos($texto);

            $de_los_bloques = BloquesIaHelper::lineas_de_historial($mensaje->bloques);

            if ($de_los_bloques !== '') {
                $texto = rtrim($texto) . "\n" . $de_los_bloques;
            }
        }

        $texto = self::recortar(trim($texto), self::TECHO_CARACTERES_POR_MENSAJE);

        if (!$es_persona && (string) $mensaje->estado === 'error') {
            $texto = '[Esta respuesta falló: la persona vio este aviso y lo que pidió no se hizo en ese mensaje] ' . $texto;
        }

        $fotos = $es_persona ? self::marca_de_fotos($mensaje, (bool) $es_la_actual) : '';

        $cargas = [];

        foreach ($mensaje->acciones as $accion) {
            if ((string) $accion->estado === AiMessageAction::ESTADO_DESCARTADA) {
                continue;
            }

            $cargas[] = AccionesIaHelper::linea_de_historial($accion);
        }

        if (trim($texto) === '' && $fotos === '' && !count($cargas)) {

            return null;
        }

        return [
            'id'     => (int) $mensaje->id,
            'rol'    => $es_persona ? 'persona' : 'asistente',
            'fecha'  => self::fecha($mensaje->created_at),
            'canal'  => trim((string) $mensaje->canal) !== '' ? (string) $mensaje->canal : AiMessage::CANAL_SISTEMA,
            'texto'  => $texto,
            'fotos'  => $fotos === '' ? null : $fotos,
            'cargas' => $cargas,
        ];
    }

    /**
     * La marca de las fotos que mandó la persona: «[Foto adjunta] [imagen_id 12]» o «[3 fotos
     * adjuntas] [imagen_id 12, 13, 14]». Es la MISMA forma que la de un mensaje anterior del historial
     * (AsistenteIaService::marca_de_las_fotos_del_mensaje(), que no se toca): el modelo la reconoce y
     * puede reusar la foto por su imagen_id. '' si no mandó ninguna.
     *
     * 🔴 SIN EL imagen_id SI LA FOTO ES DE OTRA CONVERSACIÓN (ronda de arreglos de la revisión
     * independiente, R5, 30/10/2026): «[Foto adjunta]» o «[3 fotos adjuntas]» a secas. Las herramientas
     * que usan una foto por su id (FotosDeLaConversacionIaHelper::por_id() y reutilizable_para_articulo())
     * la buscan en la conversación ACTUAL: con el id a la vista, el modelo la mandaba y la carga
     * contestaba que la foto no existe. La nota de la lectura le dice que, si la persona la quiere usar,
     * se la pida de nuevo (nota_de_la_lectura()).
     *
     * @param  \App\Models\AiMessage  $mensaje  Con `imagenes` cargada.
     * @param  bool  $con_ids  false para una foto de otra conversación.
     * @return string
     */
    protected static function marca_de_fotos(AiMessage $mensaje, $con_ids = true)
    {
        $ids = [];

        foreach ($mensaje->imagenes as $imagen) {
            $ids[] = (int) $imagen->id;
        }

        $cuantas = count($ids);

        if ($cuantas === 0) {

            return '';
        }

        $marca = $cuantas === 1 ? '[Foto adjunta]' : '[' . $cuantas . ' fotos adjuntas]';

        if (!$con_ids) {

            return $marca;
        }

        return $marca . ' [imagen_id ' . implode(', ', $ids) . ']';
    }

    /**
     * Lo que pesa un mensaje leído contra el techo de la lectura: su texto, su marca de fotos y sus
     * renglones de tarjetas.
     *
     * @param  array  $leido  Lo que devolvió mensaje_para_leer().
     * @return int
     */
    protected static function largo_de(array $leido)
    {
        $largo = mb_strlen((string) $leido['texto']) + mb_strlen((string) $leido['fotos']);

        foreach ($leido['cargas'] as $linea) {
            $largo += mb_strlen((string) $linea);
        }

        return $largo;
    }

    /**
     * La nota de la lectura: cómo seguir si hay anteriores y la regla de qué hacer con lo leído.
     *
     * Ronda de arreglos de la revisión independiente (R5, 30/10/2026), solo para OTRA conversación y solo
     * si lo leído lo trae:
     *   - con fotos: van sin imagen_id (marca_de_fotos()) porque desde acá no se pueden usar; si la persona
     *     quiere usar una, que la mande de nuevo;
     *   - con tarjetas esperando el sí (estado: propuesta): no se pueden confirmar desde esta conversación
     *     (EjecutorAccionesIaHelper y la confirmación por texto buscan la tarjeta en la conversación del
     *     turno); si la persona quiere alguna, se arma de nuevo con los datos actuales.
     *
     * @param  int  $cuantos
     * @param  bool  $hay_anteriores
     * @param  int|null  $para_seguir
     * @param  bool  $es_la_actual
     * @param  bool  $con_antes_de  true si se pidió seguir hacia atrás desde un mensaje.
     * @param  bool  $con_fotos  true si algún mensaje leído trae fotos.
     * @param  bool  $con_propuestas  true si algún mensaje leído trae una tarjeta esperando el sí.
     * @return string
     */
    protected static function nota_de_la_lectura($cuantos, $hay_anteriores, $para_seguir, $es_la_actual, $con_antes_de = false, $con_fotos = false, $con_propuestas = false)
    {
        if ($cuantos === 0) {

            return $con_antes_de
                ? 'No hay mensajes antes de ese en esta conversación.'
                : 'Esta conversación todavía no tiene mensajes para leer.';
        }

        $nota = $hay_anteriores
            ? 'Hay mensajes anteriores a estos: para verlos, volvé a llamar con antes_de_mensaje = ' . $para_seguir . '. '
            : 'Esta es la conversación desde su principio. ';

        if ($es_la_actual) {

            return $nota . 'Es ESTA conversación.';
        }

        $nota .= 'Es lo que se habló en otra conversación: los datos del negocio (saldos, stock, cheques, precios) pueden haber cambiado, volvé a consultarlos con las herramientas antes de afirmarlos; y lo que ahí ya se cargó (las cargas en estado confirmada) ya está hecho: no lo vuelvas a proponer salvo que te lo pidan.';

        if ($con_propuestas) {
            $nota .= ' Las cargas que ahí quedaron esperando el sí (estado: propuesta) no se pueden confirmar desde esta conversación: si la persona quiere alguna, armala de nuevo con los datos actuales.';
        }

        if ($con_fotos) {
            $nota .= ' Las fotos de esa conversación no se pueden usar desde acá: si la persona quiere usar una, pedile que te la mande de nuevo.';
        }

        return $nota;
    }

    /**
     * El título de la conversación; si todavía no tiene (la inferencia falló o no corrió), el principio
     * del primer mensaje de la persona, para que la lista diga algo reconocible.
     *
     * @param  \App\Models\AiConversation  $conversacion
     * @return string
     */
    protected static function titulo_de(AiConversation $conversacion)
    {
        $titulo = trim((string) $conversacion->titulo);

        if ($titulo !== '') {

            return $titulo;
        }

        $primero = AiMessage::where('ai_conversation_id', $conversacion->id)
            ->where('rol', 'user')
            ->whereNotNull('contenido')
            ->where('contenido', '!=', '')
            ->orderBy('id')
            ->value('contenido');

        $primero = self::en_una_linea((string) $primero);

        return $primero === '' ? 'Sin título' : self::recortar($primero, self::LARGO_DEL_TITULO_DE_REEMPLAZO);
    }

    /**
     * La etiqueta del canal para la lista del system.
     *
     * @param  string  $canal
     * @return string
     */
    protected static function etiqueta_del_canal($canal)
    {
        if ($canal === AiMessage::CANAL_WHATSAPP) {

            return 'WhatsApp';
        }

        if ($canal === AiMessage::CANAL_APP) {

            return 'app ComercioCity IA';
        }

        return 'sistema';
    }

    /**
     * La última actividad de la conversación (ver ACTIVIDAD).
     *
     * @param  \App\Models\AiConversation  $conversacion
     * @return mixed
     */
    protected static function actividad_de(AiConversation $conversacion)
    {
        return !is_null($conversacion->last_message_at) && (string) $conversacion->last_message_at !== ''
            ? $conversacion->last_message_at
            : $conversacion->created_at;
    }

    /**
     * Un momento de la base como Carbon (last_message_at no tiene cast y viene como texto), o null.
     *
     * @param  mixed  $valor
     * @return \Carbon\Carbon|null
     */
    protected static function momento($valor)
    {
        if (is_null($valor) || (string) $valor === '') {

            return null;
        }

        try {
            return $valor instanceof Carbon ? $valor : Carbon::parse($valor);
        } catch (\Throwable $e) {

            return null;
        }
    }

    /**
     * Una fecha como «29/10/2026 23:49», o null.
     *
     * @param  mixed  $valor
     * @return string|null
     */
    protected static function fecha($valor)
    {
        $momento = self::momento($valor);

        return is_null($momento) ? null : $momento->format('d/m/Y H:i');
    }

    /**
     * Recorta un texto a un largo con «…» al final si no entraba.
     *
     * @param  string  $texto
     * @param  int  $largo
     * @return string
     */
    protected static function recortar($texto, $largo)
    {
        $texto = (string) $texto;

        if (mb_strlen($texto) <= $largo) {

            return $texto;
        }

        return rtrim(mb_substr($texto, 0, $largo - 1)) . '…';
    }

    /**
     * El texto en una sola línea (saltos y espacios repetidos a un espacio).
     *
     * @param  string  $texto
     * @return string
     */
    protected static function en_una_linea($texto)
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $texto));
    }

    /**
     * El texto sin tildes ni diéresis ni eñe, letra por letra (cada letra se reemplaza por UNA, así las
     * posiciones en caracteres no se corren). Es para ubicar la palabra en el extracto igual que la
     * encuentra la colación de la base.
     *
     * @param  string  $texto
     * @return string
     */
    protected static function sin_tildes($texto)
    {
        return strtr((string) $texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'À' => 'A', 'È' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U',
        ]);
    }
}
