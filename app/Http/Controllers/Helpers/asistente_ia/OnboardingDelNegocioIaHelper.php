<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\AgentesIaHelper;
use App\Models\AiConocimientoDelNegocio;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiTareaDelAsistente;
use App\Models\AiTareaDelAsistenteEjecucion;
use App\Models\Article;
use App\Models\Client;
use App\Models\Provider;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeTareasDelAsistente;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * El onboarding conversacional por WhatsApp (misión onboarding-conversacional-whatsapp, 23-24/11/2026):
 * cuando el dueño le escribe por PRIMERA vez a su asistente personal, el asistente le hace —de a una pregunta, en
 * lenguaje natural— las 32 preguntas sobre su negocio (ConocimientoDelNegocioIaHelper), recuerda en cuál quedó
 * (cursor persistido), la retoma aunque pasen días y lo vuelve a buscar con recordatorios espaciados y acotados
 * hasta que contesta todo o el dueño dice que no quiere más.
 *
 * Ciclo de vida, en `users.ia_onboarding_estado` (null = nunca empezó, que es como están hoy todos los clientes):
 *   en_curso → pausado_sin_respuesta (3 recordatorios sin respuesta; vuelve a en_curso cuando el dueño escribe)
 *            → pausado_por_el_dueno («no me preguntes más»; sólo vuelve con `retomar`)
 *            → completo (los 32 temas en sabido|omitido: se cancela la tarea y no hay más recordatorios).
 *
 * El seguimiento NO es un cron propio: es UNA tarea del motor de tareas del asistente (área `onboarding`,
 * `TareasDelAsistenteIaHelper::programar`, sin tocarlo). Escalera 1, 3 y 7 días a la tarde, tope de 3 sin
 * respuesta, nunca dos tareas de onboarding activas ni más de un recordatorio por día.
 *
 * 🔴 Nada de acá rompe un turno: el gancho de arranque (al_empezar_el_turno) captura todo y sólo loguea.
 *
 * PHP 7.4: sin match, sin nullsafe, sin str_contains.
 */
class OnboardingDelNegocioIaHelper
{
    const EN_CURSO = 'en_curso';

    const PAUSADO_SIN_RESPUESTA = 'pausado_sin_respuesta';

    const PAUSADO_POR_EL_DUENO = 'pausado_por_el_dueno';

    const COMPLETO = 'completo';

    const ORIGEN_WHATSAPP = 'whatsapp';

    const ORIGEN_PEDIDO = 'pedido';

    /** Área de las tareas del motor que son recordatorios de onboarding. */
    const AREA = 'onboarding';

    /** Es el {{1}} de la plantilla `cc_tarea_del_asistente` cuando la ventana de WhatsApp está cerrada. */
    const TITULO = 'unas preguntas más para conocer tu negocio';

    /** Días entre el último movimiento y el recordatorio siguiente, según cuántos van sin respuesta. */
    const ESCALERA = [1, 3, 7];

    /** Recordatorios sin respuesta después de los cuales se pausa hasta que el dueño vuelva a escribir. */
    const TOPE_SIN_RESPUESTA = 3;

    /** Largo máximo del bloque que viaja al modelo. */
    const LARGO_MAXIMO_DEL_BLOQUE = 2500;

    /**
     * Cuántos temas faltantes se le muestran al modelo CON su destino y su pregunta, como máximo (los próximos en
     * orden). Las claves de TODOS los que faltan viajan aparte, en una línea: el modelo sólo puede marcar lo que ve
     * (demo3: con 5 visibles marcaba 5 por mensaje aunque el dueño contara 15).
     */
    const FALTANTES_EN_EL_BLOQUE = 5;

    /** Lo que hace el asistente cuando lo despierta el recordatorio (≤ 1000 caracteres: lo valida el motor). */
    const INSTRUCCION = 'Retomá la charla para conocer el negocio del dueño: saludalo corto, recordale en una línea lo último que te contó (está en <onboarding_del_negocio>) y hacé UNA sola pregunta de los temas que faltan (están en <onboarding_del_negocio>). Recordale que puede contestarte con un audio, contándolo como le salga. Si hoy no puede, que te diga cuándo.';

    /* ─────────────────────────────── lectura ─────────────────────────────── */

    /**
     * true si el onboarding se puede usar con este dueño: tiene Agentes de IA y la base tiene las tablas.
     *
     * @param  \App\Models\User|null  $dueno
     * @return bool
     */
    public static function aplica($dueno): bool
    {
        return $dueno instanceof User
            && AgentesIaHelper::tiene($dueno)
            && ConocimientoDelNegocioIaHelper::hay_tablas()
            && TareasDelAsistenteIaHelper::hay_tablas();
    }

    /**
     * Las columnas del onboarding del dueño, leídas frescas de la base (nunca del modelo, que puede venir viejo).
     *
     * @param  \App\Models\User  $dueno
     * @return object|null
     */
    protected static function fila(User $dueno)
    {
        return DB::table('users')->where('id', (int) $dueno->id)->first();
    }

    /**
     * El estado del ciclo de vida (null = no empezó).
     *
     * @param  \App\Models\User  $dueno
     * @return string|null
     */
    public static function estado_de(User $dueno)
    {
        $fila = self::fila($dueno);

        return is_null($fila) || empty($fila->ia_onboarding_estado) ? null : (string) $fila->ia_onboarding_estado;
    }

    /**
     * La pregunta en curso: la primera clave que falta, en el orden en que se pregunta (esenciales primero).
     * null si no falta ninguna.
     *
     * @param  \App\Models\User  $dueno
     * @return string|null
     */
    public static function cursor(User $dueno)
    {
        $temas = ConocimientoDelNegocioIaHelper::estado($dueno)['temas'];

        foreach (ConocimientoDelNegocioIaHelper::orden_de_preguntas() as $clave) {

            if ($temas[$clave]['estado'] === ConocimientoDelNegocioIaHelper::FALTA) {

                return $clave;
            }
        }

        return null;
    }

    /* ─────────────────────────────── gancho del turno ─────────────────────────────── */

    /**
     * Lo primero que pasa al empezar un turno del asistente: decide si arranca el onboarding, lo retoma o agenda
     * el seguimiento. Devuelve true si ESTE es el primer turno del onboarding (el bloque lleva la línea de
     * presentación).
     *
     * Arranca solo si: Agentes de IA ∧ mensaje del dueño por WhatsApp ∧ turno atendido ∧ onboarding sin empezar ∧
     * es el primer mensaje del dueño por WhatsApp de toda su historia. Un cliente con historial por WhatsApp no
     * arranca solo (un flujo que ya usa no cambia): sólo si lo pide con `conocimiento_del_negocio empezar`.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage  $assistant_message  El `assistant` del turno.
     * @param  \App\Models\User|null  $owner
     * @return bool
     */
    public static function al_empezar_el_turno(AiConversation $conversation, AiMessage $assistant_message, $owner): bool
    {
        try {

            if (!self::aplica($owner) || !HerramientasDeTareasDelAsistente::habla_el_dueno($conversation)) {

                return false;
            }

            /* Turno desatendido: lo despertó una tarea. Si era la de onboarding, ESTE turno agenda el siguiente recordatorio. */
            if (DespertadorDeTareasIaHelper::es_turno_desatendido($conversation, $assistant_message)) {

                if (self::es_turno_de_onboarding($conversation, $assistant_message->id) && self::estado_de($owner) === self::EN_CURSO) {

                    self::agendar_seguimiento($owner, $conversation->id);
                }

                return false;
            }

            $canal = (string) $assistant_message->canal;

            if ($canal !== AiMessage::CANAL_WHATSAPP && $canal !== AiMessage::CANAL_APP) {

                return false;
            }

            $estado = self::estado_de($owner);

            if (is_null($estado)) {

                if ($canal === AiMessage::CANAL_WHATSAPP && self::es_el_primer_mensaje_por_whatsapp($conversation, $assistant_message, $owner)) {

                    self::empezar($owner, self::ORIGEN_WHATSAPP, $conversation->id);

                    return true;
                }

                return false;
            }

            /* El dueño volvió a escribir: lo que estaba pausado por falta de respuesta se retoma (la escalera empieza de nuevo). */
            if ($estado === self::PAUSADO_SIN_RESPUESTA) {

                DB::table('users')->where('id', (int) $owner->id)->update(['ia_onboarding_estado' => self::EN_CURSO]);

                $estado = self::EN_CURSO;
            }

            if ($estado === self::EN_CURSO) {

                self::actualizar_cursor($owner);
                self::agendar_seguimiento($owner, $conversation->id);
            }

        } catch (\Throwable $e) {

            Log::warning('OnboardingDelNegocioIaHelper: el gancho del turno falló y se ignora', ['error' => $e->getMessage()]);
        }

        return false;
    }

    /**
     * true si el mensaje del dueño del turno es el primero de toda su historia por WhatsApp. No cuentan los
     * mensajes `tarea_programada` (no los escribió él).
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage  $assistant_message
     * @param  \App\Models\User  $owner
     * @return bool
     */
    protected static function es_el_primer_mensaje_por_whatsapp(AiConversation $conversation, AiMessage $assistant_message, User $owner): bool
    {
        $actual = AiMessage::where('ai_conversation_id', (int) $conversation->id)
            ->where('rol', 'user')
            ->where('id', '<', (int) $assistant_message->id)
            ->orderBy('id', 'desc')
            ->first();

        if (is_null($actual) || (string) $actual->canal !== AiMessage::CANAL_WHATSAPP || (string) $actual->tipo === AiMessage::TIPO_TAREA_PROGRAMADA) {

            return false;
        }

        $previo = AiMessage::where('rol', 'user')
            ->where('canal', AiMessage::CANAL_WHATSAPP)
            ->where('id', '<', (int) $actual->id)
            ->where(function ($consulta) {
                $consulta->whereNull('tipo')->orWhere('tipo', '!=', AiMessage::TIPO_TAREA_PROGRAMADA);
            })
            ->whereIn('ai_conversation_id', function ($consulta) use ($owner) {
                $consulta->select('id')->from('ai_conversations')->where('user_id', (int) $owner->id);
            })
            ->exists();

        return !$previo;
    }

    /**
     * true si el turno desatendido de la conversación lo despertó una tarea de onboarding.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  int|null  $assistant_id  El `assistant` del turno.
     * @return bool
     */
    public static function es_turno_de_onboarding(AiConversation $conversation, $assistant_id): bool
    {
        if (empty($assistant_id)) {

            return false;
        }

        $ejecucion = AiTareaDelAsistenteEjecucion::where('ai_conversation_id', (int) $conversation->id)
            ->where('ai_message_id', (int) $assistant_id)
            ->orderBy('id', 'desc')
            ->first();

        if (is_null($ejecucion)) {

            return false;
        }

        return AiTareaDelAsistente::where('id', (int) $ejecucion->tarea_id)->where('area', self::AREA)->exists();
    }

    /* ─────────────────────────────── ciclo de vida ─────────────────────────────── */

    /**
     * Empieza el onboarding: estado en_curso, siembra lo que ya contó en el formulario, fija la pregunta en curso
     * y agenda el primer recordatorio. Con el onboarding pausado lo retoma; ya en curso o completo no cambia nada.
     *
     * @param  \App\Models\User  $dueno
     * @param  string  $origen  `whatsapp` | `pedido`.
     * @param  int|null  $conversacion_id
     * @return array{ok: bool, estado: string, nota: string}
     */
    public static function empezar(User $dueno, $origen, $conversacion_id = null): array
    {
        $estado = self::estado_de($dueno);

        if ($estado === self::COMPLETO) {

            return ['ok' => false, 'estado' => $estado, 'nota' => 'El onboarding ya está completo: ya conocés todo lo que necesitabas del negocio. No hace falta empezarlo de nuevo.'];
        }

        if ($estado === self::EN_CURSO) {

            return ['ok' => true, 'estado' => $estado, 'nota' => 'El onboarding ya está en curso.'];
        }

        if (!is_null($estado)) {

            return self::retomar($dueno, $conversacion_id);
        }

        DB::table('users')->where('id', (int) $dueno->id)->update([
            'ia_onboarding_estado'      => self::EN_CURSO,
            'ia_onboarding_origen'      => $origen,
            'ia_onboarding_iniciado_at' => Carbon::now()->format('Y-m-d H:i:s'),
        ]);

        ConocimientoDelNegocioIaHelper::sembrar_desde_cuestionario($dueno);

        self::actualizar_cursor($dueno);
        self::marcar_esenciales($dueno);
        self::agendar_seguimiento($dueno, $conversacion_id);

        return ['ok' => true, 'estado' => self::EN_CURSO, 'nota' => 'Arrancó el onboarding: hacele de a una pregunta, empezando por la que dice <onboarding_del_negocio>.'];
    }

    /**
     * «No me preguntes más»: pausa y cancela los recordatorios. Sólo `retomar` lo vuelve a poner en marcha.
     *
     * @param  \App\Models\User  $dueno
     * @return array{ok: bool, estado: string, nota: string}
     */
    public static function pausar(User $dueno): array
    {
        $estado = self::estado_de($dueno);

        if (is_null($estado) || $estado === self::COMPLETO) {

            return ['ok' => false, 'estado' => (string) $estado, 'nota' => is_null($estado) ? 'El onboarding todavía no empezó: no hay nada que pausar.' : 'El onboarding ya está completo: no hay nada que pausar.'];
        }

        DB::table('users')->where('id', (int) $dueno->id)->update(['ia_onboarding_estado' => self::PAUSADO_POR_EL_DUENO]);

        self::cancelar_tareas($dueno);

        return ['ok' => true, 'estado' => self::PAUSADO_POR_EL_DUENO, 'nota' => 'Listo: no le vas a hacer más preguntas ni a mandarle recordatorios para esto. Si más adelante quiere retomarlas, usá accion "retomar".'];
    }

    /**
     * Retoma un onboarding pausado: vuelve a en_curso y agenda el recordatorio.
     *
     * @param  \App\Models\User  $dueno
     * @param  int|null  $conversacion_id
     * @return array{ok: bool, estado: string, nota: string}
     */
    public static function retomar(User $dueno, $conversacion_id = null): array
    {
        $estado = self::estado_de($dueno);

        if (is_null($estado)) {

            return self::empezar($dueno, self::ORIGEN_PEDIDO, $conversacion_id);
        }

        if ($estado === self::COMPLETO) {

            return ['ok' => false, 'estado' => $estado, 'nota' => 'El onboarding ya está completo: no hay nada que retomar.'];
        }

        if ($estado !== self::EN_CURSO) {

            DB::table('users')->where('id', (int) $dueno->id)->update(['ia_onboarding_estado' => self::EN_CURSO]);
        }

        self::actualizar_cursor($dueno);

        self::agendar_seguimiento($dueno, $conversacion_id);

        return ['ok' => true, 'estado' => self::EN_CURSO, 'nota' => 'Retomado: seguí con la pregunta que dice <onboarding_del_negocio>.'];
    }

    /**
     * Después de marcar temas: deja al día el cursor, los esenciales y el cierre. Devuelve lo que el modelo tiene
     * que saber («ya tenés lo esencial» / «con esto ya conocés todo, cerrá con un resumen»), o ''.
     *
     * @param  \App\Models\User  $dueno
     * @param  array  $resultado  El de ConocimientoDelNegocioIaHelper::marcar() (`cruzo_esenciales`, `quedo_completo`).
     * @return string
     */
    public static function al_marcar(User $dueno, array $resultado): string
    {
        if (!ConocimientoDelNegocioIaHelper::hay_tablas()) {

            return '';
        }

        self::marcar_esenciales($dueno);

        $estado = self::estado_de($dueno);

        if (!is_null($estado) && $estado !== self::COMPLETO) {

            self::actualizar_cursor($dueno);
        }

        if (!empty($resultado['quedo_completo'])) {

            DB::table('users')->where('id', (int) $dueno->id)->update([
                'ia_onboarding_estado'        => self::COMPLETO,
                'ia_onboarding_completado_at' => Carbon::now()->format('Y-m-d H:i:s'),
                'ia_onboarding_cursor'        => null,
                'ia_onboarding_cursor_desde'  => null,
            ]);

            self::cancelar_tareas($dueno);

            return 'Con esto ya conocés todo lo que necesitabas: cerrá con un resumen corto de lo que entendiste del negocio y preguntale si es así.';
        }

        /*
         * El progreso sale de las filas de la base, no de lo que el modelo cree: «Ya tenés lo esencial» sin el conteo
         * de lo que falta lo hizo creer que había terminado (demo3, msg 192). Antes de cruzar los 13 esenciales el
         * texto NO dice «lo esencial».
         */
        if (is_null($estado) || $estado === self::COMPLETO) {

            return '';
        }

        $aviso = self::linea_de_progreso($dueno);

        if (!empty($resultado['cruzo_esenciales'])) {

            $aviso = 'Ya tenés lo esencial, pero todavía no terminaste: no canceles nada y seguí con el resto, de a una. ' . $aviso;
        }

        return $aviso;
    }

    /**
     * El avance real del onboarding, leído de la base: cuántos temas llevan, cuántos esenciales y la lista (en el
     * orden en que se preguntan) de los que faltan.
     *
     * @param  \App\Models\User  $dueno
     * @return array{listos: int, total: int, esenciales_listos: int, esenciales_total: int, faltantes: array<int,string>}
     */
    public static function progreso(User $dueno): array
    {
        $conocimiento = ConocimientoDelNegocioIaHelper::estado($dueno);
        $cuentas = $conocimiento['cuentas'];

        $faltantes = [];

        foreach (ConocimientoDelNegocioIaHelper::orden_de_preguntas() as $clave) {

            if ($conocimiento['temas'][$clave]['estado'] === ConocimientoDelNegocioIaHelper::FALTA) {

                $faltantes[] = $clave;
            }
        }

        return [
            'listos'            => (int) $cuentas['total'] - count($faltantes),
            'total'             => (int) $cuentas['total'],
            'esenciales_listos' => (int) $cuentas['esenciales_listos'],
            'esenciales_total'  => (int) $cuentas['esenciales_total'],
            'faltantes'         => $faltantes,
        ];
    }

    /**
     * «Llevás X de 32 temas (E de 13 esenciales). Faltan: …» con los números de la base.
     *
     * @param  \App\Models\User  $dueno
     * @return string
     */
    public static function linea_de_progreso(User $dueno): string
    {
        $avance = self::progreso($dueno);

        return 'Llevás ' . $avance['listos'] . ' de ' . $avance['total'] . ' temas (' . $avance['esenciales_listos'] . ' de ' . $avance['esenciales_total'] . ' esenciales).'
            . (count($avance['faltantes']) ? ' Faltan: ' . implode(', ', $avance['faltantes']) . '.' : '')
            . ' Si en este mensaje te contó alguno, marcalo con temas.';
    }

    /**
     * El recordatorio del onboarding lo maneja el sistema: si el modelo pide cancelar una tarea de esa área, esta es la
     * nota con la que se le rechaza (el avance real y qué hacer según lo que pidió el dueño). null si la tarea no es
     * del onboarding de este dueño (cualquier otra se cancela como siempre).
     *
     * @param  \App\Models\User  $dueno
     * @param  mixed  $tarea_id
     * @return string|null
     */
    public static function nota_de_cancelacion(User $dueno, $tarea_id)
    {
        if (!is_numeric($tarea_id) || (int) $tarea_id <= 0) {

            return null;
        }

        $es_del_onboarding = AiTareaDelAsistente::where('owner_id', (int) $dueno->id)
            ->where('id', (int) $tarea_id)
            ->where('area', self::AREA)
            ->whereIn('estado', [AiTareaDelAsistente::ESTADO_ACTIVA, AiTareaDelAsistente::ESTADO_PAUSADA])
            ->exists();

        if (!$es_del_onboarding || self::estado_de($dueno) === self::COMPLETO) {

            return null;
        }

        $avance = self::progreso($dueno);

        return 'Ese es el recordatorio para conocer el negocio: lo maneja el sistema y se cancela solo cuando están los ' . $avance['total'] . ' temas. Llevás ' . $avance['listos'] . ' de ' . $avance['total']
            . (count($avance['faltantes']) ? ' (faltan: ' . implode(', ', $avance['faltantes']) . ')' : '')
            . '. Si el dueño pidió que no le preguntes más, usá conocimiento_del_negocio accion "pausar"; si pidió otro día, reprogramalo con reprogramar_tarea_del_asistente.';
    }

    /**
     * Graba cuándo se completaron los 13 esenciales (una sola vez).
     *
     * @param  \App\Models\User  $dueno
     * @return void
     */
    protected static function marcar_esenciales(User $dueno)
    {
        $cuentas = ConocimientoDelNegocioIaHelper::estado($dueno)['cuentas'];

        if ($cuentas['esenciales_listos'] < $cuentas['esenciales_total']) {

            return;
        }

        DB::table('users')->where('id', (int) $dueno->id)->whereNull('ia_onboarding_esenciales_at')->update(['ia_onboarding_esenciales_at' => Carbon::now()->format('Y-m-d H:i:s')]);
    }

    /**
     * Recalcula y persiste la pregunta en curso. Si la clave es la misma que la guardada, `cursor_desde` no
     * cambia (es lo que permite decir «te lo pregunté el lunes»); si cambia, `cursor_desde` es ahora.
     *
     * @param  \App\Models\User  $dueno
     * @return string|null  La clave en curso.
     */
    public static function actualizar_cursor(User $dueno)
    {
        $nuevo = self::cursor($dueno);
        $fila = self::fila($dueno);
        $guardado = is_null($fila) || empty($fila->ia_onboarding_cursor) ? null : (string) $fila->ia_onboarding_cursor;

        if ($nuevo === $guardado) {

            return $nuevo;
        }

        DB::table('users')->where('id', (int) $dueno->id)->update([
            'ia_onboarding_cursor'       => $nuevo,
            'ia_onboarding_cursor_desde' => is_null($nuevo) ? null : Carbon::now()->format('Y-m-d H:i:s'),
        ]);

        return $nuevo;
    }

    /* ─────────────────────────────── seguimiento ─────────────────────────────── */

    /**
     * Deja programado, como mucho, UN recordatorio de onboarding. Idempotente. Reglas, en orden:
     *   1. si ya hay una tarea de onboarding activa o pausada, no hace nada (respeta lo que el dueño reprogramó:
     *      «hoy no, el viernes» lo resuelve el modelo con reprogramar_tarea_del_asistente sobre esa tarea);
     *   2. (sin regla de «cancelada»: una tarea de onboarding cancelada NO es una pausa. `pausado_por_el_dueno` sólo
     *      lo escribe `pausar()`, a pedido del dueño. 🔴 Antes acá una tarea cancelada pausaba el onboarding, pero la
     *      puede cancelar cualquiera —en demo3 la canceló el modelo al cruzar los 13 esenciales, creyendo que había
     *      terminado— y el helper deducía una intención del dueño de un efecto. No «simplificar» volviendo a inferirla.);
     *   3. cuenta las ejecuciones sin respuesta (posteriores al último mensaje del dueño): 0 → +1 día, 1 → +3,
     *      2 → +7 y 3 → `pausado_sin_respuesta` sin crear nada;
     *   4. si el motor rechaza la tarea (por ejemplo, 10 tareas activas) loguea y sigue sin seguimiento.
     *
     * @param  \App\Models\User  $dueno
     * @param  int|null  $conversacion_id
     * @return string  Qué pasó: ya_hay | pausado_sin_respuesta | agendada | error | no_aplica.
     */
    public static function agendar_seguimiento(User $dueno, $conversacion_id = null): string
    {
        if (!self::aplica($dueno) || self::estado_de($dueno) !== self::EN_CURSO) {

            return 'no_aplica';
        }

        $hay = AiTareaDelAsistente::where('owner_id', (int) $dueno->id)
            ->where('area', self::AREA)
            ->whereIn('estado', [AiTareaDelAsistente::ESTADO_ACTIVA, AiTareaDelAsistente::ESTADO_PAUSADA])
            ->exists();

        if ($hay) {

            return 'ya_hay';
        }

        $ultimo_mensaje = self::ultimo_mensaje_del_dueno($dueno);
        $sin_respuesta = self::ejecuciones_sin_respuesta($dueno, $ultimo_mensaje);

        if ($sin_respuesta >= self::TOPE_SIN_RESPUESTA) {

            DB::table('users')->where('id', (int) $dueno->id)->update(['ia_onboarding_estado' => self::PAUSADO_SIN_RESPUESTA]);

            return self::PAUSADO_SIN_RESPUESTA;
        }

        /* La base es lo más reciente entre lo último que escribió el dueño y el último recordatorio que se mandó. */
        $base = $ultimo_mensaje;

        $ultima_ejecucion = DB::table('ai_tareas_del_asistente_ejecuciones as e')
            ->join('ai_tareas_del_asistente as t', 't.id', '=', 'e.tarea_id')
            ->where('e.owner_id', (int) $dueno->id)
            ->where('t.area', self::AREA)
            ->max('e.created_at');

        if (!empty($ultima_ejecucion) && (is_null($base) || Carbon::parse($ultima_ejecucion)->gt($base))) {

            $base = Carbon::parse($ultima_ejecucion);
        }

        $hoy = TareasDelAsistenteIaHelper::ahora()->copy()->startOfDay();
        $dia = (is_null($base) ? $hoy->copy() : $base->copy()->startOfDay())->addDays(self::ESCALERA[min($sin_respuesta, count(self::ESCALERA) - 1)]);

        /* Si la base es vieja, el recordatorio nunca cae en el pasado: mañana a más tardar. */
        if ($dia->lte($hoy)) {

            $dia = $hoy->copy()->addDay();
        }

        $creada = TareasDelAsistenteIaHelper::programar($dueno, $conversacion_id, [
            'titulo'      => self::TITULO,
            'instruccion' => self::INSTRUCCION,
            'area'        => self::AREA,
            'recurrencia' => AiTareaDelAsistente::RECURRENCIA_UNA_VEZ,
            'fecha'       => $dia->format('Y-m-d'),
            'franja'      => 'tarde',
        ]);

        if (isset($creada['error'])) {

            Log::warning('OnboardingDelNegocioIaHelper: no se pudo agendar el recordatorio', ['owner_id' => $dueno->id, 'motivo' => $creada['error']]);

            return 'error';
        }

        return 'agendada';
    }

    /**
     * Cuándo escribió el dueño por última vez, en cualquier canal (los mensajes de tarea programada no cuentan).
     *
     * @param  \App\Models\User  $dueno
     * @return \Carbon\Carbon|null
     */
    protected static function ultimo_mensaje_del_dueno(User $dueno)
    {
        $momento = DB::table('ai_messages as m')
            ->join('ai_conversations as c', 'c.id', '=', 'm.ai_conversation_id')
            ->where('c.user_id', (int) $dueno->id)
            ->where(function ($consulta) use ($dueno) {
                $consulta->whereNull('c.auth_user_id')->orWhere('c.auth_user_id', (int) $dueno->id);
            })
            ->where('m.rol', 'user')
            ->where(function ($consulta) {
                $consulta->whereNull('m.tipo')->orWhere('m.tipo', '!=', AiMessage::TIPO_TAREA_PROGRAMADA);
            })
            ->max('m.created_at');

        return empty($momento) ? null : Carbon::parse($momento);
    }

    /**
     * Cuántos recordatorios de onboarding se despertaron después de lo último que escribió el dueño.
     *
     * @param  \App\Models\User  $dueno
     * @param  \Carbon\Carbon|null  $desde
     * @return int
     */
    protected static function ejecuciones_sin_respuesta(User $dueno, $desde): int
    {
        $consulta = DB::table('ai_tareas_del_asistente_ejecuciones as e')
            ->join('ai_tareas_del_asistente as t', 't.id', '=', 'e.tarea_id')
            ->where('e.owner_id', (int) $dueno->id)
            ->where('t.area', self::AREA);

        if (!is_null($desde)) {

            $consulta->where('e.created_at', '>', $desde->format('Y-m-d H:i:s'));
        }

        return (int) $consulta->count();
    }

    /**
     * Cancela las tareas de onboarding activas o pausadas (por el único camino del motor).
     *
     * @param  \App\Models\User  $dueno
     * @return void
     */
    protected static function cancelar_tareas(User $dueno)
    {
        $tareas = AiTareaDelAsistente::where('owner_id', (int) $dueno->id)
            ->where('area', self::AREA)
            ->whereIn('estado', [AiTareaDelAsistente::ESTADO_ACTIVA, AiTareaDelAsistente::ESTADO_PAUSADA])
            ->get();

        foreach ($tareas as $tarea) {

            TareasDelAsistenteIaHelper::cancelar($dueno, ['tarea_id' => $tarea->id]);
        }
    }

    /* ─────────────────────────────── el bloque del prompt ─────────────────────────────── */

    /**
     * El bloque `<onboarding_del_negocio>` que viaja en el system del modelo, o '' si no corresponde.
     *
     * Corresponde sólo si habla el dueño por WhatsApp o por la app (no por el panel ni MCP) con el onboarding en
     * curso, o si el turno es el desatendido de una tarea de onboarding. Pausado por el dueño: una línea. Completo:
     * una línea con hasta 2 temas viejos, si hay. Largo máximo: LARGO_MAXIMO_DEL_BLOQUE.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\User  $owner
     * @param  bool  $es_primer_turno
     * @param  int|null  $assistant_id  El `assistant` del turno (para reconocer la tarea que lo despertó).
     * @return string
     */
    public static function bloque_para_prompt(AiConversation $conversation, $owner, $es_primer_turno = false, $assistant_id = null): string
    {
        try {

            if (!self::aplica($owner) || !HerramientasDeTareasDelAsistente::habla_el_dueno($conversation)) {

                return '';
            }

            $estado = self::estado_de($owner);

            if (is_null($estado)) {

                return '';
            }

            $desatendido = DespertadorDeTareasIaHelper::es_turno_desatendido($conversation, null);

            if ($desatendido && !self::es_turno_de_onboarding($conversation, $assistant_id)) {

                return '';
            }

            if ($estado === self::PAUSADO_POR_EL_DUENO) {

                return "<onboarding_del_negocio>\nEl dueño pidió que no le hagas más preguntas para conocer su negocio: no se las hagas ni se las insinúes. Sólo si él te lo pide, retomalo con conocimiento_del_negocio accion \"retomar\".\n</onboarding_del_negocio>";
            }

            $conocimiento = ConocimientoDelNegocioIaHelper::estado($owner);

            if ($estado === self::COMPLETO) {

                return self::bloque_de_viejos($conocimiento);
            }

            self::actualizar_cursor($owner);

            return self::bloque_en_curso($conversation, $owner, $conocimiento, (bool) $es_primer_turno, $desatendido);

        } catch (\Throwable $e) {

            Log::warning('OnboardingDelNegocioIaHelper: no se pudo armar el bloque del prompt', ['error' => $e->getMessage()]);

            return '';
        }
    }

    /**
     * El bloque de un onboarding completo: nada, o una línea con hasta 2 temas que se le quedaron viejos.
     *
     * @param  array  $conocimiento  ConocimientoDelNegocioIaHelper::estado().
     * @return string
     */
    protected static function bloque_de_viejos(array $conocimiento): string
    {
        $viejos = [];

        foreach ($conocimiento['temas'] as $clave => $tema) {

            if ($tema['estado'] === ConocimientoDelNegocioIaHelper::VIEJO && count($viejos) < 2) {

                $viejos[] = $clave;
            }
        }

        if (!count($viejos)) {

            return '';
        }

        return "<onboarding_del_negocio>\nYa conocés el negocio. Estos temas pueden haber cambiado: " . implode(', ', $viejos) . ". Si sale alguno en la charla, preguntale si sigue igual y actualizalo.\n</onboarding_del_negocio>";
    }

    /**
     * El bloque completo de un onboarding en curso (texto del plan, D5 y R.4).
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\User  $owner
     * @param  array  $conocimiento
     * @param  bool  $es_primer_turno
     * @param  bool  $desatendido
     * @return string
     */
    protected static function bloque_en_curso(AiConversation $conversation, User $owner, array $conocimiento, $es_primer_turno, $desatendido): string
    {
        $catalogo = ConocimientoDelNegocioIaHelper::catalogo();
        $cuentas = $conocimiento['cuentas'];
        $listos = $cuentas['sabidos'] + $cuentas['omitidos'];

        $tarea = AiTareaDelAsistente::where('owner_id', (int) $owner->id)
            ->where('area', self::AREA)
            ->whereIn('estado', [AiTareaDelAsistente::ESTADO_ACTIVA, AiTareaDelAsistente::ESTADO_PAUSADA])
            ->orderBy('id', 'desc')
            ->first();

        $seguimiento = 'ninguna programada por ahora';

        if (!is_null($tarea)) {

            $cuando = is_null($tarea->proxima_ejecucion_at) ? 'pausada' : 'el ' . $tarea->proxima_ejecucion_at->format('d/m') . ' a las ' . $tarea->proxima_ejecucion_at->format('H:i');
            $seguimiento = '#' . $tarea->id . ' (' . $cuando . ')';
        }

        $lineas = [];
        $lineas[] = '<onboarding_del_negocio>';
        $lineas[] = 'Objetivo: conocer su negocio a fondo. Llevás ' . $listos . ' de ' . $cuentas['total'] . ' temas (' . $cuentas['esenciales_listos'] . ' de ' . $cuentas['esenciales_total'] . ' esenciales). Seguimiento: ' . $seguimiento . '.';

        if ($es_primer_turno) {

            $lineas[] = 'Es el PRIMER mensaje por WhatsApp: contestale, presentate en una línea, contale que querés conocer su negocio (puede mandarte audios largos) y hacé la primera pregunta. Corto.';
        }

        // Lo que el sistema ya tiene cargado: antes de proponer altas «de cero» hay que saber qué hay (M18).
        $lineas[] = self::linea_de_lo_ya_cargado($owner);

        $en_curso = self::linea_de_pregunta_en_curso($owner, $catalogo);

        if ($en_curso !== '') {

            $lineas[] = $en_curso;
        }

        // Lo último que contó el dueño: de ahí sale el «en qué quedaron» de una charla retomada (M17).
        $ultimo = self::linea_de_lo_ultimo_que_conto($conocimiento);

        if ($ultimo !== '') {

            $lineas[] = $ultimo;
        }

        $lineas[] = 'Cómo:';
        $lineas[] = '- Charlá, no encuestes: una pregunta por mensaje (dos si van juntas), con tus palabras y ejemplos de su rubro. Voseo, cálido. Si escribió por otra cosa, resolvé eso primero.';
        $lineas[] = '- Invitalo a mandar audios largos: de uno sacá todo, puede responder varios temas.';

        if ($desatendido) {

            $lineas[] = '- En este turno no podés guardar ni marcar nada: sólo retomá la charla con UNA pregunta.';

        } else {

            $lineas[] = '- Guardá cada respuesta con guardar_en_contexto (archivo del tema) y "temas" con las claves respondidas, pueden ser varias. Si un tema no aplica o no quiere contestarlo: conocimiento_del_negocio accion "omitir". Si no sabe, ayudalo a pensarlo.';
            $lineas[] = '- Si el archivo del tema ya tiene algo (mirá el índice), marcalo con temas y no lo preguntes.';
            $lineas[] = '- "Hoy no"/"preguntame el viernes": reprogramá la tarea' . (is_null($tarea) ? '' : ' #' . $tarea->id) . ' con reprogramar_tarea_del_asistente. "No me preguntes más": conocimiento_del_negocio accion "pausar".';
        }

        // Las cuatro reglas de la conversación real (A6, San Blas 26/11): encaje, plata, datos y cortesías.
        $lineas[] = '- Si la respuesta no encaja con la pregunta, sumala donde encaja o repreguntá.';
        $lineas[] = '- Plata (fiado, señas, cambios): repetí en UNA frase lo que entendiste y guardá con su sí; si es ambiguo, repreguntá con opciones, nunca un «no te entendí» solo.';
        $lineas[] = '- Anotá y repetí sólo lo que dijo, sin agregar datos.';
        $lineas[] = '- Cerrada la tanda, ante una cortesía contestá una sola vez, corto: no te despidas en cada mensaje.';
        $lineas[] = '- No guardes números que calcula el sistema (ventas, stock, saldos).';
        $lineas[] = '- La tarea la maneja el sistema: no la canceles.';
        $lineas[] = 'Faltan (en este orden):';

        $faltantes = [];

        foreach (ConocimientoDelNegocioIaHelper::orden_de_preguntas() as $clave) {

            if ($conocimiento['temas'][$clave]['estado'] === ConocimientoDelNegocioIaHelper::FALTA) {

                $faltantes[] = $clave;
            }
        }

        if (count($faltantes)) {

            $clave_de_faltantes = 'Claves que faltan (usalas en temas): ' . implode(', ', $faltantes);
        }

        $del_formulario = [];

        foreach ($conocimiento['temas'] as $clave => $tema) {

            if ($tema['fuente'] === 'cuestionario' && $tema['estado'] !== ConocimientoDelNegocioIaHelper::FALTA) {

                $del_formulario[] = $clave;
            }
        }

        /*
         * Los próximos temas se muestran en detalle (hasta FALTANTES_EN_EL_BLOQUE); los demás van sólo por clave,
         * TODOS en una línea. Si con la lista completa no entra en el largo máximo (el primer turno lleva una
         * línea larga de más), se muestran menos en detalle: la lista de claves nunca se recorta. Siempre queda al menos
         * el tema en curso con su archivo («- historia → archivo»): con el formulario (4 temas sembrados) y un id de
         * tarea de 5 a 7 dígitos el bloque rozaba el máximo y, sin ese piso, el recorte del final se lo llevaba.
         * El texto fijo se mantiene corto para que ese piso entre con margen.
         */
        $bloque = '';

        for ($en_detalle = min(self::FALTANTES_EN_EL_BLOQUE, count($faltantes)); $en_detalle >= min(1, count($faltantes)); $en_detalle--) {

            $armado = $lineas;

            /* El primero ya tiene su pregunta en «Pregunta en curso»; el segundo lleva la suya; los otros, sólo el destino. */
            foreach (array_slice($faltantes, 0, $en_detalle) as $posicion => $clave) {

                $armado[] = '- ' . $clave . ' → ' . $catalogo[$clave]['destino'] . ($posicion === 1 ? ': ' . $catalogo[$clave]['pregunta'] : '');
            }

            if (isset($clave_de_faltantes)) {

                $armado[] = $clave_de_faltantes;
            }

            if (count($del_formulario)) {

                $armado[] = 'Ya te contó en la app: ' . implode(', ', $del_formulario) . '. No se lo vuelvas a preguntar.';
            }

            $armado[] = '</onboarding_del_negocio>';

            $bloque = implode("\n", $armado);

            if (mb_strlen($bloque) <= self::LARGO_MAXIMO_DEL_BLOQUE) {

                return $bloque;
            }
        }

        return mb_substr($bloque, 0, self::LARGO_MAXIMO_DEL_BLOQUE - 26) . "\n</onboarding_del_negocio>";
    }

    /**
     * La línea fija de la pregunta en curso: la hace ahora, o la retoma si se la hizo otro día y no la contestó.
     *
     * @param  \App\Models\User  $owner
     * @param  array  $catalogo
     * @return string
     */
    protected static function linea_de_pregunta_en_curso(User $owner, array $catalogo): string
    {
        $fila = self::fila($owner);

        if (is_null($fila) || empty($fila->ia_onboarding_cursor) || !isset($catalogo[$fila->ia_onboarding_cursor])) {

            return '';
        }

        $tema = $catalogo[$fila->ia_onboarding_cursor];
        $base = 'Pregunta en curso: ' . $tema['clave'] . ' → ' . $tema['pregunta'];

        $desde = empty($fila->ia_onboarding_cursor_desde) ? null : Carbon::parse($fila->ia_onboarding_cursor_desde);

        if (is_null($desde) || $desde->isSameDay(Carbon::now())) {

            return $base . ' Hacé ésta.';
        }

        /*
         * 🔴 `cursor_desde` es cuándo CAMBIÓ el cursor (al marcar el tema anterior), no cuándo se hizo la pregunta: en San Blas
         * pasó a la siguiente al cerrar la tanda, y al día siguiente el bloque afirmó «Se la hiciste» de algo que nunca se preguntó.
         * Lo único que se sabe es desde cuándo está pendiente.
         */
        return $base . ' Pendiente desde el ' . $desde->format('d/m') . ' (puede que no se la hayas llegado a hacer): hacela con tus palabras, sin decir que ya se la preguntaste.';
    }

    /**
     * «Lo último que te contó: <clave> (dd/mm)»: el último tema que el dueño contó (sabido, por fecha de marca), o ''
     * si todavía no contó ninguno. Es el dato del «en qué quedaron» cuando el recordatorio retoma la charla en una
     * conversación nueva, sin el historial.
     *
     * @param  array  $conocimiento  ConocimientoDelNegocioIaHelper::estado().
     * @return string
     */
    protected static function linea_de_lo_ultimo_que_conto(array $conocimiento): string
    {
        $ultima_clave = null;
        $ultima_fecha = null;

        foreach ($conocimiento['temas'] as $clave => $tema) {

            // Sólo lo que contó (sabido, aunque ya esté viejo): no lo omitido, lo que falta ni lo que vino del formulario de la app.
            if (!in_array($tema['estado'], [AiConocimientoDelNegocio::ESTADO_SABIDO, ConocimientoDelNegocioIaHelper::VIEJO], true) || empty($tema['marcado_at']) || $tema['fuente'] === 'cuestionario') {

                continue;
            }

            $marcado = Carbon::parse($tema['marcado_at']);

            if (is_null($ultima_fecha) || $marcado->gt($ultima_fecha)) {

                $ultima_clave = $clave;
                $ultima_fecha = $marcado;
            }
        }

        return is_null($ultima_clave) ? '' : 'Lo último que te contó: ' . $ultima_clave . ' (' . $ultima_fecha->format('d/m') . ').';
    }

    /**
     * «Ya cargado: N artículos activos, M clientes, P proveedores.»: lo que el negocio ya tiene en el sistema, contado
     * en el momento (M18, Plazoleta 26/11: el asistente propuso cargar el catálogo «de cero» sobre uno ya importado).
     *
     * @param  \App\Models\User  $owner
     * @return string
     */
    protected static function linea_de_lo_ya_cargado(User $owner): string
    {
        $articulos = Article::where('user_id', (int) $owner->id)->where('status', 'active')->count();
        $clientes = Client::where('user_id', (int) $owner->id)->count();
        $proveedores = Provider::where('user_id', (int) $owner->id)->count();

        return 'Ya cargado: ' . $articulos . ' artículos activos, ' . $clientes . ' clientes, ' . $proveedores . ' proveedores';
    }

    /* ─────────────────────────────── estados hacia afuera ─────────────────────────────── */

    /**
     * Lo que ve la app (contrato C2): `{estado, esenciales_completos, completo}`.
     *
     * @param  \App\Models\User  $dueno
     * @return array{estado: string|null, esenciales_completos: bool, completo: bool}
     */
    public static function estado_para_la_app(User $dueno): array
    {
        $estado = self::estado_de($dueno);
        $cuentas = ConocimientoDelNegocioIaHelper::estado($dueno)['cuentas'];

        return [
            'estado'               => $estado,
            'esenciales_completos' => $cuentas['esenciales_listos'] >= $cuentas['esenciales_total'],
            'completo'             => $estado === self::COMPLETO,
        ];
    }

    /**
     * Lo que ve el admin (contrato C1): el estado del formulario, el del onboarding y el hito
     * `onboarding_completado` (el formulario completo O los esenciales contados por WhatsApp).
     *
     * @param  \App\Models\User  $dueno
     * @return array<string,mixed>
     */
    public static function estado_para_admin(User $dueno): array
    {
        $cuestionario = AgenteContextoHelper::estado_cuestionario($dueno);
        $fila = self::fila($dueno);
        $conocimiento = ConocimientoDelNegocioIaHelper::estado($dueno);
        $cuentas = $conocimiento['cuentas'];

        $iso = function ($momento) {

            return empty($momento) ? null : Carbon::parse($momento)->toIso8601String();
        };

        $esenciales_completos = $cuentas['esenciales_listos'] >= $cuentas['esenciales_total'];
        $esenciales_en = $iso(is_null($fila) ? null : $fila->ia_onboarding_esenciales_at);

        if ($esenciales_completos && is_null($esenciales_en)) {

            /* Los completó antes de que el onboarding existiera para él: vale desde el último tema marcado. */
            $ultimo = null;

            foreach ($conocimiento['temas'] as $tema) {

                if (!empty($tema['marcado_at']) && (is_null($ultimo) || Carbon::parse($tema['marcado_at'])->gt($ultimo))) {

                    $ultimo = Carbon::parse($tema['marcado_at']);
                }
            }

            $esenciales_en = is_null($ultimo) ? null : $ultimo->toIso8601String();
        }

        /* El hito: lo que ocurrió primero entre el formulario de la app y los esenciales por WhatsApp. */
        $candidatos = [];

        if ($cuestionario['completado'] && !empty($cuestionario['completado_en'])) {

            $candidatos['app'] = Carbon::parse($cuestionario['completado_en']);
        }

        if ($esenciales_completos && !is_null($esenciales_en)) {

            $candidatos['whatsapp'] = Carbon::parse($esenciales_en);
        }

        $por = null;
        $en = null;

        foreach ($candidatos as $quien => $momento) {

            if (is_null($en) || $momento->lt($en)) {

                $por = $quien;
                $en = $momento;
            }
        }

        $completado = $cuestionario['completado'] || $esenciales_completos;

        if ($completado && is_null($por)) {

            $por = $cuestionario['completado'] ? 'app' : 'whatsapp';
        }

        return [
            'cuestionario'             => $cuestionario,
            'onboarding'               => [
                'estado'               => self::estado_de($dueno),
                'origen'               => is_null($fila) || empty($fila->ia_onboarding_origen) ? null : (string) $fila->ia_onboarding_origen,
                'iniciado_en'          => $iso(is_null($fila) ? null : $fila->ia_onboarding_iniciado_at),
                'esenciales_completos' => $esenciales_completos,
                'esenciales_en'        => $esenciales_en,
                'completo'             => self::estado_de($dueno) === self::COMPLETO,
                'completo_en'          => $iso(is_null($fila) ? null : $fila->ia_onboarding_completado_at),
                'cursor'               => is_null($fila) || empty($fila->ia_onboarding_cursor) ? null : (string) $fila->ia_onboarding_cursor,
                'cursor_desde'         => $iso(is_null($fila) ? null : $fila->ia_onboarding_cursor_desde),
                'temas'                => [
                    'total'             => $cuentas['total'],
                    'sabidos'           => $cuentas['sabidos'],
                    'omitidos'          => $cuentas['omitidos'],
                    'faltan'            => $cuentas['faltan'],
                    'viejos'            => $cuentas['viejos'],
                    'esenciales_total'  => $cuentas['esenciales_total'],
                    'esenciales_listos' => $cuentas['esenciales_listos'],
                ],
            ],
            'onboarding_completado'    => $completado,
            'onboarding_completado_en' => $completado && !is_null($en) ? $en->toIso8601String() : null,
            'onboarding_completado_por' => $completado ? $por : null,
        ];
    }
}
