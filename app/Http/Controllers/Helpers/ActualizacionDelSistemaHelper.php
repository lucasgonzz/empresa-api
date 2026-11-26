<?php

namespace App\Http\Controllers\Helpers;

use App\Events\ActualizacionDelSistemaCambio;
use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use App\Models\ActualizacionDelSistema;
use App\Models\ActualizacionDispositivo;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Toda la lógica del aviso de actualización del sistema (misión aviso-actualizacion-sistema, 25/11/2026).
 *
 * ## Qué resuelve
 * Mientras el admin actualiza un cliente, el sistema muestra "estamos actualizando", cierra las sesiones y no
 * deja ingresar; con `force` en horario de trabajo, antes le pide el OK a cada computadora conectada. El estado
 * vive en la tabla `actualizaciones_del_sistema` (una fila por dueño): la base es lo único que comparten los dos
 * frentes de empresa y tienda-api, y sobrevive al reemplazo de código del deploy.
 *
 * ## Estados (plan §2.2)
 *  - `libre` (o sin fila): nada.
 *  - `pidiendo_ok`: hay un pedido abierto; cada computadora ve el modal y nadie queda afuera todavía.
 *  - `rechazado`: alguien apretó "Ahora no"; para los usuarios es como `libre`, el admin lo lee y lo cancela.
 *  - `actualizando`: login bloqueado, sesiones cerradas. Vale mientras `vence_at > now()`: un `actualizando`
 *    vencido se trata (y se guarda) como `libre`, así el bloqueo no queda para siempre si el pipeline se cae.
 *
 * ## Evaluación perezosa (sin cron)
 * Las transiciones se aplican al LEER (polling de los SPA, lectura del admin, respuesta de un usuario) dentro de
 * una transacción con `lockForUpdate` sobre la fila (`evaluar()`): `pidiendo_ok` sin pendientes pasa a
 * `actualizando`; `pidiendo_ok` con el plazo vencido expulsa a los pendientes y pasa a `actualizando`;
 * `actualizando` vencido pasa a `libre`.
 *
 * ## Presencia
 * Una "computadora" es una sesión de Laravel (un navegador; sus pestañas comparten sesión). Se guarda
 * `sha256(session()->getId())`, nunca el id. El polling de 60 s del SPA deja `visto_at`; al abrir un pedido
 * participan las sesiones vistas en los últimos 3 minutos. Maestro y soporte no se registran ni cuentan.
 *
 * ## Fechas
 * Todas las comparaciones se hacen en PHP (`Carbon::now()`), nunca con `NOW()` de SQL: la zona horaria de la
 * sesión de la base puede no ser la de la aplicación. Se escribe y se lee con la misma zona, así que es
 * consistente de punta a punta.
 *
 * PHP 7.4 estricto: nada de la sintaxis de PHP 8 (ni match, ni operador nullsafe, ni funciones str_*_with, ni argumentos con nombre).
 */
class ActualizacionDelSistemaHelper
{
    /** Texto de la marca "estamos actualizando" (contrato §2.4). */
    const MENSAJE_ACTUALIZANDO = 'Estamos actualizando el sistema. Mientras tanto no se puede usar. Cuando termine vas a poder ingresar de nuevo desde esta pantalla.';

    /** Texto del pedido de OK (lo muestra el modal; el SPA puede usar el suyo). */
    const MENSAJE_PEDIDO = 'Necesitamos actualizar el sistema. Si nos das el OK, vas a dejar de usar el sistema y empezamos a actualizarlo.';

    /** Código de error de la marca. */
    const ERROR_MARCA = 'sistema_actualizando';

    /** Segundos que el SPA espera antes de reintentar (también va en el header `Retry-After`). */
    const REINTENTAR_EN_SEGUNDOS = 30;

    /** Una computadora cuenta como "conectada" si se la vio en los últimos N minutos. */
    const MINUTOS_DE_PRESENCIA = 3;

    /** Minutos por defecto que tienen las computadoras para contestar el pedido de OK. */
    const MINUTOS_PARA_RESPONDER = 10;

    /** Vencimiento del `actualizando` que sale de un pedido de OK (si el admin no arranca, se libera solo). */
    const MINUTOS_TRAS_EL_OK = 30;

    /** Vencimiento por defecto del `actualizando` que prende el admin. */
    const MINUTOS_POR_DEFECTO = 120;

    /** Lectura cacheada del estado para el middleware: el otro frente se entera en a lo sumo estos segundos. */
    const SEGUNDOS_DE_CACHE = 10;

    /** Una computadora no reescribe su `visto_at` más seguido que esto. */
    const SEGUNDOS_ENTRE_ESCRITURAS_DE_PRESENCIA = 50;

    /** Las filas de presencia más viejas que esto (en días) se borran al crear un pedido. */
    const DIAS_DE_LIMPIEZA = 1;

    /*
    |--------------------------------------------------------------------------
    | La marca (respuesta 503)
    |--------------------------------------------------------------------------
    */

    /**
     * La marca "estamos actualizando": 503, `Retry-After: 30` y el cuerpo del contrato. La contestan el
     * middleware, el login, el `down` del deploy y el GET público; empresa-spa la reconoce por
     * `status 503 + error === 'sistema_actualizando'`.
     *
     * @param array<string, mixed> $extra Claves que se suman al cuerpo (las del login).
     * @return \Illuminate\Http\JsonResponse
     */
    public static function respuesta_marca(array $extra = [])
    {
        return response()->json(array_merge([
            'error'                  => self::ERROR_MARCA,
            'message'                => self::MENSAJE_ACTUALIZANDO,
            'reintentar_en_segundos' => self::REINTENTAR_EN_SEGUNDOS,
        ], $extra), 503, ['Retry-After' => (string) self::REINTENTAR_EN_SEGUNDOS]);
    }

    /**
     * La marca para el login: además lleva las mismas claves de siempre en su forma de "no entró"
     * (`login:false`, `user:null`, sin candado), para que el SPA que lee `res.data.login` no se confunda.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public static function respuesta_marca_de_login()
    {
        return self::respuesta_marca([
            'login'                           => false,
            'user'                            => null,
            'user_last_activity'              => false,
            'user_last_activity_wait_minutes' => 0,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Lectura del bloqueo (middleware y login)
    |--------------------------------------------------------------------------
    */

    /**
     * El dueño al que pertenece un usuario (él mismo, o su dueño si es empleado).
     *
     * @param \App\Models\User|null $user
     * @return int|null
     */
    public static function owner_id_de($user)
    {
        return PruebaHelper::owner_id_de($user);
    }

    /**
     * Clave de cache de la lectura del estado de un dueño.
     *
     * @param int $owner_id
     * @return string
     */
    protected static function clave_de_cache($owner_id)
    {
        return 'actualizacion_del_sistema.estado.' . (int) $owner_id;
    }

    /**
     * Olvida la lectura cacheada de un dueño. Se llama en CADA escritura de estado de este frente, así el
     * admin que prende o libera ve el efecto en el acto; el otro frente lo ve en `SEGUNDOS_DE_CACHE`.
     *
     * @param int $owner_id
     * @return void
     */
    public static function olvidar_cache($owner_id)
    {
        try {
            Cache::forget(self::clave_de_cache($owner_id));
        } catch (\Throwable $e) {
            // La cache es una optimización: si falla, la próxima lectura va a la base.
        }
    }

    /**
     * ¿Este dueño está bloqueado ahora? Es `estado = 'actualizando'` con `vence_at` en el futuro (la misma
     * regla que lee tienda-api). No evalúa transiciones ni escribe: sólo mira.
     *
     * Con `$con_cache` (el middleware, en cada request) lee la base como mucho cada `SEGUNDOS_DE_CACHE`. Sin
     * cache (el login, que es raro) siempre lee la base. Cualquier falla de lectura (tabla inexistente en una
     * base todavía sin migrar, cache rota) cuenta como "no bloqueado": el aviso nunca puede tirar el sistema.
     *
     * @param int  $owner_id  Dueño del comercio.
     * @param bool $con_cache Si se puede usar la lectura cacheada de 10 segundos.
     * @return bool
     */
    public static function esta_bloqueado($owner_id, $con_cache = true)
    {
        $owner_id = (int) $owner_id;

        /** Lee estado y vencimiento de la fila del dueño; el vencimiento va como timestamp para cachearlo liviano. */
        $leer = function () use ($owner_id) {
            try {
                $fila = DB::table('actualizaciones_del_sistema')
                    ->where('owner_id', $owner_id)
                    ->orderBy('id')
                    ->first(['estado', 'vence_at']);
            } catch (\Throwable $e) {
                return ['estado' => ActualizacionDelSistema::ESTADO_LIBRE, 'vence_ts' => null];
            }

            if (is_null($fila)) {
                return ['estado' => ActualizacionDelSistema::ESTADO_LIBRE, 'vence_ts' => null];
            }

            return [
                'estado'   => (string) $fila->estado,
                'vence_ts' => empty($fila->vence_at) ? null : Carbon::parse($fila->vence_at)->getTimestamp(),
            ];
        };

        try {
            $lectura = $con_cache
                ? Cache::remember(self::clave_de_cache($owner_id), self::SEGUNDOS_DE_CACHE, $leer)
                : $leer();
        } catch (\Throwable $e) {
            $lectura = $leer();
        }

        return $lectura['estado'] === ActualizacionDelSistema::ESTADO_ACTUALIZANDO
            && !is_null($lectura['vence_ts'])
            && $lectura['vence_ts'] > Carbon::now()->getTimestamp();
    }

    /**
     * ¿Hay que cortar el ingreso de este usuario? Lo usan los tres caminos de login después de que la
     * credencial salió bien. Siempre lee la base (sin cache).
     *
     * @param \App\Models\User|null $user Usuario recién autenticado.
     * @return bool
     */
    public static function bloquea_el_ingreso($user)
    {
        if (is_null($user)) {
            return false;
        }

        return self::esta_bloqueado(self::owner_id_de($user), false);
    }

    /*
    |--------------------------------------------------------------------------
    | Transiciones (evaluación perezosa)
    |--------------------------------------------------------------------------
    */

    /**
     * La fila de estado del dueño con lock de escritura, creándola `libre` si no existe. Tiene que llamarse
     * DENTRO de una transacción.
     *
     * @param int  $owner_id
     * @param bool $crear     Si debe crear la fila cuando falta.
     * @return \App\Models\ActualizacionDelSistema|null
     */
    protected static function fila_con_lock($owner_id, $crear = false)
    {
        $fila = ActualizacionDelSistema::where('owner_id', (int) $owner_id)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if (is_null($fila) && $crear) {
            $fila = ActualizacionDelSistema::create([
                'owner_id' => (int) $owner_id,
                'estado'   => ActualizacionDelSistema::ESTADO_LIBRE,
            ]);
        }

        return $fila;
    }

    /**
     * Las computadoras (filas de presencia) que participan del pedido vigente de una fila de estado.
     *
     * @param \App\Models\ActualizacionDelSistema $fila
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected static function dispositivos_del_pedido($fila)
    {
        return ActualizacionDispositivo::where('owner_id', (int) $fila->owner_id)
            ->where('pedido_uuid', (string) $fila->pedido_uuid);
    }

    /**
     * Cierra la sesión única de todo el comercio: `session_id` y `last_activity` en NULL para el dueño y sus
     * empleados. Así, cuando termine la actualización, entran sin el cartel "tu cuenta está en otro
     * dispositivo" (mismo criterio que `UserController::check_cambio_version`).
     *
     * @param int $owner_id
     * @return void
     */
    protected static function limpiar_candados($owner_id)
    {
        User::where(function ($consulta) use ($owner_id) {
            $consulta->where('id', (int) $owner_id)->orWhere('owner_id', (int) $owner_id);
        })->update([
            'session_id'    => null,
            'last_activity' => null,
        ]);
    }

    /**
     * Pasa la fila a `actualizando` hasta dentro de `$minutos` y libera los candados del comercio.
     *
     * @param \App\Models\ActualizacionDelSistema $fila
     * @param int                                 $minutos
     * @return void
     */
    protected static function poner_actualizando($fila, $minutos)
    {
        $ahora = Carbon::now();

        if ($fila->estado !== ActualizacionDelSistema::ESTADO_ACTUALIZANDO) {
            $fila->actualizando_desde = $ahora;
        }

        $fila->estado = ActualizacionDelSistema::ESTADO_ACTUALIZANDO;
        $fila->vence_at = $ahora->copy()->addMinutes((int) $minutos);
        $fila->save();

        self::limpiar_candados($fila->owner_id);
    }

    /**
     * Pasa la fila a `libre` y borra todo lo del pedido y del vencimiento.
     *
     * @param \App\Models\ActualizacionDelSistema $fila
     * @return void
     */
    protected static function poner_libre($fila)
    {
        $fila->estado = ActualizacionDelSistema::ESTADO_LIBRE;
        $fila->upgrade_id = null;
        $fila->version = null;
        $fila->pedido_uuid = null;
        $fila->pedido_at = null;
        $fila->vence_respuesta_at = null;
        $fila->rechazado_at = null;
        $fila->rechazado_por_user_id = null;
        $fila->actualizando_desde = null;
        $fila->vence_at = null;
        $fila->save();
    }

    /**
     * Aplica sobre la fila (ya bloqueada) las transiciones que correspondan al momento actual. Tiene que
     * llamarse DENTRO de la transacción de quien tiene el lock.
     *
     *  - `actualizando` vencido (o sin vencimiento) pasa a `libre`.
     *  - `pidiendo_ok` con el plazo vencido: los pendientes quedan `expulsado` y pasa a `actualizando`.
     *  - `pidiendo_ok` sin pendientes: pasa a `actualizando`.
     *
     * @param \App\Models\ActualizacionDelSistema $fila
     * @return bool true si cambió el estado.
     */
    protected static function aplicar_transiciones($fila)
    {
        $ahora = Carbon::now();

        if ($fila->estado === ActualizacionDelSistema::ESTADO_ACTUALIZANDO) {
            if (is_null($fila->vence_at) || $fila->vence_at->lte($ahora)) {
                self::poner_libre($fila);

                return true;
            }

            return false;
        }

        if ($fila->estado !== ActualizacionDelSistema::ESTADO_PIDIENDO_OK) {
            return false;
        }

        // Un `pidiendo_ok` sin pedido (fila escrita a mano, sin `pedido_uuid`) no tiene participantes que
        // esperar ni plazo que vencer: no hay nada que evaluar, y no se lo promueve a `actualizando` por error.
        if (empty($fila->pedido_uuid)) {
            return false;
        }

        $pendientes = self::dispositivos_del_pedido($fila)
            ->where('respuesta', ActualizacionDispositivo::RESPUESTA_PENDIENTE);

        $plazo_vencido = !is_null($fila->vence_respuesta_at) && $fila->vence_respuesta_at->lte($ahora);

        if ($plazo_vencido) {
            // Los que no contestaron a tiempo se quedan afuera, como dice el pedido de Lucas (D2).
            $pendientes->update([
                'respuesta'    => ActualizacionDispositivo::RESPUESTA_EXPULSADO,
                'respondio_at' => $ahora,
            ]);
        } elseif ($pendientes->count() > 0) {
            return false;
        }

        self::poner_actualizando($fila, self::MINUTOS_TRAS_EL_OK);

        return true;
    }

    /**
     * Evalúa las transiciones del dueño con lock y, si hubo un cambio, olvida la cache y avisa por broadcast.
     *
     * @param int $owner_id
     * @return \App\Models\ActualizacionDelSistema|null La fila ya evaluada, o null si el dueño no tiene.
     */
    public static function evaluar($owner_id)
    {
        $cambio = false;

        $fila = DB::transaction(function () use ($owner_id, &$cambio) {
            $fila = self::fila_con_lock($owner_id);

            if (!is_null($fila)) {
                $cambio = self::aplicar_transiciones($fila);
            }

            return $fila;
        });

        if ($cambio) {
            self::olvidar_cache($owner_id);
            self::emitir($owner_id, $fila);
        }

        return $fila;
    }

    /*
    |--------------------------------------------------------------------------
    | Armado de las respuestas
    |--------------------------------------------------------------------------
    */

    /**
     * Una fecha en ISO 8601 con zona (`2026-11-26T03:10:00-03:00`), o null.
     *
     * @param \Carbon\Carbon|null $fecha
     * @return string|null
     */
    protected static function iso($fecha)
    {
        return is_null($fecha) ? null : $fecha->toIso8601String();
    }

    /**
     * Segundos que faltan para que venza el plazo de respuesta (0 si ya no hay pedido abierto).
     *
     * @param \App\Models\ActualizacionDelSistema $fila
     * @return int
     */
    protected static function segundos_para_responder($fila)
    {
        if ($fila->estado !== ActualizacionDelSistema::ESTADO_PIDIENDO_OK || is_null($fila->vence_respuesta_at)) {
            return 0;
        }

        return max(0, $fila->vence_respuesta_at->getTimestamp() - Carbon::now()->getTimestamp());
    }

    /**
     * El bloque `pedido` del contrato (`AvisoEstado`), o null si no hay pedido: totales, quiénes faltan, quién
     * dijo "Ahora no" y cuánto falta para que venza el plazo.
     *
     * @param \App\Models\ActualizacionDelSistema|null $fila
     * @return array<string, mixed>|null
     */
    protected static function pedido_de($fila)
    {
        if (is_null($fila) || $fila->estado === ActualizacionDelSistema::ESTADO_LIBRE || empty($fila->pedido_uuid)) {
            return null;
        }

        $filas = self::dispositivos_del_pedido($fila)->orderBy('id')->get();

        $pendientes = $filas->where('respuesta', ActualizacionDispositivo::RESPUESTA_PENDIENTE);

        $nombres = User::whereIn('id', $pendientes->pluck('user_id')->unique()->all())->pluck('name', 'id');

        $lista = [];
        foreach ($pendientes as $dispositivo) {
            $lista[] = (string) ($nombres->get($dispositivo->user_id) ?: 'Computadora');
        }

        $rechazado_por = null;
        if (!empty($fila->rechazado_por_user_id)) {
            $rechazado_por = User::where('id', $fila->rechazado_por_user_id)->value('name');
        }

        return [
            'total'                  => $filas->count(),
            'aceptaron'              => $filas->where('respuesta', ActualizacionDispositivo::RESPUESTA_ACEPTO)->count(),
            'faltan'                 => $pendientes->count(),
            'pendientes'             => $lista,
            'rechazado_por'          => $rechazado_por,
            'vence_respuesta_at'     => self::iso($fila->vence_respuesta_at),
            'segundos_para_responder' => self::segundos_para_responder($fila),
        ];
    }

    /**
     * El cuerpo `AvisoEstado` que contestan los endpoints del admin (contrato §4.1).
     *
     * @param int                                      $owner_id
     * @param \App\Models\ActualizacionDelSistema|null $fila
     * @return array<string, mixed>
     */
    public static function aviso_estado($owner_id, $fila)
    {
        return [
            'ok'         => true,
            'owner_id'   => (int) $owner_id,
            'estado'     => is_null($fila) ? ActualizacionDelSistema::ESTADO_LIBRE : $fila->estado,
            'upgrade_id' => is_null($fila) ? null : $fila->upgrade_id,
            'version'    => is_null($fila) ? null : $fila->version,
            'vence_at'   => is_null($fila) ? null : self::iso($fila->vence_at),
            'pedido'     => self::pedido_de($fila),
        ];
    }

    /**
     * Resultado estándar de las operaciones del admin: código HTTP y cuerpo.
     *
     * @param int                  $status
     * @param array<string, mixed> $body
     * @return array{status: int, body: array<string, mixed>}
     */
    protected static function resultado($status, array $body)
    {
        return ['status' => $status, 'body' => $body];
    }

    /**
     * Resultado de una operación que terminó bien: `AvisoEstado` con la fila fresca de la base.
     *
     * @param int $owner_id
     * @return array{status: int, body: array<string, mixed>}
     */
    protected static function resultado_de_estado($owner_id)
    {
        $fila = ActualizacionDelSistema::where('owner_id', (int) $owner_id)->orderBy('id')->first();

        return self::resultado(200, self::aviso_estado($owner_id, $fila));
    }

    /*
    |--------------------------------------------------------------------------
    | Broadcast
    |--------------------------------------------------------------------------
    */

    /**
     * Avisa por el canal público del dueño que cambió el estado. NUNCA rompe el request: si Pusher falla se
     * deja un warning y el SPA se entera igual por su polling de 60 segundos.
     *
     * @param int                                      $owner_id
     * @param \App\Models\ActualizacionDelSistema|null $fila Fila ya guardada (o null si no hay).
     * @return void
     */
    public static function emitir($owner_id, $fila)
    {
        try {
            $pedido = self::pedido_de($fila);

            event(new ActualizacionDelSistemaCambio($owner_id, [
                'estado'             => is_null($fila) ? ActualizacionDelSistema::ESTADO_LIBRE : $fila->estado,
                'total'              => is_null($pedido) ? 0 : $pedido['total'],
                'aceptaron'          => is_null($pedido) ? 0 : $pedido['aceptaron'],
                'faltan'             => is_null($pedido) ? 0 : $pedido['faltan'],
                'vence_respuesta_at' => is_null($fila) ? null : self::iso($fila->vence_respuesta_at),
                'rechazado_por'      => is_null($pedido) ? null : $pedido['rechazado_por'],
            ]));
        } catch (\Throwable $e) {
            Log::warning('ActualizacionDelSistema: no se pudo emitir el broadcast', [
                'owner_id' => (int) $owner_id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Operaciones del admin (admin-sync)
    |--------------------------------------------------------------------------
    */

    /**
     * Valida a mano el cuerpo de las operaciones del admin (el contrato exige 422 `validacion`; se hace acá
     * y no con `$request->validate()` para devolver el formato `{error, message}` del canal admin-sync).
     *
     * Reglas: `user_id` entero positivo; `upgrade_id` entero positivo; `version` texto de hasta 40;
     * `vence_en_minutos` entero 5..240; `minutos_para_responder` entero 1..30; `estado` (sólo si
     * `$exige_estado`) `actualizando` o `libre`.
     *
     * @param array<string, mixed> $entrada      Cuerpo del request.
     * @param bool                 $exige_estado Si `estado` es obligatorio (el PUT).
     * @return string|null El primer mensaje de error, o null si todo está bien.
     */
    public static function validar_entrada(array $entrada, $exige_estado = false)
    {
        if ($exige_estado) {
            $estado = isset($entrada['estado']) ? $entrada['estado'] : null;

            if (!in_array($estado, [ActualizacionDelSistema::ESTADO_ACTUALIZANDO, ActualizacionDelSistema::ESTADO_LIBRE], true)) {
                return 'El campo "estado" es obligatorio y tiene que ser "actualizando" o "libre".';
            }
        }

        $enteros = [
            'user_id'                => [1, null],
            'upgrade_id'             => [1, null],
            'vence_en_minutos'       => [5, 240],
            'minutos_para_responder' => [1, 30],
        ];

        foreach ($enteros as $campo => $rango) {
            if (!array_key_exists($campo, $entrada) || is_null($entrada[$campo])) {
                continue;
            }

            $valor = filter_var($entrada[$campo], FILTER_VALIDATE_INT);

            if ($valor === false || $valor < $rango[0] || (!is_null($rango[1]) && $valor > $rango[1])) {
                return 'El campo "' . $campo . '" no es válido.';
            }
        }

        if (array_key_exists('version', $entrada) && !is_null($entrada['version'])) {
            if (!is_string($entrada['version']) || strlen($entrada['version']) > 40) {
                return 'El campo "version" tiene que ser un texto de hasta 40 caracteres.';
            }
        }

        return null;
    }

    /**
     * El dueño sobre el que opera el admin: el `user_id` explícito (que tiene que ser un dueño) o, si no
     * viene, el dueño de la instancia (`owner_de_la_instancia()`, la misma fuente que usa el GET público).
     *
     * @param mixed $user_id `user_id` del cuerpo (ya validado como entero positivo) o null.
     * @return int|null Id del dueño, o null si no se puede resolver.
     */
    public static function resolver_owner_del_admin($user_id)
    {
        if (!is_null($user_id) && $user_id !== '') {
            $owner = User::where('id', (int) $user_id)->whereNull('owner_id')->first();

            return is_null($owner) ? null : (int) $owner->id;
        }

        return self::owner_de_la_instancia();
    }

    /**
     * ¿Existe la tabla del estado? (Durante el `down` del deploy, antes de migrar, no.) El admin lo lee como
     * 409 `tabla_pendiente` y vuelve a intentar después de las migraciones.
     *
     * @return bool
     */
    public static function tabla_disponible()
    {
        try {
            return DB::connection()->getSchemaBuilder()->hasTable('actualizaciones_del_sistema')
                && DB::connection()->getSchemaBuilder()->hasTable('actualizacion_dispositivos');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Lectura del admin (`GET`): evalúa las transiciones y devuelve el estado.
     *
     * @param int $owner_id
     * @return array{status: int, body: array<string, mixed>}
     */
    public static function leer_para_admin($owner_id)
    {
        self::evaluar($owner_id);

        return self::resultado_de_estado($owner_id);
    }

    /**
     * Prende (`actualizando`) o libera (`libre`) el estado, según pide el admin con `PUT`.
     *
     * Al prender: vence en `vence_en_minutos` (por defecto 120), libera el candado de sesión única del dueño
     * y de todos sus empleados, y avisa por broadcast. Si había un pedido de OK abierto, los pendientes
     * quedan `expulsado`. Prender sobre un `actualizando` lo RENUEVA (el pipeline lo hace en cada tramo).
     *
     * @param int                  $owner_id
     * @param array<string, mixed> $entrada Cuerpo ya validado (`estado`, `upgrade_id`, `version`, `vence_en_minutos`).
     * @return array{status: int, body: array<string, mixed>}
     */
    public static function aplicar_para_admin($owner_id, array $entrada)
    {
        $fila = DB::transaction(function () use ($owner_id, $entrada) {
            $fila = self::fila_con_lock($owner_id, true);

            if ($entrada['estado'] === ActualizacionDelSistema::ESTADO_LIBRE) {
                self::poner_libre($fila);

                return $fila;
            }

            if ($fila->estado === ActualizacionDelSistema::ESTADO_PIDIENDO_OK) {
                self::dispositivos_del_pedido($fila)
                    ->where('respuesta', ActualizacionDispositivo::RESPUESTA_PENDIENTE)
                    ->update([
                        'respuesta'    => ActualizacionDispositivo::RESPUESTA_EXPULSADO,
                        'respondio_at' => Carbon::now(),
                    ]);
            }

            if (isset($entrada['upgrade_id'])) {
                $fila->upgrade_id = (int) $entrada['upgrade_id'];
            }

            if (isset($entrada['version'])) {
                $fila->version = $entrada['version'];
            }

            $minutos = isset($entrada['vence_en_minutos']) ? (int) $entrada['vence_en_minutos'] : self::MINUTOS_POR_DEFECTO;

            self::poner_actualizando($fila, $minutos);

            return $fila;
        });

        self::olvidar_cache($owner_id);
        self::emitir($owner_id, $fila);

        return self::resultado_de_estado($owner_id);
    }

    /**
     * Abre un pedido de OK (`POST …/pedido`).
     *
     * Idempotente: si ya hay `pidiendo_ok` o `actualizando` devuelve el estado actual sin crear otro pedido.
     * Si no, los participantes son las computadoras del dueño vistas en los últimos 3 minutos; cada una queda
     * `pendiente`. Sin ninguna conectada no hay a quién esperar: pasa directo a `actualizando`.
     *
     * @param int                  $owner_id
     * @param array<string, mixed> $entrada Cuerpo ya validado (`upgrade_id`, `version`, `minutos_para_responder`).
     * @return array{status: int, body: array<string, mixed>}
     */
    public static function pedir_ok($owner_id, array $entrada)
    {
        $cambio = false;

        $fila = DB::transaction(function () use ($owner_id, $entrada, &$cambio) {
            $fila = self::fila_con_lock($owner_id, true);

            // Primero se ponen al día las transiciones: un `actualizando` vencido es `libre`.
            $cambio = self::aplicar_transiciones($fila);

            if (in_array($fila->estado, [ActualizacionDelSistema::ESTADO_PIDIENDO_OK, ActualizacionDelSistema::ESTADO_ACTUALIZANDO], true)) {
                return $fila;
            }

            $ahora = Carbon::now();
            $minutos = isset($entrada['minutos_para_responder']) ? (int) $entrada['minutos_para_responder'] : self::MINUTOS_PARA_RESPONDER;

            // Limpieza de presencia vieja: la tabla no crece sin límite.
            ActualizacionDispositivo::where('owner_id', (int) $owner_id)
                ->where('visto_at', '<', $ahora->copy()->subDays(self::DIAS_DE_LIMPIEZA))
                ->delete();

            $uuid = (string) \Illuminate\Support\Str::uuid();

            $fila->upgrade_id = isset($entrada['upgrade_id']) ? (int) $entrada['upgrade_id'] : $fila->upgrade_id;
            $fila->version = isset($entrada['version']) ? $entrada['version'] : $fila->version;
            $fila->pedido_uuid = $uuid;
            $fila->pedido_at = $ahora;
            $fila->vence_respuesta_at = $ahora->copy()->addMinutes($minutos);
            $fila->rechazado_at = null;
            $fila->rechazado_por_user_id = null;
            $fila->vence_at = null;

            // Participantes: las computadoras vistas en los últimos 3 minutos.
            $participantes = ActualizacionDispositivo::where('owner_id', (int) $owner_id)
                ->where('visto_at', '>=', $ahora->copy()->subMinutes(self::MINUTOS_DE_PRESENCIA))
                ->update([
                    'pedido_uuid'  => $uuid,
                    'respuesta'    => ActualizacionDispositivo::RESPUESTA_PENDIENTE,
                    'respondio_at' => null,
                ]);

            if ($participantes === 0) {
                // Nadie conectado: no hay a quién pedirle el OK, se sigue al instante.
                self::poner_actualizando($fila, self::MINUTOS_TRAS_EL_OK);
            } else {
                $fila->estado = ActualizacionDelSistema::ESTADO_PIDIENDO_OK;
                $fila->save();
            }

            $cambio = true;

            return $fila;
        });

        self::olvidar_cache($owner_id);

        if ($cambio) {
            self::emitir($owner_id, $fila);
        }

        return self::resultado_de_estado($owner_id);
    }

    /**
     * Cancela el pedido (`DELETE …/pedido`): vuelve a `libre` desde `pidiendo_ok`, `rechazado` o `libre`.
     * Con el sistema ya `actualizando` no lo suelta (409 `ya_actualizando`): para eso es `PUT estado=libre`.
     *
     * @param int $owner_id
     * @return array{status: int, body: array<string, mixed>}
     */
    public static function cancelar_pedido($owner_id)
    {
        $conflicto = false;
        $cambio = false;

        $fila = DB::transaction(function () use ($owner_id, &$conflicto, &$cambio) {
            $fila = self::fila_con_lock($owner_id);

            if (is_null($fila)) {
                return null;
            }

            self::aplicar_transiciones($fila);

            if ($fila->estado === ActualizacionDelSistema::ESTADO_ACTUALIZANDO) {
                $conflicto = true;

                return $fila;
            }

            if ($fila->estado !== ActualizacionDelSistema::ESTADO_LIBRE) {
                self::poner_libre($fila);
                $cambio = true;
            }

            return $fila;
        });

        self::olvidar_cache($owner_id);

        if ($conflicto) {
            return self::resultado(409, [
                'error'   => 'ya_actualizando',
                'message' => 'El sistema ya está actualizando: para soltarlo hay que liberar el estado.',
            ]);
        }

        if ($cambio) {
            self::emitir($owner_id, $fila);
        }

        return self::resultado_de_estado($owner_id);
    }

    /*
    |--------------------------------------------------------------------------
    | Operaciones con sesión (SPA)
    |--------------------------------------------------------------------------
    */

    /**
     * ¿Esta request viene de una computadora que cuenta para el pedido? Hace falta sesión de Laravel y que no
     * sea la maestra ni la de soporte (esas entran a mirar, no a trabajar).
     *
     * @param \Illuminate\Http\Request $request
     * @return bool
     */
    public static function es_computadora_contable(Request $request)
    {
        if (!$request->hasSession()) {
            return false;
        }

        return !MostradorHelper::es_sesion_maestra($request)
            && !$request->session()->has('acceso_soporte_token_id');
    }

    /**
     * El identificador de la computadora: sha256 del id de sesión (nunca se guarda el id).
     *
     * @param \Illuminate\Http\Request $request
     * @return string
     */
    protected static function hash_de_sesion(Request $request)
    {
        return hash('sha256', (string) $request->session()->getId());
    }

    /**
     * Deja registrada la presencia de esta computadora y, si hay un pedido abierto al que todavía no
     * pertenece, la suma como `pendiente` con el mismo plazo global (alguien que entra durante el pedido ve el
     * mismo modal). Escribe como mucho cada `SEGUNDOS_ENTRE_ESCRITURAS_DE_PRESENCIA` por computadora.
     *
     * @param \Illuminate\Http\Request                 $request
     * @param \App\Models\User                         $user
     * @param int                                      $owner_id
     * @param \App\Models\ActualizacionDelSistema|null $fila Estado ya evaluado.
     * @return void
     */
    protected static function registrar_presencia(Request $request, $user, $owner_id, $fila)
    {
        $ahora = Carbon::now();
        $sesion = self::hash_de_sesion($request);

        $pedido_abierto = !is_null($fila)
            && $fila->estado === ActualizacionDelSistema::ESTADO_PIDIENDO_OK
            && !is_null($fila->vence_respuesta_at)
            && $fila->vence_respuesta_at->gt($ahora);

        $dispositivo = ActualizacionDispositivo::where('owner_id', (int) $owner_id)
            ->where('sesion', $sesion)
            ->orderBy('id')
            ->first();

        if (is_null($dispositivo)) {
            $dispositivo = new ActualizacionDispositivo([
                'owner_id' => (int) $owner_id,
                'user_id'  => (int) $user->id,
                'sesion'   => $sesion,
                'visto_at' => $ahora,
            ]);
        } else {
            $reciente = !is_null($dispositivo->visto_at)
                && $dispositivo->visto_at->getTimestamp() > $ahora->getTimestamp() - self::SEGUNDOS_ENTRE_ESCRITURAS_DE_PRESENCIA;

            $ya_en_el_pedido = !$pedido_abierto || $dispositivo->pedido_uuid === $fila->pedido_uuid;

            if ($reciente && $ya_en_el_pedido && (int) $dispositivo->user_id === (int) $user->id) {
                return;
            }

            $dispositivo->user_id = (int) $user->id;
            $dispositivo->visto_at = $ahora;
        }

        if ($pedido_abierto && $dispositivo->pedido_uuid !== $fila->pedido_uuid) {
            $dispositivo->pedido_uuid = $fila->pedido_uuid;
            $dispositivo->respuesta = ActualizacionDispositivo::RESPUESTA_PENDIENTE;
            $dispositivo->respondio_at = null;
        }

        $dispositivo->save();
    }

    /**
     * El cuerpo que ve el SPA de una computadora (`GET estado` y `POST respuesta`, contrato §4.3).
     *
     * @param int                                      $owner_id
     * @param \App\Models\ActualizacionDelSistema|null $fila    Estado ya evaluado.
     * @param string|null                              $sesion  Hash de la sesión de quien pregunta (null si no cuenta).
     * @return array<string, mixed>
     */
    protected static function cuerpo_para_usuario($owner_id, $fila, $sesion)
    {
        $estado = is_null($fila) ? ActualizacionDelSistema::ESTADO_LIBRE : $fila->estado;
        $pedido = self::pedido_de($fila);

        $mi_respuesta = null;
        if (!is_null($pedido) && !is_null($sesion)) {
            $mi_respuesta = ActualizacionDispositivo::where('owner_id', (int) $owner_id)
                ->where('sesion', $sesion)
                ->where('pedido_uuid', (string) $fila->pedido_uuid)
                ->orderBy('id')
                ->value('respuesta');
        }

        $mensaje = null;
        if ($estado === ActualizacionDelSistema::ESTADO_ACTUALIZANDO) {
            $mensaje = self::MENSAJE_ACTUALIZANDO;
        } elseif ($estado === ActualizacionDelSistema::ESTADO_PIDIENDO_OK) {
            $mensaje = self::MENSAJE_PEDIDO;
        }

        return [
            'estado'                  => $estado,
            'mi_respuesta'            => $mi_respuesta,
            'total'                   => is_null($pedido) ? 0 : $pedido['total'],
            'aceptaron'               => is_null($pedido) ? 0 : $pedido['aceptaron'],
            'faltan'                  => is_null($pedido) ? 0 : $pedido['faltan'],
            'segundos_para_responder' => is_null($fila) ? 0 : self::segundos_para_responder($fila),
            'mensaje'                 => $mensaje,
        ];
    }

    /**
     * `GET api/actualizacion-del-sistema/estado`: es el polling de 60 s del SPA. Evalúa las transiciones,
     * registra la presencia de esta computadora (si cuenta) y devuelve el cuerpo del contrato.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\User         $user    Usuario autenticado.
     * @return array<string, mixed>
     */
    public static function estado_para_sesion(Request $request, $user)
    {
        $owner_id = self::owner_id_de($user);

        $fila = self::evaluar($owner_id);

        $sesion = null;

        if (self::es_computadora_contable($request)) {
            self::registrar_presencia($request, $user, $owner_id, $fila);
            $sesion = self::hash_de_sesion($request);
        }

        return self::cuerpo_para_usuario($owner_id, $fila, $sesion);
    }

    /**
     * `POST api/actualizacion-del-sistema/respuesta`: la computadora acepta o dice "Ahora no".
     *
     *  - `acepto`: queda aceptado y sigue trabajando; si era la última pendiente, el estado pasa a
     *    `actualizando` por 30 minutos y se cierran los candados.
     *  - `ahora_no`: el estado pasa a `rechazado` y se guarda quién fue (D3: se cancela la actualización).
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\User         $user      Usuario autenticado.
     * @param mixed                    $respuesta Lo que mandó el SPA.
     * @return array{status: int, body: array<string, mixed>} 200 con el cuerpo del contrato; 409 `sin_pedido`; 422.
     */
    public static function responder(Request $request, $user, $respuesta)
    {
        if (!in_array($respuesta, [ActualizacionDispositivo::RESPUESTA_ACEPTO, ActualizacionDispositivo::RESPUESTA_AHORA_NO], true)) {
            return self::resultado(422, [
                'error'   => 'validacion',
                'message' => 'La respuesta tiene que ser "acepto" o "ahora_no".',
            ]);
        }

        $owner_id = self::owner_id_de($user);
        $sesion = $request->hasSession() ? self::hash_de_sesion($request) : hash('sha256', 'sin-sesion-' . $user->id);

        $sin_pedido = false;
        $cambio = false;

        $fila = DB::transaction(function () use ($owner_id, $user, $respuesta, $sesion, &$sin_pedido, &$cambio) {
            $fila = self::fila_con_lock($owner_id);

            if (is_null($fila)) {
                $sin_pedido = true;

                return null;
            }

            $cambio = self::aplicar_transiciones($fila);

            if ($fila->estado !== ActualizacionDelSistema::ESTADO_PIDIENDO_OK) {
                $sin_pedido = true;

                return $fila;
            }

            // Una respuesta cambia lo que ven las demás computadoras (contadores o estado).
            $cambio = true;

            $dispositivo = self::dispositivos_del_pedido($fila)->where('sesion', $sesion)->orderBy('id')->first();

            if (is_null($dispositivo)) {
                // Contestó sin haber hecho polling antes: entra al pedido en este momento.
                $dispositivo = new ActualizacionDispositivo([
                    'owner_id'    => (int) $owner_id,
                    'sesion'      => $sesion,
                    'pedido_uuid' => $fila->pedido_uuid,
                ]);
            }

            $dispositivo->user_id = (int) $user->id;
            $dispositivo->visto_at = Carbon::now();
            $dispositivo->respuesta = $respuesta;
            $dispositivo->respondio_at = Carbon::now();
            $dispositivo->save();

            if ($respuesta === ActualizacionDispositivo::RESPUESTA_AHORA_NO) {
                $fila->estado = ActualizacionDelSistema::ESTADO_RECHAZADO;
                $fila->rechazado_at = Carbon::now();
                $fila->rechazado_por_user_id = (int) $user->id;
                $fila->save();
            } else {
                self::aplicar_transiciones($fila);
            }

            return $fila;
        });

        self::olvidar_cache($owner_id);

        if ($cambio) {
            self::emitir($owner_id, $fila);
        }

        if ($sin_pedido) {
            return self::resultado(409, [
                'error'   => 'sin_pedido',
                'message' => 'No hay un pedido de actualización abierto.',
            ]);
        }

        return self::resultado(200, self::cuerpo_para_usuario($owner_id, $fila, $sesion));
    }

    /*
    |--------------------------------------------------------------------------
    | GET público (pantalla de login, sin sesión)
    |--------------------------------------------------------------------------
    */

    /**
     * El dueño de ESTA instancia: la ÚNICA fuente de resolución del aviso (admin-sync sin `user_id` y GET
     * público). Es la misma regla del resto de `admin-sync` (`AsistenteCanalHelper::dueno()`):
     * `config('app.USER_ID')` y, si no está, el único dueño de la base. Con varios dueños y sin `USER_ID`
     * no se puede saber a cuál le habla el admin y se devuelve null: NUNCA "el primero" (en una base de 51
     * comercios bloquearía al equivocado).
     *
     * El middleware y el login resuelven el dueño del USUARIO (`owner_id_de($user)`): ése es el comercio al que
     * efectivamente se le corta la sesión, y coincide con el de la instancia en cualquier instalación sana.
     *
     * @return int|null Id del dueño, o null si no se puede decidir sin ambigüedad.
     */
    public static function owner_de_la_instancia()
    {
        $dueno = AsistenteCanalHelper::dueno();

        return is_null($dueno) ? null : (int) $dueno->id;
    }

    /**
     * `GET api/actualizacion-del-sistema/publico`: lo que necesita la pantalla de login. `actualizando` se
     * contesta con la marca 503; `libre`, `pidiendo_ok` y `rechazado` con 200 `{estado}`. Si el dueño no tiene
     * fila (lo normal) no escribe nada; sólo evalúa transiciones cuando hay algo que evaluar.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public static function respuesta_publica()
    {
        $owner_id = self::owner_de_la_instancia();

        $estado = ActualizacionDelSistema::ESTADO_LIBRE;

        if (!is_null($owner_id)) {
            try {
                $existe = ActualizacionDelSistema::where('owner_id', $owner_id)
                    ->where('estado', '<>', ActualizacionDelSistema::ESTADO_LIBRE)
                    ->exists();

                $fila = $existe ? self::evaluar($owner_id) : null;
            } catch (\Throwable $e) {
                // Tabla inexistente (base todavía sin migrar): la pantalla de login sigue como siempre.
                $fila = null;
            }

            if (!is_null($fila)) {
                $estado = $fila->estado;
            }
        }

        if ($estado === ActualizacionDelSistema::ESTADO_ACTUALIZANDO) {
            return self::respuesta_marca();
        }

        return response()->json(['estado' => $estado], 200);
    }
}
