<?php

namespace App\Services;

use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use App\Http\Controllers\Helpers\UserHelper;
use Illuminate\Queue\LuaScripts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Cola propia de los embeddings de artículos (misión embeddings-lote-cola).
 *
 * Hasta esta misión los jobs de embeddings (el job de un artículo, el finalizador de la tanda)
 * iban a `default`, la misma cola de las actualizaciones masivas, las imágenes y las exportaciones:
 * una importación de 22 mil artículos las dejaba esperando horas. Ahora todo lo de embeddings va a
 * `<cola base>_embeddings` y lo atiende un worker aparte que programa el scheduler de la propia
 * instancia (Kernel).
 *
 * El nombre se DERIVA de la cola base y no es fijo, igual que `<cola>_asistente`: las instalaciones
 * que comparten base de datos se distinguen por QUEUE_NAME (config/queue.php) y una cola con nombre
 * fijo dejaría que el worker de una tomara los jobs de otra.
 *
 * 🔴 EL CONSUMIDOR ES EL SCHEDULER EN TODOS LOS ENTORNOS, NO SUPERVISOR. En shared el cron corre
 * `schedule:run`; en el VPS cada cliente (y el pool de pruebas) ya tiene el programa `_schedule`
 * con `cc-schedule-runner.sh`. Así la cola nueva llega con el código, sin tocar la configuración de
 * supervisor de los ~23 clientes del VPS.
 *
 * Esta clase junta en un solo lugar las decisiones que no pueden divergir entre el despacho (a qué
 * cola va cada job) y el Kernel (quién la consume): si una abriera y la otra no, los jobs quedarían
 * en una cola sin worker.
 */
class ColaDeEmbeddings
{
    /**
     * Sufijo que se le agrega a la cola base para armar la cola propia.
     *
     * @var string
     */
    const SUFIJO = '_embeddings';

    /**
     * Segundos que el worker de esta cola se queda vivo como máximo (`--max-time`). Veinte minutos:
     * lejos del SIGKILL de 30 minutos del hosting compartido y debajo del mutex del evento (25).
     *
     * @var int
     */
    const MAX_TIME_DEL_WORKER = 1200;

    /**
     * Minutos de vigencia del mutex (`withoutOverlapping`) del evento del worker. Tiene que ser MAYOR
     * que MAX_TIME_DEL_WORKER (20 min): un solo worker de embeddings por instancia, que además cuida
     * el rate limit de OpenAI.
     *
     * @var int
     */
    const MINUTOS_DEL_MUTEX = 25;

    /**
     * Cola base de la instancia: la de la conexión `database` en shared y la de la conexión por
     * defecto en el VPS (con `redis` respeta QUEUE_NAME o REDIS_QUEUE, que pueden diferir). Es la
     * misma derivación que usa la cola del asistente.
     *
     * @return string
     */
    public static function cola_base(): string
    {
        if (config('app.VPS')) {
            return (string) config('queue.connections.' . config('queue.default') . '.queue', 'default');
        }

        return (string) config('queue.connections.database.queue', 'default');
    }

    /**
     * Nombre de la cola propia de embeddings (con QUEUE_NAME sin setear: `default_embeddings`).
     *
     * @return string
     */
    public static function nombre(): string
    {
        return self::cola_base() . self::SUFIJO;
    }

    /**
     * ¿Está prendido el interruptor de la cola propia? (`queue.embeddings_worker`, nace prendido).
     *
     * @return bool
     */
    public static function habilitada(): bool
    {
        return (bool) config('queue.embeddings_worker');
    }

    /**
     * Cola a la que se despacha un job de embeddings, o null para la cola por defecto (el
     * comportamiento de antes de la misión) cuando el interruptor está apagado.
     *
     * Es el ayudante ÚNICO: los constructores de los dos jobs de embeddings y del finalizador lo
     * usan para que no puedan tener criterios distintos.
     *
     * @return string|null
     */
    public static function cola_para_despachar(): ?string
    {
        return self::habilitada() ? self::nombre() : null;
    }

    /**
     * Comando completo del worker de la cola propia para el evento del schedule: el cierre de los
     * candados heredados del cron y después el `queue:work`.
     *
     * 🔴 El prefijo es lo que evita que, en el shared, un worker de hasta 20 minutos retenga el
     * `flock` del cron y haga saltear el `schedule:run` siguiente ENTERO (incluido el worker de
     * `default`, que es justo lo que esta misión viene a destapar). Se reusa la constante del helper
     * del asistente sin tocarlo. `--stop-when-empty` va SIN `=1` (Laravel 8 lo toma como flag).
     * Determinístico: `schedule:finish` rearma el schedule y tiene que encontrar el mismo evento.
     *
     * @param string $cola Cola a atender (p. ej. `default_embeddings`).
     *
     * @return string
     */
    public static function comando_del_worker(string $cola): string
    {
        return AsistenteCanalHelper::PREFIJO_CIERRE_DE_CANDADOS_HEREDADOS
            . \Illuminate\Console\Application::formatCommandString(
                'queue:work --queue=' . $cola
                . ' --stop-when-empty --sleep=3 --memory=256 --max-time=' . self::MAX_TIME_DEL_WORKER
            );
    }

    /**
     * Cola de embeddings que el Kernel tiene que atender, o null si no hay que programar worker.
     *
     * Se programa si (interruptor prendido Y el dueño tiene `agentes_ia`) O si quedan jobs
     * pendientes en la cola propia aunque ya no se cumpla lo anterior: un job despachado antes de
     * apagar el interruptor o la extensión no puede quedar huérfano (vuelta atrás sin huérfanos,
     * igual que el asistente).
     *
     * @param \App\Models\User|null $dueno El dueño ya resuelto por el Kernel (null si no hay USER_ID).
     *
     * @return string|null
     */
    public static function cola_para_el_schedule($dueno): ?string
    {
        $cola = self::nombre();

        if (self::habilitada() && ! is_null($dueno) && UserHelper::hasExtencion('agentes_ia', $dueno)) {
            return $cola;
        }

        return self::hay_pendientes($cola) ? $cola : null;
    }

    /**
     * ¿Quedan jobs (pendientes, reservados o demorados) en la cola indicada? Es el `when()` del evento
     * del worker (no arranca un artisan por minuto si no hay nada, el motivo por el que el VPS apagó
     * el worker de `default` del scheduler) y también decide si el worker sigue programado cuando ya
     * no se cumplen las condiciones de arriba.
     *
     *  - Shared: la tabla `jobs` de la conexión `database`.
     *  - VPS con driver `database`: la tabla de la conexión por defecto.
     *  - VPS con driver `redis`: el mismo script Lua y las mismas claves que `RedisQueue::size`.
     *  - Cualquier otro driver, o una excepción: false y un `Log::notice` (NO `warning`: el worker de la
     *    cola del asistente comparte instancia con éste y sus tests de infraestructura exigen cero warnings
     *    cuando sólo falla el conteo de la otra cola).
     *
     * 🔴 El driver se lee de la CONFIG y no por la fachada `Queue`: con `Queue::fake()` la fachada
     * devuelve el fake y con `sync` `size()` da siempre 0, y dejaría sin worker a un huérfano real.
     * Duplica a propósito el conteo de AsistenteCanalHelper::hay_turnos_pendientes_en (protegido) para
     * no tocar el helper del asistente; deuda baja: unificar en un helper de colas.
     *
     * @param string $cola Cola a contar.
     *
     * @return bool
     */
    public static function hay_pendientes(string $cola): bool
    {
        if (! config('app.VPS')) {
            try {
                return DB::table(config('queue.connections.database.table', 'jobs'))->where('queue', $cola)->exists();
            } catch (\Throwable $e) {
                return false;
            }
        }

        // Conexión por defecto del VPS y su driver, leídos de la config.
        $conexion = config('queue.default');
        $driver   = config('queue.connections.' . $conexion . '.driver');

        try {
            if ($driver === 'database') {
                return DB::table(config('queue.connections.' . $conexion . '.table', 'jobs'))->where('queue', $cola)->exists();
            }

            if ($driver === 'redis') {
                // Lista, demorados y reservados de la cola, como los cuenta RedisQueue::size.
                $clave = 'queues:' . $cola;
                $total = Redis::connection(config('queue.connections.' . $conexion . '.connection', 'default'))
                    ->eval(LuaScripts::size(), 3, $clave, $clave . ':delayed', $clave . ':reserved');

                return (int) $total > 0;
            }

            Log::notice('Cola de embeddings: el driver "' . $driver . '" no se sabe contar; no se programa el worker de ' . $cola . '.');
        } catch (\Throwable $e) {
            Log::notice('Cola de embeddings: no se pudo contar ' . $cola . ' (' . $e->getMessage() . '); no se programa el worker.');
        }

        return false;
    }
}
