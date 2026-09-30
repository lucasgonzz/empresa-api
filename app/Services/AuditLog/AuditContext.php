<?php

namespace App\Services\AuditLog;

/**
 * Estado del PROCESO en curso para la auditoría de cambios (misión auditoria-de-cambios,
 * 30/9/2026): en qué lote estamos, si la operación está en silencio, cuántas filas se llevan
 * escritas y desde dónde viene la acción.
 *
 * Es estado estático a propósito: el listener de Eloquent se ejecuta por CADA fila que el sistema
 * guarda (una importación de 20.000 artículos lo llama decenas de miles de veces), así que
 * todo lo que se pueda resolver una sola vez por request o por job se resuelve acá y se
 * memoriza, en vez de repetirse por fila.
 *
 * Un "marco" (lote) es todo lo que sale de UNA acción: un request HTTP, un job de la cola o, en
 * consola, el proceso entero. Los marcos se apilan porque con el driver de cola `sync` un job corre
 * DENTRO de un request: el job abre su marco encima del del request y, al terminar, se vuelve a
 * él.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 */
class AuditContext
{
    /**
     * Marco actual. Null hasta que alguien lo pide.
     *
     * @var \stdClass|null
     */
    protected static $marco = null;

    /**
     * Marcos suspendidos: el de abajo de cada job que corre adentro de otro contexto (driver
     * `sync`).
     *
     * @var array
     */
    protected static $pila = [];

    /**
     * Timestamp (segundos) hasta el cual el recorder no intenta escribir porque la tabla no
     * existía. Ver AuditLogRecorder::avisar_falla().
     *
     * @var int
     */
    protected static $esperar_hasta = 0;

    /**
     * Si ya se emitió el `Log::warning` de falla de este proceso. Es una sola vez por proceso y no
     * una por fila: con la tabla ausente una importación grande dejaría un warning por artículo.
     *
     * @var bool
     */
    protected static $aviso_emitido = false;

    /**
     * Devuelve el marco actual, creándolo (o renovándolo) si hace falta.
     *
     * Fuera de un job, el marco es el del REQUEST: se renueva cuando cambia el objeto request.
     * Se compara con `===` contra una referencia que el propio marco retiene, y no con
     * `spl_object_id(request())`: un id de objeto se REUTILIZA apenas el garbage collector libera
     * el request anterior, y en una suite de tests (o cualquier proceso que atienda varios requests
     * seguidos) dos requests distintos podían terminar en el mismo lote. Reteniendo la referencia
     * el objeto no se libera, y por lo tanto su identidad no se repite.
     *
     * Sin middleware nuevo a propósito: el listener ya tiene que leer el request para el origen y
     * la IP, así que el lote sale del mismo objeto, sin tocar el Kernel.
     *
     * @return \stdClass
     */
    public static function marco()
    {
        // Adentro de un job el marco lo maneja iniciar_job()/terminar_job(), no el request.
        if (self::$marco !== null && self::$marco->job !== null) {
            return self::$marco;
        }

        $request = self::request_actual();

        if (self::$marco === null || self::$marco->request !== $request) {

            $anterior = self::$marco;

            self::$marco = self::nuevo_marco($request, null, false);

            // Si el request anterior dejó filas omitidas sin cerrar (no llegó el terminating),
            // se cierra ahora para no perder el conteo.
            if ($anterior !== null) {
                AuditLogRecorder::cerrar_marco($anterior);
            }
        }

        return self::$marco;
    }

    /**
     * Abre el marco de un job de la cola (evento JobProcessing).
     *
     * @param object $job Job de Laravel (`Illuminate\Contracts\Queue\Job`).
     * @param string $clase Clase del comando que corre el job.
     * @param bool $silenciado Si el job es de una operación masiva (config audit_log.jobs_masivos).
     * @return \stdClass
     */
    public static function iniciar_job($job, $clase, $silenciado)
    {
        if (self::$marco !== null) {
            self::$pila[] = self::$marco;
        }

        self::$marco = self::nuevo_marco(null, $job, $silenciado);
        self::$marco->job_clase = $clase;

        return self::$marco;
    }

    /**
     * Cierra el marco de un job (eventos JobProcessed / JobFailed / JobExceptionOccurred).
     *
     * Solo actúa si el marco actual ES el de ese job: para un mismo job Laravel puede disparar
     * JobExceptionOccurred y después JobFailed, y el segundo no tiene que cerrar el marco de otro
     * job que corre por debajo.
     *
     * @param object $job
     * @return void
     */
    public static function terminar_job($job)
    {
        if (self::$marco === null || self::$marco->job !== $job) {
            return;
        }

        AuditLogRecorder::cerrar_marco(self::$marco);

        self::$marco = empty(self::$pila) ? null : array_pop(self::$pila);
    }

    /**
     * Indica si la operación en curso está en silencio (job masivo).
     *
     * @return bool
     */
    public static function silenciado()
    {
        return self::marco()->silenciado;
    }

    /**
     * De dónde viene la acción: `queue` dentro de un job, `http` si hay una ruta resuelta,
     * `console` en cualquier otro caso (artisan, tinker, un test que guarda directo).
     *
     * @return string
     */
    public static function fuente()
    {
        $marco = self::marco();

        if ($marco->job !== null) {
            return 'queue';
        }

        // `route()` es null hasta que el router resolvió una ruta: en consola nunca se resuelve.
        if ($marco->request !== null && $marco->request->route() !== null) {
            return 'http';
        }

        return 'console';
    }

    /**
     * El request actual, o null si el contenedor todavía no tiene uno.
     *
     * @return \Illuminate\Http\Request|null
     */
    public static function request_actual()
    {
        return app()->bound('request') ? app('request') : null;
    }

    /**
     * Segundos hasta los que hay que esperar antes de volver a intentar escribir (0 = sin espera).
     *
     * @return int
     */
    public static function esperar_hasta()
    {
        return self::$esperar_hasta;
    }

    /**
     * Fija hasta cuándo no se intenta escribir.
     *
     * @param int $timestamp
     * @return void
     */
    public static function fijar_espera($timestamp)
    {
        self::$esperar_hasta = (int) $timestamp;
    }

    /**
     * Marca el aviso de falla como emitido y devuelve si ya lo estaba (para emitirlo una sola vez).
     *
     * @return bool True si el aviso ya se había emitido antes de esta llamada.
     */
    public static function aviso_ya_emitido()
    {
        $ya_emitido = self::$aviso_emitido;

        self::$aviso_emitido = true;

        return $ya_emitido;
    }

    /**
     * Deja el contexto como recién arrancado. Público para los tests: los estáticos sobreviven
     * entre un test y el siguiente (y entre dos requests de un mismo test), así que cada test que
     * mide lotes, topes o avisos empieza por acá.
     *
     * @return void
     */
    public static function reiniciar()
    {
        self::$marco         = null;
        self::$pila          = [];
        self::$esperar_hasta = 0;
        self::$aviso_emitido = false;
    }

    /**
     * Arma un marco nuevo.
     *
     * @param \Illuminate\Http\Request|null $request Request al que pertenece (null en un job).
     * @param object|null $job Job de la cola (null fuera de un job).
     * @param bool $silenciado Si la operación es masiva y no audita sus filas.
     * @return \stdClass
     */
    protected static function nuevo_marco($request, $job, $silenciado)
    {
        $marco = new \stdClass();

        // Identificador del lote: agrupa todas las filas de esta acción.
        $marco->uuid = self::generar_uuid();

        // Referencia retenida al request (ver marco()).
        $marco->request = $request;

        // Job de la cola y su clase (null fuera de un job).
        $marco->job = $job;
        $marco->job_clase = null;

        // Operación masiva: no se auditan las filas que toca, salvo la de la operación misma.
        $marco->silenciado = (bool) $silenciado;

        // Filas de auditoría escritas en este lote, para el tope `max_filas_por_lote`.
        $marco->filas = 0;

        // Filas que no se escribieron por haber pasado el tope, y el id de la fila `truncated`.
        $marco->omitidas = 0;
        $marco->id_truncada = null;

        // Si el cierre de la fila `truncated` ya se hizo, para no repetirlo.
        $marco->cerrado = false;

        // Si ya se registró el callback de cierre en `terminating` (solo se registra si hace falta).
        $marco->terminating_registrado = false;

        return $marco;
    }

    /**
     * UUID v4. Se arma a mano (random_bytes) y no con Str::uuid() para no depender de la versión
     * de la librería de UUID y no pagar su costo por lote.
     *
     * @return string
     */
    protected static function generar_uuid()
    {
        $bytes = random_bytes(16);

        // Bits de versión (4) y de variante (RFC 4122).
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
