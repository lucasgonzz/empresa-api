<?php

namespace App\Services\AsistenteIa;

use App\Http\Controllers\Helpers\AgentesIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\TareasDelAsistenteIaHelper;
use App\Models\AiConversation;
use App\Models\User;

/**
 * Las cinco herramientas con las que el dueño le programa trabajo a su asistente (misión
 * agente-tareas-programadas, 21/11/2026): «el viernes a la tarde preguntame por la logística», «todos los
 * días a primera hora contame cómo vienen las ventas», «hoy no, el viernes». Definición y despacho juntos,
 * igual que HerramientasDeContexto: una tool declarada en un solo lado es una tool que la IA llama y recibe
 * «Tool desconocida». La lógica vive en TareasDelAsistenteIaHelper.
 *
 * 🔴 SE OFRECEN SÓLO SI: el dueño tiene `agentes_ia`, existen las tablas, el turno NO es desatendido (un turno
 * que despertó una tarea no puede programar otras: se encadenarían solas) y QUIEN HABLA ES EL DUEÑO (un
 * empleado no programa mensajes al teléfono del dueño). El despacho vuelve a mirar las condiciones que no
 * dependen del turno, por si llegara una llamada inventada.
 *
 * 🔴 SE EJECUTAN DIRECTO, SIN TARJETA: no mueven plata, stock ni le escriben a terceros, y se cancelan con un
 * mensaje. Todos los nombres llevan «tarea» a propósito: la guarda de promesas futuras
 * (PromesaDeAccionFuturaIaHelper) se apaga si el turno pidió una herramienta cuyo nombre matchea
 * /tarea|recordatorio|imagen/, y estas son justamente el «después» que esa guarda echaba de menos.
 *
 * Van AL FINAL de build_tools() (antes del manual y del soporte de la prueba) para no mover el prefijo del caché.
 *
 * IMPORTANTE (PHP 7.4): nada de nullsafe, match, str_contains ni argumentos nombrados.
 */
class HerramientasDeTareasDelAsistente
{
    const PROGRAMAR = 'programar_tarea_del_asistente';

    const REPROGRAMAR = 'reprogramar_tarea_del_asistente';

    const CONSULTAR = 'consultar_tareas_del_asistente';

    const CANCELAR = 'cancelar_tarea_del_asistente';

    const HORARIOS = 'guardar_horarios_del_dueno';

    /**
     * Las definiciones para la API (forma de Anthropic: name / description / input_schema).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function definiciones()
    {
        $momento = [
            'fecha'  => ['type' => 'string', 'description' => 'AAAA-MM-DD, tomada de la lista de días de las instrucciones ("el viernes que viene" = el viernes de esa lista). Una vez: el día. Recurrente: desde cuándo (opcional).'],
            'hora'   => ['type' => 'string', 'description' => 'HH:MM si el dueño dijo una hora exacta ("a las 8" = 08:00). Gana sobre la franja.'],
            'franja' => ['type' => 'string', 'enum' => ['manana', 'mediodia', 'tarde', 'noche'], 'description' => 'Si dijo "a la mañana", "al mediodía", "a la tarde" o "a la noche" (o "a primera hora" = manana): la hora la resuelve el sistema con los horarios que prefiere el dueño.'],
        ];

        return [
            [
                'name'         => self::PROGRAMAR,
                'description'  => 'Programa algo que VOS vas a hacer a una hora: despertarte, consultar datos del sistema y escribirle al dueño por WhatsApp (por ejemplo "el viernes a la tarde retomá las preguntas de logística", "todos los días a primera hora contame cómo vienen las ventas"). 🔴 NO la uses para algo que el DUEÑO tiene que hacer o recordar ("recordame llamar a Juan"): eso es proponer_tarea (Agenda). "Avisame cuando entre un pedido" o "cuando un producto quede sin stock" es proponer_avisos_por_whatsapp. Cuando llegue la hora vas a trabajar SOLA, sin el dueño mirando: sólo vas a poder consultar, no cargar, confirmar ni guardar nada; si hace falta su sí, le vas a dejar una pregunta. Por eso la instrucción tiene que ser autosuficiente: qué mirar y qué decirle, en una o dos frases. Se ejecuta directo, sin tarjeta. El resultado trae `cuando` y `proxima` (el momento ya resuelto: repetíselo al dueño tal cual) y `aviso` (lo único que podés prometer sobre cómo le llega). Si dice que no, contale el motivo y corregilo con él. Límites: 10 tareas activas, una vez entre 5 minutos y 90 días, y como máximo una vez por día.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => array_merge([
                        'titulo'      => ['type' => 'string', 'description' => 'Nombre corto de la tarea (hasta 120 caracteres). Ejemplo: "Preguntas de logística".'],
                        'instruccion' => ['type' => 'string', 'description' => 'Qué tenés que hacer cuando te despiertes, autosuficiente y de hasta 1000 caracteres. Ejemplo: "Retomá las preguntas pendientes sobre logística y hacele la próxima".'],
                        'recurrencia' => ['type' => 'string', 'enum' => ['una_vez', 'diaria', 'dias_semana', 'semanal'], 'description' => 'una_vez (por defecto), diaria (todos los días), dias_semana (ciertos días, con `dias`) o semanal (un solo día por semana, con `dias`). No existe nada más frecuente que una vez por día.'],
                    ], $momento, [
                        'dias'  => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Para dias_semana y semanal: los días, 1 = lunes … 7 = domingo. Ejemplo: [1,3,5].'],
                        'hasta' => ['type' => 'string', 'description' => 'Sólo en recurrentes: AAAA-MM-DD del último día (opcional).'],
                        'area'  => ['type' => 'string', 'description' => 'Opcional: el área del negocio (compras, ventas, logística…). Por defecto "general".'],
                    ]),
                    'required'   => ['titulo', 'instruccion'],
                ],
            ],
            [
                'name'         => self::REPROGRAMAR,
                'description'  => 'Cambia una tarea programada: la pospone ("hoy no me preguntes, mandame recién el viernes"), le cambia la hora o la instrucción, o reactiva una pausada. Mandá `tarea_id` (lo ves con consultar_tareas_del_asistente) y SOLO lo que cambia. Una vez: `fecha` y `franja`/`hora` nuevas. Recurrente: `desde` = el día desde el cual vuelve a correr ("recién el viernes"). Devuelve el momento resuelto en `cuando` y `proxima`: repetíselo al dueño.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => array_merge([
                        'tarea_id'    => ['type' => 'integer', 'description' => 'El id de la tarea.'],
                        'titulo'      => ['type' => 'string', 'description' => 'Título nuevo (opcional).'],
                        'instruccion' => ['type' => 'string', 'description' => 'Instrucción nueva (opcional, hasta 1000 caracteres).'],
                        'recurrencia' => ['type' => 'string', 'enum' => ['una_vez', 'diaria', 'dias_semana', 'semanal'], 'description' => 'Sólo si cambia la frecuencia.'],
                    ], $momento, [
                        'desde' => ['type' => 'string', 'description' => 'Recurrentes: AAAA-MM-DD desde el cual vuelve a correr ("recién el viernes").'],
                        'dias'  => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Sólo si cambian los días (1 = lunes … 7 = domingo).'],
                        'hasta' => ['type' => 'string', 'description' => 'AAAA-MM-DD del último día (opcional).'],
                    ]),
                    'required'   => ['tarea_id'],
                ],
            ],
            [
                'name'         => self::CONSULTAR,
                'description'  => 'Lista lo que tenés programado (tareas activas y pausadas, por orden de próxima ejecución) con su id, cuándo corre, su estado y cómo salió la última vez; más la hora de ahora. Usala cuando el dueño pregunte qué tenés programado, y antes de reprogramar o cancelar si no sabés el id.',
                'input_schema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name'         => self::CANCELAR,
                'description'  => 'Cancela una tarea programada: no se vuelve a despertar. Mandá `tarea_id` (lo ves con consultar_tareas_del_asistente).',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => ['tarea_id' => ['type' => 'integer', 'description' => 'El id de la tarea.']],
                    'required'   => ['tarea_id'],
                ],
            ],
            [
                'name'         => self::HORARIOS,
                'description'  => 'Guarda a qué hora prefiere el dueño que le escribas: a qué hora es "a la mañana", "al mediodía", "a la tarde" y "a la noche", su hora preferida cuando no dice ninguna, y los días de la semana en que NO quiere mensajes (las tareas diarias los saltean). Preguntáselo UNA vez cuando programes tu primera tarea o cuando él lo mencione. Valen para las tareas NUEVAS: las ya programadas no se mueven (reprogramalas si quiere). Mandá solo lo que cambia.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'manana'            => ['type' => 'string', 'description' => 'HH:MM de "a la mañana" / "a primera hora". Ejemplo: "08:30".'],
                        'mediodia'          => ['type' => 'string', 'description' => 'HH:MM de "al mediodía".'],
                        'tarde'             => ['type' => 'string', 'description' => 'HH:MM de "a la tarde". Ejemplo: "18:30".'],
                        'noche'             => ['type' => 'string', 'description' => 'HH:MM de "a la noche".'],
                        'hora_preferida'    => ['type' => 'string', 'description' => 'HH:MM por defecto cuando no dice ni hora ni franja.'],
                        'dias_sin_mensajes' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Días en que no quiere mensajes, 1 = lunes … 7 = domingo. [] = todos los días.'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Los nombres, en el mismo orden que definiciones().
     *
     * @return array<int, string>
     */
    public static function nombres()
    {
        return [self::PROGRAMAR, self::REPROGRAMAR, self::CONSULTAR, self::CANCELAR, self::HORARIOS];
    }

    /**
     * true si la tool es de este registro.
     *
     * @param  string  $tool_name
     * @return bool
     */
    public static function maneja($tool_name)
    {
        return in_array((string) $tool_name, self::nombres(), true);
    }

    /**
     * true si en esta conversación hay que ofrecer las cinco herramientas (y decirle la hora al modelo): el
     * dueño tiene Agentes de IA, la base tiene las tablas y quien habla es el dueño. El turno desatendido lo
     * decide quien llama (AsistenteIaService).
     *
     * @param  \App\Models\User|null  $owner
     * @param  \App\Models\AiConversation|null  $conversation
     * @return bool
     */
    public static function se_ofrecen($owner, $conversation)
    {
        if (!($owner instanceof User) || !($conversation instanceof AiConversation)) {

            return false;
        }

        return AgentesIaHelper::tiene($owner)
            && TareasDelAsistenteIaHelper::hay_tablas()
            && self::habla_el_dueno($conversation);
    }

    /**
     * true si quien habla en la conversación es el dueño (sin persona, o la misma que el dueño).
     *
     * @param  \App\Models\AiConversation  $conversation
     * @return bool
     */
    public static function habla_el_dueno(AiConversation $conversation)
    {
        return is_null($conversation->auth_user_id) || (int) $conversation->auth_user_id === (int) $conversation->user_id;
    }

    /**
     * Ejecuta una de las cinco herramientas.
     *
     * Lo que no se pudo hacer (un límite, un momento pasado, una tarea que no existe) viaja con `is_error`
     * para que el modelo lo trate como un rechazo y lo corrija con el dueño.
     *
     * @param  string  $tool_name
     * @param  array  $input
     * @param  \App\Models\AiConversation  $conversation
     * @return array{content: string, is_error: bool}
     */
    public static function ejecutar($tool_name, array $input, AiConversation $conversation)
    {
        $owner = User::find((int) $conversation->user_id);

        if (is_null($owner) || !AgentesIaHelper::tiene($owner) || !TareasDelAsistenteIaHelper::hay_tablas()) {

            return self::resultado(['error' => 'Las tareas del asistente son parte de Agentes de IA, y este negocio no las tiene activas.'], true);
        }

        if (!self::habla_el_dueno($conversation)) {

            return self::resultado(['error' => 'Sólo el dueño del negocio puede programar tareas del asistente: no le puedo agendar mensajes a su teléfono a pedido de otra persona.'], true);
        }

        switch ((string) $tool_name) {

            case self::PROGRAMAR:
                $datos = TareasDelAsistenteIaHelper::programar($owner, $conversation->id, $input);
                break;

            case self::REPROGRAMAR:
                $datos = TareasDelAsistenteIaHelper::reprogramar($owner, $input);
                break;

            case self::CANCELAR:
                $datos = TareasDelAsistenteIaHelper::cancelar($owner, $input);
                break;

            case self::HORARIOS:
                $datos = TareasDelAsistenteIaHelper::guardar_horarios($owner, $input);
                break;

            default:
                $datos = TareasDelAsistenteIaHelper::listar($owner);
        }

        return self::resultado($datos, isset($datos['error']));
    }

    /**
     * El tool_result: JSON con el mismo fallback que el resto del asistente.
     *
     * @param  array  $datos
     * @param  bool  $is_error
     * @return array{content: string, is_error: bool}
     */
    protected static function resultado(array $datos, $is_error)
    {
        return [
            'content'  => json_encode($datos, JSON_UNESCAPED_UNICODE) ?: '[]',
            'is_error' => (bool) $is_error,
        ];
    }
}
