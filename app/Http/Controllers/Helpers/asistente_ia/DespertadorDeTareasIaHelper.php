<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiTareaDelAsistente;
use App\Models\AiTareaDelAsistenteEjecucion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Quien despierta al asistente a la hora de sus tareas y cierra lo que despertó (misión
 * agente-tareas-programadas, 21/11/2026). Lo corre el comando `asistente:despertar-tareas`, que el Kernel agenda
 * cada minuto: en hosting compartido por el cron del schedule y en el VPS por el supervisor, sin depender del admin.
 *
 * 🔴 ES UN BARRIDO CORTO: consulta, reclama, ARMA el turno y despacha el job. NUNCA corre el turno del modelo
 * acá adentro ni espera: en hosting compartido un proceso largo colgado del schedule retiene los candados del
 * cron. El turno lo corre el worker de la cola (ResponderMensajeChatIaJob, sin tocarlo) y el SIGUIENTE barrido
 * lo cierra (cerrar()).
 *
 * El circuito, por tarea:
 *   1. despertar(): la tarea vence (con ANTICIPO_MINUTOS de anticipo, porque entre el barrido, la cola, el turno
 *      y el admin pasan minutos) → se reclama con la fila bloqueada y el unique (tarea_id, ocurrencia_at) →
 *      queda un mensaje «[Tarea programada] …» (tipo `tarea_programada`) y un `assistant` pendiente SIN acciones
 *      en la conversación de WhatsApp vigente del dueño → se despacha el job.
 *   2. cerrar(): el `assistant` terminó (`lista`), falló o se colgó (`fallida`); tres fallos seguidos pausan la
 *      tarea; una `lista` que nadie entregó en 3 horas pasa a `no_entregada` (sigue en la conversación).
 *   3. items_de_entrega() / marcar(): la ejecución `lista` sale como un ítem más del carril de recordatorios que el
 *      admin ya consulta cada 5 minutos (RecordatoriosDelAsistenteHelper), y su sello la deja `entregada`.
 *
 * Todo está acotado por el dueño de la instancia (hay bases compartidas entre comercios).
 *
 * PHP 7.4: sin match, sin nullsafe, sin str_contains.
 */
class DespertadorDeTareasIaHelper
{
    /** El prefijo del `id` de un ítem de tarea del asistente en el carril de recordatorios. */
    const PREFIJO_ID = 'recordatorio:tarea_asistente:';

    /** Cuántos minutos antes de su hora se despierta una tarea (compensa la demora del circuito). */
    const ANTICIPO_MINUTOS = 2;

    /** Cuántas tareas se despiertan como mucho por barrido (el resto sigue vencida y sale al minuto siguiente). */
    const MAX_POR_BARRIDO = 3;

    /** Cuántos turnos programados puede correr un negocio por día. */
    const MAX_EJECUCIONES_POR_DIA = 6;

    /** Si el dueño escribió hace menos de estos minutos no se lo interrumpe... */
    const MINUTOS_SIN_INTERRUMPIR = 10;

    /** ...hasta que pasan estos minutos de la hora programada (después corre igual). */
    const MINUTOS_MAXIMOS_DE_ESPERA = 60;

    /** Una ocurrencia más vieja que esto (el servidor estuvo caído) no se «pone al día»: se omite. */
    const HORAS_DE_VENCIDA = 3;

    /** Un turno que sigue `pendiente` después de esto se da por colgado. */
    const MINUTOS_DE_COLGADA = 15;

    /** Fallos seguidos que pausan la tarea. */
    const MAX_FALLOS = 3;

    /** Largo máximo del resultado que viaja por el contrato. */
    const LARGO_MAXIMO_DEL_TEXTO = 3500;

    /** Lo que se le agrega a un resultado recortado. */
    const SUFIJO_RECORTADO = '… (seguí en la app)';

    /** Largo máximo del título dentro del `detalle` y del `resumen` del contrato. */
    const LARGO_TITULO_EN_EL_ITEM = 100;

    /* ─────────────────────────────── despertar ─────────────────────────────── */

    /**
     * Despierta las tareas del dueño que vencieron (con anticipo), como mucho MAX_POR_BARRIDO.
     *
     * @param  \App\Models\User  $dueno
     * @return int  Cuántos turnos se despacharon.
     */
    public static function despertar(User $dueno): int
    {
        $ahora = TareasDelAsistenteIaHelper::ahora();

        $ids = AiTareaDelAsistente::where('owner_id', (int) $dueno->id)
            ->where('estado', AiTareaDelAsistente::ESTADO_ACTIVA)
            ->whereNotNull('proxima_ejecucion_at')
            ->where('proxima_ejecucion_at', '<=', $ahora->copy()->addMinutes(self::ANTICIPO_MINUTOS)->format('Y-m-d H:i:s'))
            ->orderBy('proxima_ejecucion_at')
            ->orderBy('id')
            ->limit(self::MAX_POR_BARRIDO)
            ->pluck('id')
            ->all();

        $despachados = 0;

        foreach ($ids as $id) {

            try {

                $assistant_id = null;

                DB::transaction(function () use ($dueno, $id, $ahora, &$assistant_id) {
                    $assistant_id = self::despertar_una($dueno, (int) $id, $ahora);
                });

                /* Después del commit: el worker tiene que encontrar el turno ya guardado. */
                if (!is_null($assistant_id)) {

                    self::despachar($assistant_id);

                    $despachados++;
                }

            } catch (\Throwable $e) {

                Log::warning('DespertadorDeTareasIaHelper: no se pudo despertar la tarea ' . $id, ['error' => $e->getMessage()]);
            }
        }

        return $despachados;
    }

    /**
     * Reclama una tarea y arma su turno, adentro de una transacción. Devuelve el id del `assistant` a despachar,
     * o null si no corresponde correr (la omitió, la frenó una regla o la ganó otro proceso).
     *
     * @param  \App\Models\User  $dueno
     * @param  int  $tarea_id
     * @param  \Carbon\Carbon  $ahora
     * @return int|null
     */
    protected static function despertar_una(User $dueno, int $tarea_id, Carbon $ahora)
    {
        /* La fila bloqueada: dos procesos no la reclaman a la vez, y se re-mira todo con ella en la mano. */
        $tarea = AiTareaDelAsistente::where('owner_id', (int) $dueno->id)->where('id', $tarea_id)->lockForUpdate()->first();

        if (is_null($tarea)
            || $tarea->estado !== AiTareaDelAsistente::ESTADO_ACTIVA
            || is_null($tarea->proxima_ejecucion_at)
            || $tarea->proxima_ejecucion_at->copy()->setTimezone(ProximaEjecucionIaHelper::ZONA)->gt($ahora->copy()->addMinutes(self::ANTICIPO_MINUTOS))) {

            return null;
        }

        $ocurrencia = Carbon::parse((string) $tarea->getRawOriginal('proxima_ejecucion_at'), ProximaEjecucionIaHelper::ZONA);

        /* Una ocurrencia de un servidor caído no se pone al día: se omite y la tarea sigue con la próxima futura. */
        if ($ocurrencia->lt($ahora->copy()->subHours(self::HORAS_DE_VENCIDA))) {

            self::omitir($tarea, $ocurrencia, AiTareaDelAsistenteEjecucion::MOTIVO_VENCIDA, $ahora);

            return null;
        }

        /* Topes: los turnos programados del día y el tope de interacciones del plan. */
        if (self::ejecuciones_de_hoy($dueno, $ahora) >= self::MAX_EJECUCIONES_POR_DIA) {

            self::omitir($tarea, $ocurrencia, AiTareaDelAsistenteEjecucion::MOTIVO_TOPE_DIARIO, $ahora);

            return null;
        }

        $estado_del_plan = TopeDeTokensHelper::estado($dueno);

        if (!empty($estado_del_plan['supero'])) {

            self::omitir($tarea, $ocurrencia, AiTareaDelAsistenteEjecucion::MOTIVO_TOPE_DEL_PLAN, $ahora);

            return null;
        }

        /* No se interrumpe al dueño que está escribiendo (pero tampoco se espera más de una hora). Va después de los topes: si igual se iba a omitir, no hay nada que esperar. */
        if ($ocurrencia->copy()->addMinutes(self::MINUTOS_MAXIMOS_DE_ESPERA)->gt($ahora) && self::el_dueno_esta_escribiendo($dueno, $ahora)) {

            return null;
        }

        /* El reclamo: el unique (tarea_id, ocurrencia_at) lo gana un solo proceso. */
        try {

            $ejecucion = AiTareaDelAsistenteEjecucion::create([
                'tarea_id'      => (int) $tarea->id,
                'owner_id'      => (int) $dueno->id,
                'ocurrencia_at' => $ocurrencia->format('Y-m-d H:i:s'),
                'estado'        => AiTareaDelAsistenteEjecucion::ESTADO_EN_CURSO,
            ]);

        } catch (QueryException $e) {

            /*
             * 🔴 Sólo el duplicado del unique (tarea_id, ocurrencia_at) significa «otro proceso ya la despertó».
             * Cualquier otro error de base (un timeout de lock, una base que se cae a mitad del barrido) NO puede
             * correr la tarea a su próxima en silencio: la ocurrencia se perdería sin turno ni aviso. Se vuelve a
             * lanzar: despertar() lo registra con Log y la tarea queda como estaba, pendiente para el minuto
             * siguiente (la transacción deshace lo que se hubiera escrito).
             */
            if (!self::es_duplicado_del_unique($e)) {

                throw $e;
            }

            /* Otro proceso ya la despertó con una fila vieja: acá sólo se corre la tarea a su próxima. */
            self::avanzar($tarea, $ocurrencia, $ahora, false);

            return null;
        }

        $conversacion = AsistenteCanalHelper::conversacion_vigente($dueno, true);

        if (is_null($conversacion)) {

            $conversacion = AiConversation::create([
                'user_id'      => (int) $dueno->id,
                'auth_user_id' => (int) $dueno->id,
                'origen'       => AiConversation::ORIGEN_WHATSAPP,
                'titulo'       => mb_substr('Tarea: ' . $tarea->titulo, 0, 150),
            ]);
        }

        /* El pedido sintético: lo escribió el reloj, no el dueño (tipo `tarea_programada`). */
        AiMessage::create([
            'ai_conversation_id' => (int) $conversacion->id,
            'rol'                => 'user',
            'tipo'               => AiMessage::TIPO_TAREA_PROGRAMADA,
            'contenido'          => '[Tarea programada] ' . $tarea->titulo . ': ' . $tarea->instruccion,
            'estado'             => 'listo',
            'canal'              => AiMessage::CANAL_WHATSAPP,
        ]);

        /* La respuesta pendiente, SIN acciones: el turno desatendido no carga, no confirma ni guarda nada. */
        $assistant = AiMessage::create([
            'ai_conversation_id'   => (int) $conversacion->id,
            'rol'                  => 'assistant',
            'contenido'            => null,
            'estado'               => 'pendiente',
            'canal'                => AiMessage::CANAL_WHATSAPP,
            'acciones_habilitadas' => false,
        ]);

        $ejecucion->ai_conversation_id = (int) $conversacion->id;
        $ejecucion->ai_message_id = (int) $assistant->id;
        $ejecucion->save();

        $conversacion->last_message_at = $ahora;
        $conversacion->save();

        self::avanzar($tarea, $ocurrencia, $ahora, true);

        return (int) $assistant->id;
    }

    /**
     * true si el error de base es el duplicado de una clave única (MySQL/MariaDB 1062, SQLSTATE 23000): el
     * unique (tarea_id, ocurrencia_at) que garantiza que una ocurrencia corre una sola vez.
     *
     * @param  \Illuminate\Database\QueryException  $e
     * @return bool
     */
    protected static function es_duplicado_del_unique(QueryException $e): bool
    {
        $info = is_array($e->errorInfo) ? $e->errorInfo : [];

        if (isset($info[1]) && (int) $info[1] === 1062) {

            return true;
        }

        return (string) $e->getCode() === '23000' && stripos($e->getMessage(), 'duplicate') !== false;
    }

    /**
     * Despacha el job que responde el turno. Si no se puede encolar, el turno queda cerrado como fallido en vez
     * de colgado.
     *
     * @param  int  $assistant_id
     * @return void
     */
    protected static function despachar(int $assistant_id)
    {
        try {

            /*
             * Enmienda 1 (E3): por la MISMA cola que los demás turnos de WhatsApp (AdminSync/AsistenteController).
             * En hosting compartido es la cola propia del asistente: en `default`, compartida con las
             * importaciones, el turno esperaría y el vigía lo daría por colgado a los 10 minutos. Con el
             * interruptor apagado o en el VPS la cola es null y cae a `default`, como el resto.
             */
            ResponderMensajeChatIaJob::dispatch($assistant_id)->onQueue(AsistenteCanalHelper::cola_del_turno_whatsapp());

        } catch (\Throwable $e) {

            Log::warning('DespertadorDeTareasIaHelper: no se pudo encolar el turno ' . $assistant_id, ['error' => $e->getMessage()]);

            AiMessage::where('id', $assistant_id)->update(['estado' => 'error', 'error_mensaje' => mb_substr('no se pudo encolar: ' . $e->getMessage(), 0, 5000)]);
        }
    }

    /**
     * Deja una ejecución `omitida` (sin turno) y avanza la tarea.
     *
     * @param  \App\Models\AiTareaDelAsistente  $tarea
     * @param  \Carbon\Carbon  $ocurrencia
     * @param  string  $motivo
     * @param  \Carbon\Carbon  $ahora
     * @return void
     */
    protected static function omitir(AiTareaDelAsistente $tarea, Carbon $ocurrencia, $motivo, Carbon $ahora)
    {
        try {

            AiTareaDelAsistenteEjecucion::create([
                'tarea_id'      => (int) $tarea->id,
                'owner_id'      => (int) $tarea->owner_id,
                'ocurrencia_at' => $ocurrencia->format('Y-m-d H:i:s'),
                'estado'        => AiTareaDelAsistenteEjecucion::ESTADO_OMITIDA,
                'motivo'        => $motivo,
            ]);

        } catch (QueryException $e) {

            /* Un error que no es el duplicado no se traga (ver el 🔴 de despertar_una): sube y la tarea no avanza. */
            if (!self::es_duplicado_del_unique($e)) {

                throw $e;
            }

            /* Ya había una fila de esa ocurrencia (otro proceso): sólo se avanza. */
        }

        $tarea->ultimo_resultado = 'omitida:' . $motivo;

        self::avanzar($tarea, $ocurrencia, $ahora, false);
    }

    /**
     * Corre la tarea a su próxima ocurrencia (o la termina) y, si fue una corrida de verdad, la cuenta.
     *
     * @param  \App\Models\AiTareaDelAsistente  $tarea  Con la fila bloqueada.
     * @param  \Carbon\Carbon  $ocurrencia  La que se acaba de despertar u omitir.
     * @param  \Carbon\Carbon  $ahora
     * @param  bool  $corrio  true si se armó un turno (suma una ejecución y marca la última).
     * @return void
     */
    protected static function avanzar(AiTareaDelAsistente $tarea, Carbon $ocurrencia, Carbon $ahora, $corrio)
    {
        /* La siguiente tiene que ser posterior a la que se despertó Y a «ahora» (una omitida por vencida salta lo viejo). */
        $despues_de = $ocurrencia->gt($ahora) ? $ocurrencia : $ahora;

        $dias_sin_mensajes = [];

        $dueno = User::find((int) $tarea->owner_id);

        if (!is_null($dueno)) {

            $dias_sin_mensajes = TareasDelAsistenteIaHelper::horarios_del_dueno($dueno)['dias_sin_mensajes'];
        }

        $siguiente = ProximaEjecucionIaHelper::siguiente($tarea, $despues_de, $dias_sin_mensajes);

        if (is_null($siguiente)) {

            $tarea->estado = AiTareaDelAsistente::ESTADO_TERMINADA;
            $tarea->proxima_ejecucion_at = null;

        } else {

            $tarea->proxima_ejecucion_at = $siguiente->format('Y-m-d H:i:s');
        }

        if ($corrio) {

            $tarea->ultima_ejecucion_at = $ocurrencia->format('Y-m-d H:i:s');
            $tarea->ejecuciones = (int) $tarea->ejecuciones + 1;
        }

        $tarea->save();
    }

    /**
     * true si el DUEÑO escribió él mismo hace menos de MINUTOS_SIN_INTERRUMPIR minutos.
     *
     * 🔴 SE MIDEN LOS MENSAJES `user` DEL DUEÑO, no `last_message_at` de la conversación (enmienda 1, E2). Ese reloj
     * lo mueve todo: el propio despertar (al armar el turno de una tarea), los recordatorios, los avisos, las
     * sugerencias y el MCP. Medirlo hacía que una tarea frenara a la que vencía a su misma hora (la segunda salía
     * ~10 minutos después y MAX_POR_BARRIDO quedaba en 1). Acá cuenta sólo lo que escribió la persona en una
     * conversación suya, y los mensajes sintéticos (tipo `tarea_programada`) no son de la persona. La tarea se
     * reintenta cada minuto hasta MINUTOS_MAXIMOS_DE_ESPERA después de su hora.
     *
     * @param  \App\Models\User  $dueno
     * @param  \Carbon\Carbon  $ahora
     * @return bool
     */
    protected static function el_dueno_esta_escribiendo(User $dueno, Carbon $ahora): bool
    {
        $desde = $ahora->copy()->subMinutes(self::MINUTOS_SIN_INTERRUMPIR)->format('Y-m-d H:i:s');

        return AiMessage::where('rol', 'user')
            ->where(function ($q) {
                $q->whereNull('tipo')->orWhere('tipo', '<>', AiMessage::TIPO_TAREA_PROGRAMADA);
            })
            ->where('created_at', '>', $desde)
            ->whereIn('ai_conversation_id', function ($q) use ($dueno, $desde) {
                /*
                 * Sólo conversaciones suyas con movimiento reciente: es un prefiltro barato (toda escritura de un
                 * mensaje del dueño mueve este reloj, así que no descarta ninguno real) que evita revisar los
                 * mensajes de todo el historial. NO decide por sí solo: lo que decide es el mensaje del dueño.
                 */
                $q->select('id')
                    ->from('ai_conversations')
                    ->where('user_id', (int) $dueno->id)
                    ->where('auth_user_id', (int) $dueno->id)
                    ->where('last_message_at', '>', $desde);
            })
            ->exists();
    }

    /**
     * Cuántas ejecuciones no omitidas arrancaron hoy (de 00:00 de Argentina en adelante).
     *
     * @param  \App\Models\User  $dueno
     * @param  \Carbon\Carbon  $ahora
     * @return int
     */
    protected static function ejecuciones_de_hoy(User $dueno, Carbon $ahora): int
    {
        return AiTareaDelAsistenteEjecucion::where('owner_id', (int) $dueno->id)
            ->where('estado', '<>', AiTareaDelAsistenteEjecucion::ESTADO_OMITIDA)
            ->where('created_at', '>=', $ahora->copy()->startOfDay()->format('Y-m-d H:i:s'))
            ->count();
    }

    /* ─────────────────────────────── cerrar ─────────────────────────────── */

    /**
     * Cierra los turnos que despertó: `listo` → ejecución `lista` con su texto; `error` o colgado → `fallida`
     * (tres seguidas pausan la tarea); y las `lista` que nadie entregó en HORAS_DE_GRACIA pasan a `no_entregada`.
     *
     * @param  \App\Models\User  $dueno
     * @return int  Cuántas ejecuciones cambiaron de estado.
     */
    public static function cerrar(User $dueno): int
    {
        $ahora = TareasDelAsistenteIaHelper::ahora();
        $cambiadas = 0;

        $en_curso = AiTareaDelAsistenteEjecucion::where('owner_id', (int) $dueno->id)
            ->where('estado', AiTareaDelAsistenteEjecucion::ESTADO_EN_CURSO)
            ->whereNotNull('ai_message_id')
            ->orderBy('id')
            ->limit(50)
            ->get();

        foreach ($en_curso as $ejecucion) {

            $assistant = AiMessage::find((int) $ejecucion->ai_message_id);

            if (!is_null($assistant) && $assistant->estado === 'listo' && trim((string) $assistant->contenido) !== '') {

                $ejecucion->estado = AiTareaDelAsistenteEjecucion::ESTADO_LISTA;
                $ejecucion->texto = self::recortar((string) $assistant->contenido);
                $ejecucion->save();

                self::resultado_de_la_tarea($ejecucion, 'lista', true);

                $cambiadas++;

            } elseif (is_null($assistant) || in_array($assistant->estado, ['error', 'listo'], true)) {

                self::fallar($ejecucion, AiTareaDelAsistenteEjecucion::MOTIVO_ERROR);

                $cambiadas++;

            } elseif ($assistant->estado === 'pendiente' && !is_null($assistant->created_at)
                && Carbon::parse((string) $assistant->getRawOriginal('created_at'))->lt($ahora->copy()->subMinutes(self::MINUTOS_DE_COLGADA))) {

                self::fallar($ejecucion, AiTareaDelAsistenteEjecucion::MOTIVO_COLGADA);

                $cambiadas++;
            }
        }

        /* Las listas que nadie entregó a tiempo: el resultado sigue en la conversación, ya no se ofrece. */
        $vencidas = AiTareaDelAsistenteEjecucion::where('owner_id', (int) $dueno->id)
            ->where('estado', AiTareaDelAsistenteEjecucion::ESTADO_LISTA)
            ->where('updated_at', '<=', $ahora->copy()->subHours(RecordatoriosDelAsistenteHelper::HORAS_DE_GRACIA)->format('Y-m-d H:i:s'))
            ->get();

        foreach ($vencidas as $ejecucion) {

            $ejecucion->estado = AiTareaDelAsistenteEjecucion::ESTADO_NO_ENTREGADA;
            $ejecucion->save();

            self::resultado_de_la_tarea($ejecucion, 'no_entregada', false);

            $cambiadas++;
        }

        return $cambiadas;
    }

    /**
     * true si hay algo por cerrar (turnos en curso, o listas que ya pasaron su gracia): el gate del Kernel.
     *
     * @param  \App\Models\User  $dueno
     * @return bool
     */
    public static function hay_para_cerrar(User $dueno): bool
    {
        $ahora = TareasDelAsistenteIaHelper::ahora();

        return AiTareaDelAsistenteEjecucion::where('owner_id', (int) $dueno->id)
            ->where(function ($q) use ($ahora) {
                $q->where('estado', AiTareaDelAsistenteEjecucion::ESTADO_EN_CURSO)
                    ->orWhere(function ($q2) use ($ahora) {
                        $q2->where('estado', AiTareaDelAsistenteEjecucion::ESTADO_LISTA)
                            ->where('updated_at', '<=', $ahora->copy()->subHours(RecordatoriosDelAsistenteHelper::HORAS_DE_GRACIA)->format('Y-m-d H:i:s'));
                    });
            })
            ->exists();
    }

    /**
     * true si hay una tarea activa que vence dentro del anticipo: el gate del Kernel.
     *
     * @param  \App\Models\User  $dueno
     * @return bool
     */
    public static function hay_para_despertar(User $dueno): bool
    {
        return AiTareaDelAsistente::where('owner_id', (int) $dueno->id)
            ->where('estado', AiTareaDelAsistente::ESTADO_ACTIVA)
            ->whereNotNull('proxima_ejecucion_at')
            ->where('proxima_ejecucion_at', '<=', TareasDelAsistenteIaHelper::ahora()->addMinutes(self::ANTICIPO_MINUTOS)->format('Y-m-d H:i:s'))
            ->exists();
    }

    /**
     * Marca la ejecución como fallida, suma el fallo a la tarea y la pausa al tercero seguido.
     *
     * @param  \App\Models\AiTareaDelAsistenteEjecucion  $ejecucion
     * @param  string  $motivo
     * @return void
     */
    protected static function fallar(AiTareaDelAsistenteEjecucion $ejecucion, $motivo)
    {
        $ejecucion->estado = AiTareaDelAsistenteEjecucion::ESTADO_FALLIDA;
        $ejecucion->motivo = $motivo;
        $ejecucion->save();

        self::resultado_de_la_tarea($ejecucion, 'fallida', false, true);
    }

    /**
     * Refleja en la tarea cómo salió la última ejecución: el resultado, y los fallos seguidos (una lista los
     * pone en cero; una fallida suma, y con MAX_FALLOS la tarea queda pausada).
     *
     * @param  \App\Models\AiTareaDelAsistenteEjecucion  $ejecucion
     * @param  string  $resultado
     * @param  bool  $reinicia_fallos
     * @param  bool  $suma_fallo
     * @return void
     */
    protected static function resultado_de_la_tarea(AiTareaDelAsistenteEjecucion $ejecucion, $resultado, $reinicia_fallos, $suma_fallo = false)
    {
        $tarea = AiTareaDelAsistente::where('owner_id', (int) $ejecucion->owner_id)->where('id', (int) $ejecucion->tarea_id)->first();

        if (is_null($tarea)) {

            return;
        }

        $tarea->ultimo_resultado = $resultado;

        if ($reinicia_fallos) {

            $tarea->fallos_seguidos = 0;
        }

        if ($suma_fallo) {

            $tarea->fallos_seguidos = (int) $tarea->fallos_seguidos + 1;

            if ($tarea->fallos_seguidos >= self::MAX_FALLOS && $tarea->estado === AiTareaDelAsistente::ESTADO_ACTIVA) {

                $tarea->estado = AiTareaDelAsistente::ESTADO_PAUSADA;
                $tarea->proxima_ejecucion_at = null;
                $tarea->ultimo_resultado = 'fallida: pausada por ' . self::MAX_FALLOS . ' fallos seguidos';
            }
        }

        $tarea->save();
    }

    /**
     * El texto recortado al tope del contrato, con el aviso de que sigue en la app.
     *
     * @param  string  $texto
     * @return string
     */
    protected static function recortar($texto): string
    {
        $texto = trim($texto);

        if (mb_strlen($texto) <= self::LARGO_MAXIMO_DEL_TEXTO) {

            return $texto;
        }

        return rtrim(mb_substr($texto, 0, self::LARGO_MAXIMO_DEL_TEXTO - mb_strlen(self::SUFIJO_RECORTADO))) . self::SUFIJO_RECORTADO;
    }

    /* ─────────────────────────────── turno desatendido ─────────────────────────────── */

    /**
     * true si el turno lo disparó una tarea programada: el mensaje del dueño inmediatamente anterior al
     * `assistant` (o, sin `assistant`, el último de la conversación) es de tipo `tarea_programada`.
     *
     * @param  \App\Models\AiConversation|null  $conversation
     * @param  \App\Models\AiMessage|null  $assistant
     * @return bool
     */
    public static function es_turno_desatendido($conversation, $assistant = null): bool
    {
        if (!($conversation instanceof AiConversation)) {

            return false;
        }

        $consulta = AiMessage::where('ai_conversation_id', (int) $conversation->id)->where('rol', 'user');

        if ($assistant instanceof AiMessage && !empty($assistant->id)) {

            $consulta->where('id', '<', (int) $assistant->id);
        }

        $pedido = $consulta->orderBy('id', 'DESC')->first();

        return !is_null($pedido) && $pedido->tipo === AiMessage::TIPO_TAREA_PROGRAMADA;
    }

    /**
     * Cuál es la tarea que disparó el turno desatendido de la conversación: su título y la hora a la que estaba
     * programada, para el bloque que le explica al modelo por qué lo despertaron. Si no encuentra la ejecución
     * (un turno armado a mano), un título genérico y la hora de ahora.
     *
     * 🔴 La ejecución se identifica por el `assistant` DEL TURNO (enmienda 1, E2): con dos tareas a la misma hora
     * puede haber dos ejecuciones en curso en la misma conversación, y «la última en curso» le habría puesto a
     * cada turno el título de la otra. Sin el id del turno (quien no lo conoce) cae a la última en curso.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  int|null  $assistant_id  El `assistant` del turno que se está respondiendo.
     * @return array{titulo: string, hora: string}
     */
    public static function contexto_del_turno(AiConversation $conversation, $assistant_id = null): array
    {
        $consulta = AiTareaDelAsistenteEjecucion::where('ai_conversation_id', (int) $conversation->id)
            ->where('estado', AiTareaDelAsistenteEjecucion::ESTADO_EN_CURSO);

        if (!empty($assistant_id)) {

            $consulta->where('ai_message_id', (int) $assistant_id);
        }

        $ejecucion = $consulta->orderBy('id', 'DESC')->first();

        $tarea = is_null($ejecucion) ? null : AiTareaDelAsistente::where('id', (int) $ejecucion->tarea_id)->first();

        if (is_null($ejecucion) || is_null($tarea)) {

            return ['titulo' => 'programada', 'hora' => TareasDelAsistenteIaHelper::ahora()->format('H:i')];
        }

        return [
            'titulo' => (string) $tarea->titulo,
            'hora'   => Carbon::parse((string) $ejecucion->getRawOriginal('ocurrencia_at'))->format('H:i'),
        ];
    }

    /* ─────────────────────────────── entrega por el carril de recordatorios ─────────────────────────────── */

    /**
     * Las ejecuciones `lista` que todavía se pueden entregar (menos de HORAS_DE_GRACIA desde que quedaron
     * listas), con la forma de un ítem de `recordatorios-pendientes`. Las claves que lee el admin de hoy más dos
     * opcionales (`plantilla`, `resumen`) que sólo usa el admin nuevo.
     *
     * @param  \App\Models\User  $dueno
     * @return array<int, array{item: array<string,mixed>, momento: \Carbon\Carbon}>
     */
    public static function items_de_entrega(User $dueno): array
    {
        if (!TareasDelAsistenteIaHelper::hay_tablas()) {

            return [];
        }

        $ahora = TareasDelAsistenteIaHelper::ahora();

        $ejecuciones = DB::table('ai_tareas_del_asistente_ejecuciones')
            ->where('owner_id', (int) $dueno->id)
            ->where('estado', AiTareaDelAsistenteEjecucion::ESTADO_LISTA)
            ->whereNotNull('texto')
            ->where('updated_at', '>', $ahora->copy()->subHours(RecordatoriosDelAsistenteHelper::HORAS_DE_GRACIA)->format('Y-m-d H:i:s'))
            ->orderBy('ocurrencia_at')
            ->orderBy('id')
            ->limit(RecordatoriosDelAsistenteHelper::TOPE_RECORDATORIOS)
            ->get();

        if (!count($ejecuciones)) {

            return [];
        }

        $titulos = AiTareaDelAsistente::where('owner_id', (int) $dueno->id)
            ->whereIn('id', $ejecuciones->pluck('tarea_id')->all())
            ->pluck('titulo', 'id')
            ->all();

        $items = [];

        foreach ($ejecuciones as $ejecucion) {

            $texto = trim((string) $ejecucion->texto);

            if ($texto === '') {

                continue;
            }

            $ocurrencia = Carbon::parse((string) $ejecucion->ocurrencia_at, ProximaEjecucionIaHelper::ZONA);
            $titulo = isset($titulos[$ejecucion->tarea_id]) ? mb_substr(trim((string) $titulos[$ejecucion->tarea_id]), 0, self::LARGO_TITULO_EN_EL_ITEM) : 'tu asistente';

            $items[] = [
                'momento' => $ocurrencia,
                'item'    => [
                    /* Con el created_at adentro, no sólo el id: el reseteo de una demo vuelve el autoincremental a 1. */
                    'id'                 => self::PREFIJO_ID . (int) $ejecucion->id . '-' . Carbon::parse((string) $ejecucion->created_at)->timestamp,
                    'tarea_id'           => null,
                    'fecha'              => $ocurrencia->format('Y-m-d'),
                    'hora'               => $ocurrencia->format('H:i'),
                    'detalle'            => 'que hoy te escriba por «' . $titulo . '». Ya te dejé el mensaje: respondeme por acá y seguimos',
                    'texto'              => $texto,
                    'ai_conversation_id' => is_null($ejecucion->ai_conversation_id) ? null : (int) $ejecucion->ai_conversation_id,
                    'ruta'               => '',
                    'plantilla'          => 'tarea_del_asistente',
                    'resumen'            => '«' . $titulo . '»',
                ],
            ];
        }

        return $items;
    }

    /**
     * true si el id del contrato es de una ejecución de tarea del asistente.
     *
     * @param  mixed  $id
     * @return bool
     */
    public static function es_id_propio($id): bool
    {
        return is_string($id) && strpos($id, self::PREFIJO_ID) === 0;
    }

    /**
     * Sella la ejecución como entregada (UNA vez: un segundo sello no cambia nada) y le pasa el wamid al
     * mensaje del turno. No crea otro mensaje: el resultado ya está en la conversación.
     *
     * @param  \App\Models\User  $dueno
     * @param  mixed  $id  El `id` del contrato.
     * @param  mixed  $whatsapp_message_id
     * @return bool  false (el 404 del contrato) si el id no parsea o no es de este dueño.
     */
    public static function marcar(User $dueno, $id, $whatsapp_message_id = null): bool
    {
        if (!self::es_id_propio($id) || !TareasDelAsistenteIaHelper::hay_tablas()
            || !preg_match('/^(\d{1,12})-(\d{1,12})$/', substr($id, strlen(self::PREFIJO_ID)), $partes)) {

            return false;
        }

        return DB::transaction(function () use ($dueno, $partes, $whatsapp_message_id) {

            /* Siempre dentro del dueño: el número solo no alcanza para saber de quién es. */
            $ejecucion = AiTareaDelAsistenteEjecucion::where('owner_id', (int) $dueno->id)
                ->where('id', (int) $partes[1])
                ->lockForUpdate()
                ->first();

            if (is_null($ejecucion) || (string) Carbon::parse((string) $ejecucion->getRawOriginal('created_at'))->timestamp !== (string) $partes[2]) {

                return false;
            }

            /* Idempotente: un segundo sello (el admin reintenta) no pisa nada. */
            if ($ejecucion->estado === AiTareaDelAsistenteEjecucion::ESTADO_ENTREGADA) {

                return true;
            }

            /* Salió a último momento y el barrido la había dado por no entregada: igual cuenta como entregada. */
            if (!in_array($ejecucion->estado, [AiTareaDelAsistenteEjecucion::ESTADO_LISTA, AiTareaDelAsistenteEjecucion::ESTADO_NO_ENTREGADA], true)) {

                return false;
            }

            $wamid = trim((string) $whatsapp_message_id);
            $wamid = $wamid === '' ? null : mb_substr($wamid, 0, 128);

            $ejecucion->estado = AiTareaDelAsistenteEjecucion::ESTADO_ENTREGADA;
            $ejecucion->entregada_at = TareasDelAsistenteIaHelper::ahora();
            $ejecucion->whatsapp_message_id = $wamid;
            $ejecucion->save();

            if (!is_null($wamid) && !is_null($ejecucion->ai_message_id)) {

                AiMessage::where('id', (int) $ejecucion->ai_message_id)->whereNull('whatsapp_message_id')->update(['whatsapp_message_id' => $wamid]);
            }

            self::resultado_de_la_tarea($ejecucion, 'entregada', false);

            return true;
        });
    }
}
