<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\AiConversation;
use App\Models\MostradorReporte;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Queue\LuaScripts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Quién habla y en qué conversación, cuando el mensaje entra por WhatsApp (misión
 * asistente-por-whatsapp, §3.3 del plan, 16/9/2026).
 *
 * El canal de WhatsApp no tiene sesión: el admin recibe el mensaje en el número de ComercioCity y
 * lo empuja por admin-sync con la clave del cliente. De este lado no hay `Auth` de donde sacar la
 * persona ni panel donde elegir la conversación, así que las dos cosas se resuelven acá.
 *
 * 🔴 EL DUEÑO SE RESUELVE CON config('app.USER_ID') PRIMERO. Es el patrón que ya usa
 * AdminSync\MostradorController::duenos() :76. En una base compartida por varios comercios (que
 * son un accidente histórico, pero existen: `u767360347_empresa` tiene 51 adentro) cada instancia
 * atiende al suyo, y "el primer owner del sistema" es el comercio equivocado —el error que
 * SistemaQueryController::resolve_owner() :131 arrastra y que este canal reemplaza—. Sin USER_ID,
 * el único owner del sistema; con más de uno y sin USER_ID, ninguno: es preferible un 404 visible
 * a mandarle los números de un negocio al dueño de otro.
 *
 * La política de conversaciones está repartida a propósito (§2 del plan): el admin resuelve la
 * CITA (es el único que conoce los wamid y manda `ai_conversation_id` solo si la dedujo de una) y
 * este API resuelve el CORTE POR TIEMPO (es el único que tiene `last_message_at` de verdad, y la
 * conversación es un objeto suyo).
 */
class AsistenteCanalHelper
{
    /**
     * Horas sin hablar después de las cuales un mensaje de WhatsApp abre una conversación NUEVA en
     * vez de seguir la anterior.
     *
     * Es la decisión de Lucas en la Fase 2: en el sistema el dueño abre una conversación nueva con
     * un botón y cambia entre ellas; en WhatsApp no hay dónde hacer eso, así que el corte es por
     * tiempo. Seis horas separan "seguimos hablando de lo mismo" de "esto es otro tema": el que
     * escribe a la mañana y vuelve a la tarde arranca limpio, y el que pregunta tres cosas
     * seguidas no pierde el contexto.
     *
     * La otra mitad de la política es la cita: responder citando un mensaje del asistente reabre
     * esa conversación aunque sea vieja, y eso pasa por encima de este corte (llega resuelto desde
     * el admin como `ai_conversation_id`).
     */
    const HORAS_CORTE = 6;

    /**
     * Segundos dentro de los cuales un mensaje del dueño es parte de la MISMA tanda que el anterior (misión
     * asistente-diario-20261119, rama B, A6): no abre conversación nueva, ni por el tema ni por la carrera de dos
     * mensajes que entran casi juntos.
     *
     * 🔴 EL CASO REAL (Ferre Total, 18/11/2026, conv 62 y 63). La lista de 37 renglones (11:15:51) y el audio «¿Me
     * podés preparar un presupuesto… con estos productos?» (11:16:05) quedaron en dos conversaciones: cada una
     * perdió lo de la otra y el presupuesto nunca se armó.
     *
     * POR QUÉ TRES MINUTOS. Por WhatsApp el dueño manda en ráfagas: el texto, la foto, el audio que lo explica;
     * entre uno y otro pasan segundos o, con un audio largo, uno o dos minutos. Tres minutos cubren la ráfaga
     * (el caso real fueron 14 segundos) y siguen dejando que un tema nuevo de verdad abra su hilo apenas el dueño
     * hizo una pausa. El error de este lado es barato (una conversación algo más larga, que es exactamente lo que
     * HiloPorTemaIaHelper acepta "ante la duda"); el del otro lado —partir una tarea— es el que costó un
     * presupuesto. El plan de la misión sugería «por ejemplo 10»: se eligió más corto para no tapar los cambios de
     * tema de un dueño que escribe seguido (dos consultas distintas en cinco minutos son comunes en Ferre Total).
     */
    const SEGUNDOS_DE_LA_RAFAGA = 180;

    /** Slug de la extensión que gatea el módulo IA, igual que el resto del asistente. */
    const EXTENSION = 'agentes_ia';

    /**
     * Orígenes de conversación que este canal puede CONTINUAR cuando el admin manda un
     * `ai_conversation_id` explícito.
     *
     * 🔴 `mostrador_reporte` está acá por un pedido textual de Lucas: "que pueda abrirlos desde el
     * celular y también les pueda hacer preguntas acerca de esos informes". El informe de la mañana
     * se manda con el id de SU conversación, y cuando el dueño contesta "¿por qué bajó la caja?",
     * el admin cita ese mensaje y el mensaje entra en la conversación del informe — que es la única
     * que tiene el informe entero como `contexto` de fondo. Sin esto, el asistente contestaba sin la
     * menor idea de qué informe le estaban hablando.
     *
     * Lo que NO se toca: el corte de 6 h y la creación automática siguen siendo solo de
     * 'whatsapp'. Este canal continúa una conversación de mostrador cuando se la nombran, pero
     * nunca la elige por su cuenta ni abre una.
     */
    const ORIGENES_QUE_CONTINUA = [AiConversation::ORIGEN_WHATSAPP, MostradorReporte::ORIGEN_CONVERSACION];

    /**
     * El dueño de esta instancia: users con `owner_id` null, acotado por `config('app.USER_ID')`
     * cuando la base está compartida. Null si no se puede decidir sin ambigüedad.
     *
     * 🔴 NO filtra por extensión: el gate de la extensión es otra cosa (403, no 404) y se chequea
     * aparte, para que "este cliente no tiene el módulo contratado" y "este cliente no existe" no
     * lleguen al admin como la misma respuesta. Ver `tiene_extension()`.
     *
     * @return \App\Models\User|null
     */
    public static function dueno()
    {
        $user_id_instancia = config('app.USER_ID');

        if (!empty($user_id_instancia)) {

            return User::whereNull('owner_id')
                        ->where('id', (int) $user_id_instancia)
                        ->first();
        }

        $duenos = User::whereNull('owner_id')
                        ->orderBy('id')
                        ->limit(2)
                        ->get();

        /*
         * Con más de un dueño y sin USER_ID no hay forma de saber a cuál de los comercios de esta
         * base le está hablando el que escribió: se devuelve null y el endpoint responde 404. Es
         * exactamente el caso que el login maestro resuelve mal (entra siempre como el primer
         * dueño), y acá el costo de equivocarse es mandarle las ventas de un negocio a otro.
         */
        if (count($duenos) !== 1) {

            return null;
        }

        return $duenos[0];
    }

    /**
     * true si el dueño tiene contratado el módulo IA. Misma lectura que CheckExtencionEmpresa,
     * aplicada a mano porque admin-sync no pasa por `auth:sanctum` y el middleware resuelve el
     * usuario desde la sesión.
     *
     * @param  \App\Models\User|null  $dueno
     * @return bool
     */
    public static function tiene_extension($dueno)
    {
        return !is_null($dueno) && UserHelper::hasExtencion(self::EXTENSION, $dueno);
    }

    /**
     * Nombre de la cola propia del turno del asistente, derivado de QUEUE_NAME (misión
     * asistente-whatsapp-velocidad, etapa 2, D5). Con QUEUE_NAME sin setear es `default_asistente`.
     *
     * Se deriva y no es fijo a propósito: las instalaciones que comparten base (config/queue.php) se
     * distinguen por QUEUE_NAME, y una cola con nombre fijo dejaría que el worker de una tomara el
     * turno de otra.
     *
     * En el shared es la cola de la conexión `database`, literal como siempre. En el VPS (opción
     * `queue.asistente_worker_vps`) es la de la conexión por defecto: con `redis` respeta QUEUE_NAME y,
     * si no, REDIS_QUEUE (config/queue.php), que pueden diferir del nombre de la conexión `database`.
     *
     * @return string
     */
    protected static function nombre_de_la_cola_del_asistente()
    {
        if (config('app.VPS')) {
            return config('queue.connections.' . config('queue.default') . '.queue', 'default') . '_asistente';
        }

        return config('queue.connections.database.queue', 'default') . '_asistente';
    }

    /**
     * Puerta ÚNICA de infraestructura del worker del asistente. La usan las dos funciones públicas
     * (el despacho del turno y el schedule del Kernel) para que no puedan divergir: si una abriera y
     * la otra no, el turno iría a `<cola>_asistente` sin worker que lo levante.
     *
     *  - Shared (no VPS): el worker del scheduler (`queue.scheduler_worker`), como siempre.
     *  - VPS: SÓLO `queue.asistente_worker_vps` (apagada por defecto). Ignora a propósito
     *    `queue.scheduler_worker`: en el VPS vale false (el supervisor ya consume `default` y el
     *    scheduler no lanza el worker de `default`), y exigirlo dejaría la opción inutilizable. Tampoco
     *    mira `cache.default`: el evento del worker no tiene mutex (Enmienda 8), no hay candado que la
     *    caché tenga que sostener.
     *
     * @return bool
     */
    protected static function infraestructura_del_worker_del_asistente()
    {
        if (config('app.VPS')) {
            return (bool) config('queue.asistente_worker_vps');
        }

        return (bool) config('queue.scheduler_worker');
    }

    /**
     * ¿Se dan las condiciones para usar la cola propia del asistente? La puerta única de
     * infraestructura (en el shared el worker del scheduler; en el VPS la opción `asistente_worker_vps`) y el
     * interruptor del asistente (QUEUE_ASISTENTE_WORKER) encendidos. En el VPS, sin la opción, el
     * supervisor sólo consume `default`: una cola nueva dejaría el turno sin nadie que lo levante.
     *
     * @return bool
     */
    protected static function cola_del_asistente_habilitada()
    {
        return self::infraestructura_del_worker_del_asistente()
            && config('queue.asistente_worker');
    }

    /**
     * Cola a la que se despacha el turno del asistente (WhatsApp y chat de la pantalla), o null
     * para la cola por defecto, que es el comportamiento de siempre (D5).
     *
     * Devuelve la cola propia sólo si se cumple la puerta de infraestructura (shared con el worker
     * del scheduler; VPS con la opción `asistente_worker_vps`) y el interruptor está encendido, Y el
     * dueño de la instancia tiene la extensión `agentes_ia`. En cualquier otro caso (el VPS sin la
     * opción incluido) devuelve null. Es el ayudante ÚNICO: así los dos canales y el redespacho no
     * tienen criterios distintos.
     *
     * @return string|null
     */
    public static function cola_del_turno_whatsapp(): ?string
    {
        if (! self::cola_del_asistente_habilitada()) {
            return null;
        }

        if (! self::tiene_extension(self::dueno())) {
            return null;
        }

        return self::nombre_de_la_cola_del_asistente();
    }

    /**
     * Cierre de los descriptores heredados (3 a 9) que va delante del worker vivo del asistente
     * (Enmienda 7, hallazgo de la revisión 1-A).
     *
     * En el hosting compartido `schedule:run` corre bajo `flock -n <slug>.schedule.lock` (cc-cron.sh)
     * y bajo el envoltorio de Hostinger (`flock -w 1 /tmp/cron_lock_<uid> …`). Los procesos de
     * `runInBackground()` heredan esos candados como descriptores abiertos: como el worker vive
     * ~65 s, el `schedule:run` del minuto siguiente se saltearía entero. Un `exec` sin comando
     * aplica sus redirecciones al subshell `( … )` de fondo, así que ni el worker ni
     * `schedule:finish` conservan los candados.
     */
    const PREFIJO_CIERRE_DE_CANDADOS_HEREDADOS = 'exec 3>&- 4>&- 5>&- 6>&- 7>&- 8>&- 9>&- ; ';

    /**
     * Comando completo del worker vivo del asistente para el evento del schedule: el cierre de
     * candados heredados y después el `queue:work` (armado con el mismo formato que usa
     * `$schedule->command()`). Es determinístico: `schedule:finish` rearma el schedule y tiene que
     * encontrar el mismo evento. El evento va SIN `withoutOverlapping` (Enmienda 8): no hay mutex
     * que retenga el tick; `--max-time=65` hace que cada worker se pise unos segundos con el
     * siguiente, para que un turno que cruza el arranque del minuto no deje la cola sin worker.
     *
     * @param  string  $cola  Cola del asistente (p. ej. `default_asistente`).
     * @return string
     */
    public static function comando_del_worker_del_turno_whatsapp(string $cola): string
    {
        return self::PREFIJO_CIERRE_DE_CANDADOS_HEREDADOS
            . \Illuminate\Console\Application::formatCommandString(
                'queue:work --queue=' . $cola . ' --sleep=1 --max-time=65 --tries=1'
            );
    }

    /**
     * Cola del asistente para el schedule del Kernel (D7): la del turno si corresponde, o la misma
     * cola si quedaron jobs pendientes en ella aunque el dueño ya no cumpla las condiciones, para
     * que un turno despachado antes de apagar la extensión o el interruptor no quede huérfano.
     * Null si no hay que programar ningún worker.
     *
     * Nunca devuelve cola si la puerta de infraestructura está cerrada (shared sin worker del
     * scheduler; VPS sin `asistente_worker_vps`), ni siquiera con filas pendientes: ahí el worker no
     * se programa. En VPS la vuelta atrás es en dos pasos (ver config/queue.php): primero el
     * interruptor `asistente_worker`, y recién con la cola vacía la opción `asistente_worker_vps`.
     *
     * @param  \App\Models\User|null  $dueno  El dueño ya resuelto por el Kernel (null si no hay USER_ID).
     * @return string|null
     */
    public static function cola_del_turno_whatsapp_para_el_schedule($dueno): ?string
    {
        if (! self::infraestructura_del_worker_del_asistente()) {
            return null;
        }

        $cola = self::nombre_de_la_cola_del_asistente();

        // El worker vive siempre que el dueño tenga la extensión y el interruptor esté prendido.
        if (config('queue.asistente_worker') && self::tiene_extension($dueno)) {
            return $cola;
        }

        // Sin eso, sólo si hay turnos pendientes en esa cola. Si la consulta falla, no se programa.
        return self::hay_turnos_pendientes_en($cola) ? $cola : null;
    }

    /**
     * ¿Quedan turnos (pendientes, reservados o demorados) en la cola propia del asistente? Decide si
     * el worker sigue programado cuando el dueño ya no cumple las condiciones, para no dejar huérfano
     * un turno despachado antes de apagar algo.
     *
     *  - Shared: la consulta de siempre sobre la tabla `jobs`, sin cambios.
     *  - VPS con driver `database`: la misma consulta sobre la tabla de la conexión por defecto.
     *  - VPS con driver `redis`: el mismo script Lua y las mismas claves que `RedisQueue::size`
     *    (lista, `:delayed` y `:reserved`).
     *  - Cualquier otro driver, o una excepción: false y un `Log::warning` (no se programa worker;
     *    el huérfano lo vería la herramienta de colas).
     *
     * 🔴 El driver se lee de la CONFIG (`queue.default` → `queue.connections.<conexion>.driver`) y no
     * por la fachada `Queue`: con `Queue::fake()` (el test 7) la fachada devuelve el fake y con `sync`
     * (phpunit.xml) `size()` da siempre 0, así que dejaría sin worker a un huérfano real.
     *
     * @param  string  $cola  Cola del asistente (p. ej. `default_asistente`).
     * @return bool
     */
    protected static function hay_turnos_pendientes_en(string $cola): bool
    {
        if (! config('app.VPS')) {
            try {
                return DB::table('jobs')->where('queue', $cola)->exists();
            } catch (\Throwable $e) {
                return false;
            }
        }

        $conexion = config('queue.default');
        $driver = config('queue.connections.' . $conexion . '.driver');

        try {
            if ($driver === 'database') {
                return DB::table(config('queue.connections.' . $conexion . '.table', 'jobs'))->where('queue', $cola)->exists();
            }

            if ($driver === 'redis') {
                $clave = 'queues:' . $cola;
                $total = Redis::connection(config('queue.connections.' . $conexion . '.connection', 'default'))
                    ->eval(LuaScripts::size(), 3, $clave, $clave . ':delayed', $clave . ':reserved');

                return (int) $total > 0;
            }

            Log::warning('Cola del asistente en VPS: el driver "' . $driver . '" no se sabe contar; no se programa el worker de ' . $cola . '.');
        } catch (\Throwable $e) {
            Log::warning('Cola del asistente en VPS: no se pudo contar ' . $cola . ' (' . $e->getMessage() . '); no se programa el worker.');
        }

        return false;
    }

    /**
     * La conversación de WhatsApp en la que entra este mensaje.
     *
     * El orden del §3.3 del plan, que es el que sostiene la política repartida con el admin:
     *
     *   1. si vino `ai_conversation_id` y es una conversación de este dueño de un origen que este
     *      canal puede continuar → esa. (El admin solo lo manda cuando lo dedujo de una CITA o de
     *      un informe que él mismo mandó, así que esto es "el dueño respondió citando": reabre ese
     *      hilo aunque hayan pasado días.)
     *   2. si no, la última conversación de WhatsApp del dueño que habló hace menos de
     *      HORAS_CORTE → esa...
     *   2.b ...salvo que el mensaje nuevo arranque OTRA TAREA, que es lo que decide
     *      HiloPorTemaIaHelper (misión asistente-capacidades-y-hilos, P4, 22/9/2026). En WhatsApp
     *      el dueño escribe siempre desde la misma conversación, así que sin este paso se juntaban
     *      ocho tareas sin relación en un solo hilo (76 mensajes en demo3) con el título de lo
     *      primero que se pidió. Cualquier falla de ese llamado se comporta como "sigue".
     *   3. si no → una nueva.
     *
     * Un `ai_conversation_id` que no existe, que es de otro dueño o que es de un origen que este
     * canal no continúa NO es un error: se ignora y se sigue por el camino 2. El admin puede tener
     * una fila vieja apuntando a una conversación que el dueño borró desde el sistema, y un 422 por
     * eso dejaría al dueño sin respuesta a un mensaje perfectamente válido.
     *
     * 🔴 El corte se calcula con Carbon::now() de ESTE API, que corre en
     * America/Argentina/Buenos_Aires. No con la hora de la máquina que despachó el mensaje.
     *
     * @param  \App\Models\User  $dueno
     * @param  mixed  $ai_conversation_id  El que mandó el admin, o null.
     * @param  string  $texto  El texto del mensaje nuevo, con el que se decide si es otro tema.
     *                         Vacío (una foto sola, un audio sin transcribir) nunca corta.
     * @return \App\Models\AiConversation|null Null si hace falta crear un hilo al guardar.
     */
    public static function elegir_conversacion(User $dueno, $ai_conversation_id = null, $texto = ''): ?AiConversation
    {
        $pedida = self::conversacion_pedida($dueno, $ai_conversation_id);

        if (!is_null($pedida)) {

            return $pedida;
        }

        $reciente = self::conversacion_vigente($dueno);

        /*
         * 🔴 UN HILO POR TAREA. Con conversación vigente, la IA decide si este mensaje sigue el
         * tema o arranca otro; con `nueva` se cae al create de abajo y el título lo infiere
         * InferirTituloConversacionIaJob por el camino de siempre, porque la conversación nace sin
         * título y sin mensajes. Las guardas duras —tarjeta en `propuesta`, cita del admin,
         * mensaje corto o sin texto— y el "una falla no corta" viven en el helper.
         */
        if (!is_null($reciente) && !HiloPorTemaIaHelper::abre_otro_hilo($reciente, $texto, $ai_conversation_id)) {

            return $reciente;
        }

        return null;
    }

    public static function nueva_conversacion(User $dueno): AiConversation
    {
        // En este canal solo habla el dueño, en las dos puntas de la tenencia.
        return AiConversation::create([
            'user_id'      => $dueno->id,
            'auth_user_id' => $dueno->id,
            'origen'       => AiConversation::ORIGEN_WHATSAPP,
        ]);
    }

    /**
     * La conversación de WhatsApp vigente del dueño: la última que habló hace menos de HORAS_CORTE,
     * o null. Es el paso 2 de conversacion(), sin la decisión de tema ni la creación.
     *
     * Existe aparte desde la misión recordatorios-whatsapp (16/10/2026): el recordatorio de la
     * Agenda viaja con el id de la conversación donde va a caer la respuesta del dueño, y tiene que
     * ser exactamente la que este canal elegiría. Es una consulta pura: no llama a la IA ni crea nada.
     *
     * @param  \App\Models\User  $dueno
     * @return \App\Models\AiConversation|null
     */
    public static function conversacion_vigente(User $dueno, bool $bloquear = false)
    {
        $consulta = AiConversation::where('user_id', $dueno->id)
                                ->where('auth_user_id', $dueno->id)
                                ->where('origen', AiConversation::ORIGEN_WHATSAPP)
                                ->whereNotNull('last_message_at')
                                ->where('last_message_at', '>', Carbon::now()->subHours(self::HORAS_CORTE))
                                ->orderBy('last_message_at', 'DESC')
                                ->orderBy('id', 'DESC');

        $ultima = $bloquear ? $consulta->lockForUpdate()->first() : $consulta->first();

        // Si el hilo más reciente se trasladó, no retoma un tema anterior por accidente.
        return $ultima && $ultima->sistema_exclusivo_at === null ? $ultima : null;
    }

    /**
     * true si la conversación tuvo actividad hace menos de SEGUNDOS_DE_LA_RAFAGA: el mensaje nuevo es parte de la
     * misma tanda (ver la constante).
     *
     * Se mide con `last_message_at`, que es el mismo reloj del corte por HORAS_CORTE: lo escribe el alta de cada
     * mensaje del dueño por este canal (AdminSync\AsistenteController::guardar_turno(), ANTES de que el asistente
     * conteste: por eso también cubre la respuesta que todavía se está escribiendo) y también un aviso o un
     * recordatorio que el asistente le acaba de mandar. Que un aviso de hace un minuto no deje cortar está bien: lo
     * más probable es que el dueño le esté contestando a ese aviso («ponele 4 a cada uno»).
     *
     * @param  \App\Models\AiConversation  $conversacion
     * @return bool
     */
    public static function es_de_la_rafaga(AiConversation $conversacion): bool
    {
        if (is_null($conversacion->last_message_at) || (string) $conversacion->last_message_at === '') {

            return false;
        }

        return Carbon::parse($conversacion->last_message_at)->gte(Carbon::now()->subSeconds(self::SEGUNDOS_DE_LA_RAFAGA));
    }

    /**
     * La conversación de WhatsApp vigente del dueño SI el dueño escribió ahí hace menos de SEGUNDOS_DE_LA_RAFAGA, o
     * null. La usa AdminSync\AsistenteController ADENTRO de la transacción que bloquea al dueño, cuando la elección
     * de afuera dio «conversación nueva»: dos mensajes que entran casi juntos (cada uno con su request) eligen la
     * conversación en paralelo, antes de que el otro haya guardado nada, y los dos terminaban creando una. Con el
     * dueño bloqueado, el segundo ve la conversación que acaba de crear el primero y entra ahí.
     *
     * @param  \App\Models\User  $dueno
     * @return \App\Models\AiConversation|null
     */
    public static function conversacion_de_la_rafaga(User $dueno)
    {
        $vigente = self::conversacion_vigente($dueno, true);

        return (!is_null($vigente) && self::es_de_la_rafaga($vigente)) ? $vigente : null;
    }

    /**
     * La conversación que pidió el admin, si existe, es de este dueño y es de un origen que este
     * canal continúa (ORIGENES_QUE_CONTINUA). Null en cualquier otro caso (ver por qué no es un
     * error en el docblock de conversacion()).
     *
     * @param  \App\Models\User  $dueno
     * @param  mixed  $ai_conversation_id
     * @return \App\Models\AiConversation|null
     */
    protected static function conversacion_pedida(User $dueno, $ai_conversation_id)
    {
        $id = (int) $ai_conversation_id;

        if ($id <= 0) {

            return null;
        }

        return AiConversation::where('id', $id)
                                ->where('user_id', $dueno->id)
                                ->where('auth_user_id', $dueno->id)
                                ->whereIn('origen', self::ORIGENES_QUE_CONTINUA)
                                ->first();
    }
}
