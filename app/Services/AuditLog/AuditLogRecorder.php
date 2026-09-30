<?php

namespace App\Services\AuditLog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * Registro de auditoría de cambios (misión auditoria-de-cambios, 30/9/2026).
 *
 * Engancha un listener GLOBAL a los eventos de Eloquent (`created`, `updated`, `deleted`,
 * `restored`) y deja una fila en `audit_logs` por cada cambio: qué modelo, qué campos (valor viejo
 * y nuevo), quién, desde dónde y en qué lote. Cubre los más de 300 modelos, los presentes y los
 * futuros, sin tocar ninguno: lo que no se quiere auditar se EXCLUYE a propósito en
 * `config/audit_log.php`, con su motivo.
 *
 * Se registra con UNA línea en `AppServiceProvider::boot()`: `AuditLogRecorder::register()`.
 *
 * 🔴 Lo que este mecanismo NO ve, y está declarado en vez de escondido: solo ve lo que pasa por
 * eventos de Eloquent. No ve las escrituras por query builder (`DB::table()->update()`,
 * `Model::where()->update()`, `Model::insert()`), ni `saveQuietly()`/`withoutEvents()`, ni las
 * tablas pivote de `attach()/sync()/detach()`. Esas están inventariadas en
 * `_cruzado/misiones/20260930-auditoria-de-cambios/huecos.md`. Lo que sí queda cubierto aunque el
 * stock se escriba por SQL directo: cada movimiento de stock crea un `StockMovement` por Eloquent.
 *
 * Reglas del diseño, en el orden en que se aplican (ver `procesar()`):
 *  1. Interruptor general. 2. Modelos excluidos. 3. Silencio de operación masiva (jobs
 *  `jobs_masivos`: una fila por OPERACIÓN, no por artículo). 4. En un `updated`, solo cuentan los
 *  campos que cambiaron y no son ignorados. 5. Los campos sensibles se guardan como "[oculto]".
 *  6. Topes de tamaño por valor y por fila. 7. Tope de filas por lote. 8. INSERT inmediato.
 *
 * 🔴 NUNCA rompe la operación de negocio: todo pasa dentro de un `try/catch (\Throwable)`. Un
 * fallo de auditoría (tabla inexistente en un cliente a mitad de upgrade, base saturada) deja un
 * `Log::warning` y el negocio sigue.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 */
class AuditLogRecorder
{
    /**
     * Bandera de reentrada: true mientras el recorder está trabajando.
     *
     * Hace falta porque resolver al actor (`Auth::user()`) puede, en el primer llamado de un
     * request con token, guardar un modelo (Sanctum actualiza `last_used_at` de
     * `PersonalAccessToken`), y ese guardado dispararía otra vez este listener, que volvería a
     * pedir el usuario que todavía se está resolviendo. Sin esta bandera, una recursión sin fin.
     *
     * @var bool
     */
    protected static $trabajando = false;

    /**
     * Engancha el listener a los eventos de Eloquent y de la cola. Se llama desde
     * `AppServiceProvider::boot()`.
     *
     * No lleva bandera de "ya registrado": cada aplicación (y cada test crea una) tiene su propio
     * despachador de eventos, y una bandera estática dejaría a las siguientes sin listener.
     *
     * @return void
     */
    public static function register()
    {
        foreach (['created', 'updated', 'deleted', 'restored'] as $evento) {

            // Comodín por clase: 'eloquent.updated: App\Models\Article', 'eloquent.updated: App\Models\Sale'...
            Event::listen('eloquent.' . $evento . ': *', function ($nombre_evento, $payload) use ($evento) {

                $modelo = isset($payload[0]) ? $payload[0] : null;

                if ($modelo instanceof Model) {
                    self::registrar($evento, $modelo);
                }

                // Sin retorno a propósito: un listener que devuelve algo distinto de null
                // frenaría a los demás en los eventos que se despachan con `until`.
                return null;
            });
        }

        // Contexto de la cola: abre el lote (y el silencio, si es una operación masiva) al empezar
        // un job y lo cierra al terminar. Todo dentro de try/catch: un error acá NO puede tumbar
        // un job.
        Event::listen(JobProcessing::class, function ($evento) {
            try {
                $clase = self::clase_del_job($evento->job);
                $masivos = config('audit_log.jobs_masivos', []);

                AuditContext::iniciar_job($evento->job, $clase, isset($masivos[$clase]));
            } catch (\Throwable $e) {
                self::avisar_falla($e);
            }
        });

        foreach ([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class] as $clase_evento) {
            Event::listen($clase_evento, function ($evento) {
                try {
                    AuditContext::terminar_job($evento->job);
                } catch (\Throwable $e) {
                    self::avisar_falla($e);
                }
            });
        }
    }

    /**
     * Punto de entrada del listener de Eloquent: protege al negocio de cualquier fallo de la
     * auditoría.
     *
     * @param string $evento created | updated | deleted | restored
     * @param \Illuminate\Database\Eloquent\Model $modelo
     * @return void
     */
    protected static function registrar($evento, Model $modelo)
    {
        // Reentrada: ver la nota de $trabajando.
        if (self::$trabajando) {
            return;
        }

        self::$trabajando = true;

        try {
            self::procesar($evento, $modelo);
        } catch (\Throwable $e) {
            // 🔴 Un fallo de auditoría no rompe jamás la operación de negocio.
            self::avisar_falla($e);
        } finally {
            self::$trabajando = false;
        }
    }

    /**
     * Decide si el cambio se audita y, si sí, lo escribe.
     *
     * @param string $evento
     * @param \Illuminate\Database\Eloquent\Model $modelo
     * @return void
     */
    protected static function procesar($evento, Model $modelo)
    {
        // 1. Interruptor general (config(), nunca env(): ver config/audit_log.php).
        if (!config('audit_log.habilitado', true)) {
            return;
        }

        // Si la tabla no existía hace un momento, no se insiste hasta que pase la espera.
        if (time() < AuditContext::esperar_hasta()) {
            return;
        }

        // Clase del modelo que cambió.
        $clase = get_class($modelo);

        // 2. Excluidos (clase => motivo).
        $excluidos = config('audit_log.modelos_excluidos', []);

        if (isset($excluidos[$clase])) {
            return;
        }

        // Marco (lote) de esta acción: se resuelve UNA vez por request o job, no por fila.
        $marco = AuditContext::marco();

        // 3. Silencio de operación masiva: solo pasa la fila de la operación misma.
        if ($marco->silenciado && !in_array($clase, config('audit_log.registro_de_operaciones', []), true)) {
            return;
        }

        // De los modelos de progreso (BackgroundProcess) solo se audita la creación.
        if ($evento !== 'created' && in_array($clase, config('audit_log.solo_creacion', []), true)) {
            return;
        }

        // 4. Qué valores viejos y nuevos se guardan, según el evento.
        $viejos = null;
        $nuevos = null;

        if ($evento === 'created') {

            $nuevos = $modelo->getAttributes();

        } elseif ($evento === 'deleted') {

            $viejos = $modelo->getAttributes();

        } elseif ($evento === 'updated') {

            // En el evento `updated` de Eloquent, getChanges() ya tiene lo nuevo y el original
            // todavía tiene lo viejo (Model::performUpdate e incrementOrDecrement sincronizan el
            // original DESPUÉS de disparar el evento): por eso también auditan increment() y
            // decrement().
            $nuevos = self::sin_campos_ignorados($modelo->getChanges());

            // Si lo único que cambió es un campo ignorado (updated_at, session_id...), no hay nada
            // que registrar: no se escribe ninguna fila.
            if (empty($nuevos)) {
                return;
            }

            // `getRawOriginal()` y no `getOriginal()`: éste último castea cada atributo del
            // modelo entero, y acá corremos por cada fila que el sistema guarda.
            $original = $modelo->getRawOriginal();
            $viejos = [];

            foreach ($nuevos as $campo => $valor) {
                $viejos[$campo] = array_key_exists($campo, $original) ? $original[$campo] : null;
            }
        }

        // 5 y 6. Sensibles ocultos, valores largos cortados, JSON con tope de bytes.
        $ocultos_del_modelo = $modelo->getHidden();
        $json_viejos = is_null($viejos) ? null : self::serializar($viejos, $ocultos_del_modelo);
        $json_nuevos = is_null($nuevos) ? null : self::serializar($nuevos, $ocultos_del_modelo);

        // 7. Tope de filas por lote.
        $maximo = (int) config('audit_log.max_filas_por_lote', 500);

        if ($maximo > 0 && $marco->filas >= $maximo) {
            self::omitir($marco, $modelo, $clase, $maximo);
            return;
        }

        // 8. Escritura.
        self::insertar($marco, $modelo, $clase, $evento, $json_viejos, $json_nuevos);
    }

    /**
     * Escribe la fila de auditoría.
     *
     * 🔴 INSERT INMEDIATO Y POR LA MISMA CONEXIÓN, no diferido a `terminating` ni a una cola: así
     * la fila participa de la transacción del negocio. Si una venta hace rollback, su auditoría
     * también, y no queda constancia de algo que nunca pasó. Diferirla (que sería más "prolijo" y
     * ahorraría el INSERT dentro de la transacción) dejaría filas de auditoría de operaciones
     * revertidas, que es peor que no tener auditoría: miente.
     *
     * Se usa `table()->insert()` y no `AuditLog::create()`: sin eventos de modelo (no hay
     * recursión posible) y sin el costo de instanciar un modelo por fila.
     *
     * @param \stdClass $marco
     * @param \Illuminate\Database\Eloquent\Model $modelo
     * @param string $clase
     * @param string $evento
     * @param string|null $json_viejos
     * @param string|null $json_nuevos
     * @return void
     */
    protected static function insertar($marco, Model $modelo, $clase, $evento, $json_viejos, $json_nuevos)
    {
        $modelo->getConnection()
            ->table(config('audit_log.tabla', 'audit_logs'))
            ->insert(self::armar_fila($marco, $modelo, $clase, $evento, $json_viejos, $json_nuevos));

        $marco->filas++;
    }

    /**
     * Se pasó el tope de filas del lote: escribe UNA fila `truncated` (la primera vez) y de ahí en
     * más solo cuenta las omitidas. Al cerrar el lote se actualiza esa fila con el total.
     *
     * Es la red de seguridad contra una operación masiva que nadie previó (un job que guarda
     * miles de filas por Eloquent y no está en `jobs_masivos`): acota el peor caso de crecimiento
     * de la tabla, y lo deja dicho en vez de esconderlo.
     *
     * @param \stdClass $marco
     * @param \Illuminate\Database\Eloquent\Model $modelo
     * @param string $clase
     * @param int $maximo
     * @return void
     */
    protected static function omitir($marco, Model $modelo, $clase, $maximo)
    {
        $marco->omitidas++;

        if ($marco->id_truncada !== null) {
            return;
        }

        $fila = self::armar_fila(
            $marco,
            $modelo,
            $clase,
            'truncated',
            null,
            json_encode(['limite' => $maximo, 'omitidas' => 1])
        );

        // La fila `truncated` no lleva el id del modelo que colmó el tope: no es un cambio de ese
        // modelo sino un aviso sobre el lote.
        $fila['auditable_id'] = null;

        $marco->id_truncada = $modelo->getConnection()
            ->table(config('audit_log.tabla', 'audit_logs'))
            ->insertGetId($fila);

        // En HTTP el cierre corre al terminar el request; en la cola, en JobProcessed (ver
        // AuditContext::terminar_job). Se registra solo si hace falta: la enorme mayoría de los
        // requests nunca llega al tope.
        if ($marco->job === null && !$marco->terminating_registrado) {

            $marco->terminating_registrado = true;

            app()->terminating(function () use ($marco) {
                AuditLogRecorder::cerrar_marco($marco);
            });
        }
    }

    /**
     * Cierra un lote: si hubo filas omitidas por el tope, actualiza la fila `truncated` con el
     * total y deja un `Log::warning`. Idempotente. Público porque lo llaman AuditContext (al
     * terminar un job o renovar el request) y el `terminating` de HTTP.
     *
     * @param \stdClass $marco
     * @return void
     */
    public static function cerrar_marco($marco)
    {
        if ($marco->cerrado) {
            return;
        }

        $marco->cerrado = true;

        if ($marco->omitidas <= 0 || $marco->id_truncada === null) {
            return;
        }

        try {

            DB::table(config('audit_log.tabla', 'audit_logs'))
                ->where('id', $marco->id_truncada)
                ->update([
                    'new_values' => json_encode([
                        'limite'   => (int) config('audit_log.max_filas_por_lote', 500),
                        'omitidas' => (int) $marco->omitidas,
                    ]),
                ]);

            Log::warning(
                'AuditLogRecorder: el lote ' . $marco->uuid . ' pasó el tope de auditoría; se omitieron '
                . $marco->omitidas . ' filas (ver la fila truncated ' . $marco->id_truncada . ').'
            );

        } catch (\Throwable $e) {
            self::avisar_falla($e);
        }
    }

    /**
     * Arma el arreglo de la fila (columnas de `audit_logs`).
     *
     * 🔴 El actor sale de `Auth::user()` (ya resuelto por el guard, sin consulta) y su dueño de
     * `owner_id`, que es una columna del mismo usuario. NO se usa `UserHelper::user()`: hace un
     * `User::find()` en CADA llamada y, corriendo por cada fila que se guarda, una importación de
     * 20.000 artículos serían 20.000 consultas de más (misma trampa documentada en
     * `ArticleObserver::$cache_gate`). Hay un test que cuenta las consultas.
     *
     * @param \stdClass $marco
     * @param \Illuminate\Database\Eloquent\Model $modelo
     * @param string $clase
     * @param string $evento
     * @param string|null $json_viejos
     * @param string|null $json_nuevos
     * @return array
     */
    protected static function armar_fila($marco, Model $modelo, $clase, $evento, $json_viejos, $json_nuevos)
    {
        // Quien hizo la acción. En la cola (y en consola sin sesión) es null.
        $actor = Auth::user();

        // Dueño del comercio (multi-tenant): el del actor; sin actor, el que declara el modelo.
        $dueno = null;

        if (!is_null($actor)) {
            $dueno = $actor->owner_id ? $actor->owner_id : $actor->id;
        } elseif (array_key_exists('user_id', $modelo->getAttributes())) {
            $dueno = $modelo->getAttributes()['user_id'];
        } elseif ($clase === 'App\Models\User') {
            $dueno = $modelo->owner_id ? $modelo->owner_id : $modelo->getKey();
        }

        $fuente = AuditContext::fuente();

        $id_modelo = $modelo->getKey();

        return [
            'user_id'        => is_numeric($dueno) ? (int) $dueno : null,
            'actor_id'       => is_null($actor) ? null : $actor->id,
            'actor_name'     => is_null($actor) ? null : mb_substr((string) $actor->name, 0, 120),
            'auditable_type' => mb_substr($clase, 0, 100),
            'auditable_id'   => is_numeric($id_modelo) ? (int) $id_modelo : null,
            'event'          => $evento,
            'old_values'     => $json_viejos,
            'new_values'     => $json_nuevos,
            'source'         => $fuente,
            'origin'         => self::origen($marco, $fuente),
            'ip'             => self::ip($marco, $fuente),
            'batch_uuid'     => $marco->uuid,
            'created_at'     => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * De dónde salió la acción: método y ruta en HTTP (SIN query string: puede llevar tokens), la
     * clase del job en la cola, el comando de artisan en consola (sin sus argumentos, que pueden
     * llevar datos).
     *
     * @param \stdClass $marco
     * @param string $fuente http | queue | console
     * @return string|null
     */
    protected static function origen($marco, $fuente)
    {
        if ($fuente === 'queue') {
            return mb_substr((string) $marco->job_clase, 0, 150);
        }

        if ($fuente === 'http') {
            return mb_substr(strtoupper($marco->request->method()) . ' ' . $marco->request->path(), 0, 150);
        }

        // Consola: el binario y el primer argumento si es el nombre de un comando.
        $argv = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? $_SERVER['argv'] : [];

        if (empty($argv)) {
            return null;
        }

        $origen = basename((string) $argv[0]);

        if (isset($argv[1]) && substr((string) $argv[1], 0, 1) !== '-') {
            $origen .= ' ' . $argv[1];
        }

        return mb_substr($origen, 0, 150);
    }

    /**
     * IP del cliente en HTTP; null en la cola y en consola.
     *
     * @param \stdClass $marco
     * @param string $fuente
     * @return string|null
     */
    protected static function ip($marco, $fuente)
    {
        if ($fuente !== 'http') {
            return null;
        }

        $ip = $marco->request->ip();

        return is_null($ip) ? null : mb_substr((string) $ip, 0, 45);
    }

    /**
     * Quita del arreglo de cambios los campos que no cuentan como cambio (config
     * `audit_log.campos_ignorados`).
     *
     * @param array $cambios
     * @return array
     */
    protected static function sin_campos_ignorados(array $cambios)
    {
        foreach (config('audit_log.campos_ignorados', []) as $campo) {
            unset($cambios[$campo]);
        }

        return $cambios;
    }

    /**
     * Convierte un arreglo de valores a JSON: oculta los campos sensibles, corta los strings
     * largos y, si el JSON resultante pasa del tope de bytes, lo reemplaza por la lista de campos.
     *
     * @param array $valores
     * @param array $ocultos_del_modelo Lo que el modelo declara en `$hidden`.
     * @return string
     */
    protected static function serializar(array $valores, array $ocultos_del_modelo)
    {
        $limpios = [];
        $valor_oculto = config('audit_log.valor_oculto', '[oculto]');

        foreach ($valores as $campo => $valor) {

            if (self::es_sensible($campo, $ocultos_del_modelo)) {
                // Queda constancia de que el campo CAMBIÓ, sin filtrar el valor.
                $limpios[$campo] = $valor_oculto;
                continue;
            }

            $limpios[$campo] = self::normalizar_valor($valor);
        }

        // PARTIAL_OUTPUT y SUBSTITUTE: un valor con bytes inválidos (binario en una columna de
        // texto) no puede hacer que json_encode devuelva false y se pierda la fila entera.
        $json = json_encode(
            $limpios,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        if ($json === false) {
            $json = '{}';
        }

        if (strlen($json) > (int) config('audit_log.max_bytes_fila', 60000)) {
            $json = json_encode(['_truncado' => true, 'campos' => array_keys($limpios)]);
        }

        return $json;
    }

    /**
     * Un campo es sensible si está en la lista de la config, en el `$hidden` del modelo o termina
     * en alguno de los sufijos sensibles (`_token`, `_secret`, `_password`...).
     *
     * @param string $campo
     * @param array $ocultos_del_modelo
     * @return bool
     */
    protected static function es_sensible($campo, array $ocultos_del_modelo)
    {
        $campo = strtolower((string) $campo);

        if (in_array($campo, config('audit_log.campos_sensibles', []), true)) {
            return true;
        }

        if (in_array($campo, $ocultos_del_modelo, true)) {
            return true;
        }

        foreach (config('audit_log.sufijos_sensibles', []) as $sufijo) {
            if ($sufijo !== '' && substr($campo, -strlen($sufijo)) === $sufijo) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deja un valor listo para JSON: escalares tal cual (los strings cortados al máximo), fechas
     * como texto, y arreglos u objetos como JSON en un string (también cortado).
     *
     * @param mixed $valor
     * @return mixed
     */
    protected static function normalizar_valor($valor)
    {
        if (is_null($valor) || is_bool($valor) || is_int($valor) || is_float($valor)) {
            return $valor;
        }

        if (is_string($valor)) {
            return self::cortar($valor);
        }

        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d H:i:s');
        }

        if (is_object($valor) && method_exists($valor, '__toString')) {
            return self::cortar((string) $valor);
        }

        if (is_array($valor) || is_object($valor)) {
            $json = json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

            return self::cortar($json === false ? '' : $json);
        }

        return null;
    }

    /**
     * Corta un string al máximo permitido y le agrega cuántos caracteres se perdieron
     * (`…[+N caracteres]`). Descripciones largas, imágenes en base64, JSON de diseños de ticket.
     *
     * @param string $texto
     * @return string
     */
    protected static function cortar($texto)
    {
        $maximo = (int) config('audit_log.max_largo_valor', 1000);

        // strlen (bytes) es cota superior del largo en caracteres: si los bytes no lo pasan, no
        // hace falta el conteo multibyte, que es lo caro.
        if ($maximo <= 0 || strlen($texto) <= $maximo) {
            return $texto;
        }

        $largo = mb_strlen($texto, 'UTF-8');

        if ($largo <= $maximo) {
            return $texto;
        }

        return mb_substr($texto, 0, $maximo, 'UTF-8') . '…[+' . ($largo - $maximo) . ' caracteres]';
    }

    /**
     * Clase del comando que corre un job de la cola.
     *
     * @param object $job
     * @return string
     */
    protected static function clase_del_job($job)
    {
        return method_exists($job, 'resolveName') ? (string) $job->resolveName() : get_class($job);
    }

    /**
     * Un fallo de la auditoría: se loguea UNA sola vez por proceso y, si la causa es que la tabla
     * no existe (cliente a mitad de un upgrade: código nuevo, migración todavía sin correr), se
     * deja de intentar durante `segundos_de_espera_si_falta_la_tabla`.
     *
     * Una vez por proceso y no una por fila: con la tabla ausente, una importación grande dejaría
     * un warning por cada artículo.
     *
     * @param \Throwable $e
     * @return void
     */
    protected static function avisar_falla(\Throwable $e)
    {
        try {

            // SQLSTATE 42S02 / código 1146 de MySQL: "tabla inexistente".
            $falta_la_tabla = $e instanceof QueryException
                && ((string) $e->getCode() === '42S02' || (isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1146));

            if ($falta_la_tabla) {
                AuditContext::fijar_espera(time() + (int) config('audit_log.segundos_de_espera_si_falta_la_tabla', 60));
            }

            if (!AuditContext::aviso_ya_emitido()) {
                Log::warning('AuditLogRecorder: no se pudo registrar la auditoría (la operación de negocio siguió): ' . $e->getMessage());
            }

        } catch (\Throwable $ignorada) {
            // Ni siquiera el aviso puede romper al negocio.
        }
    }
}
