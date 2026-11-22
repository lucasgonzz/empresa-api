<?php

namespace App\Http\Controllers\Helpers\ResetDatosNegocio;

use App\Exceptions\ResetDatosNegocioRechazadoException;
use App\Http\Controllers\Helpers\DemoSetupLockHelper;
use App\Http\Controllers\Helpers\import\article\ArticleIndexCache;
use App\Models\PlatformConnector;
use App\Models\ResetDatosNegocioRegistro;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Reset de datos de negocio de un cliente (misión reset-datos-cliente, 21/11/2026).
 *
 * Deja el sistema "igual que cuando se ejecutó el user setup": vacía con TRUNCATE (ids otra vez desde 1) las tablas
 * de negocio de `CatalogoDeTablasDelResetHelper::TABLAS_A_VACIAR` y conserva la configuración (usuarios, permisos,
 * extensiones, ARCA, listas, cajas, sucursales, plantillas...). Toda la lógica vive acá; el controlador sólo traduce
 * excepciones a HTTP.
 *
 * FLUJO (cada freno falla CERRADO y la base queda intacta):
 *   1. validación del pedido (lista cerrada de campos; 422 `validacion`);
 *   2. candado (`DemoSetupLockHelper`, el mismo de los setups): ocupado → 409 `en_curso`;
 *   3. SIMULACIÓN (cualquier `dry_run` que no sea false/0/'0'/'false'): cuenta lo que se vaciaría, no escribe nada
 *      en negocio, informa bloqueos y, si no hay ninguno, entrega un token de un solo uso (sólo su sha256 se
 *      guarda; vence a los 15 minutos);
 *   4. EJECUCIÓN: exige el nombre exacto de la base y un token vigente de ESTA base (409 `confirmacion_invalida`,
 *      el mismo mensaje para ambos, para no servir de oráculo), re-corre los chequeos de estado, escribe el
 *      respaldo SQL obligatorio, vacía, aplica los ajustes, mueve archivos, limpia caches y verifica.
 *
 * Chequeos de estado (también en la simulación, donde no frenan: se devuelven como `bloqueos`):
 * `varios_duenos`, `tablas_sin_clasificar`, `comprobantes_con_cae`, `integraciones_conectadas` y
 * `sin_espacio_para_respaldo`.
 *
 * TRUNCATE hace commit implícito: no hay transacción posible. La vuelta atrás es el respaldo, que se escribe y
 * verifica ANTES de vaciar. Cada simulación y cada ejecución (también las rechazadas y las fallidas) queda en
 * `reset_datos_negocio_registros` y como `Log::warning`, sin la clave de admin, sin el token y sin headers.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe, argumentos nombrados ni union types.
 */
class ResetDatosNegocioHelper
{
    /** Campos que acepta el pedido (contrato C1). Cualquier otro es 422. */
    const CAMPOS_PERMITIDOS = [
        'dry_run', 'confirmar_base_de_datos', 'token_de_simulacion', 'incluir_comprobantes_con_cae',
        'incluir_integraciones_conectadas', 'solicitante', 'origen', 'motivo',
    ];

    /** Minutos de vida del token de simulación. */
    const MINUTOS_DEL_TOKEN = 15;

    /** Largos máximos del contrato. */
    const LARGO_SOLICITANTE = 60;
    const LARGO_ORIGEN = 20;
    const LARGO_MOTIVO = 120;

    /** Margen sobre el tamaño de las tablas para estimar el respaldo (el gz comprime, así que es cota superior). */
    const MARGEN_DEL_RESPALDO = 1.2;

    /** Plataformas que publican artículos afuera con el id como SKU: si están conectadas, el reset se niega. */
    const PLATAFORMAS_QUE_FRENAN = ['tienda_nube', 'mercado_libre'];

    /** Mismo mensaje para nombre de base errado, token inexistente, vencido, usado o de otra base. */
    const MENSAJE_CONFIRMACION = 'La confirmación no es válida: hace falta el nombre exacto de la base y el token de una simulación reciente, sin usar, de esta misma base. Volvé a simular.';

    // ---------------------------------------------------------------------------------------------------------
    // Punto de entrada
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Procesa un pedido de reset: valida, toma el candado y simula o ejecuta.
     *
     * @param array $datos Cuerpo del pedido tal como llegó (JSON crudo, sin recortar espacios).
     * @param Request $request Pedido HTTP (sólo para IP y user agent del registro).
     * @return array Respuesta del contrato C1 (simulación o ejecución).
     * @throws ResetDatosNegocioRechazadoException Si el pedido es inválido, se rechaza o falla.
     */
    public static function procesar(array $datos, Request $request)
    {
        // 1) Pedido mal formado: 422 antes de crear nada (no deja registro, igual que un 401).
        $pedido = self::validar_pedido($datos);

        $base = (string) DB::connection()->getDatabaseName();

        // Contexto de la corrida: lo comparten los pasos para poder cerrar el registro con lo que se sabe.
        $contexto = [
            'registro' => self::abrir_registro($pedido, $base, $request),
            'conteos' => [],
            'total_filas' => 0,
            'respaldo' => null,
            'resultado' => [],
            'inicio' => microtime(true),
        ];

        $candado = false;

        try {
            // 2) Candado: no puede haber un reset junto a un user setup, un demo setup ni otro reset.
            $candado = DemoSetupLockHelper::tomar();

            if ($candado === false) {
                throw new ResetDatosNegocioRechazadoException(
                    'en_curso',
                    'Ya hay un setup o un reset corriendo en esta instancia. Esperá a que termine.',
                    409
                );
            }

            return $pedido['ejecutar']
                ? self::ejecutar($pedido, $base, $contexto)
                : self::simular($pedido, $base, $contexto);
        } catch (ResetDatosNegocioRechazadoException $e) {
            // Un 4xx es un rechazo (no pasó nada); un 5xx (`respaldo_fallido`, `reset_incompleto`) es una falla.
            self::cerrar_registro(
                $contexto,
                $e->http_status() >= 500 ? 'error' : 'rechazado',
                $e->codigo(),
                array_merge($contexto['resultado'], ['detalle' => $e->detalle()])
            );

            throw $e->con_registro($contexto['registro']['id']);
        } catch (\Throwable $e) {
            // Cualquier otra cosa es un error nuestro: se registra y se responde genérico (sin SQL ni trazas).
            self::cerrar_registro($contexto, 'error', 'error_inesperado', array_merge($contexto['resultado'], ['error' => mb_substr($e->getMessage(), 0, 300)]));

            throw (new ResetDatosNegocioRechazadoException(
                'error_inesperado',
                'El reset falló por un error inesperado. Revisá los registros antes de reintentar.',
                500
            ))->con_registro($contexto['registro']['id']);
        } finally {
            DemoSetupLockHelper::soltar($candado);
        }
    }

    /**
     * Últimos registros del reset para mostrar en el admin (nunca incluye el hash del token).
     *
     * @param int $limite Cuántos devolver (entre 1 y 50).
     * @return array<int, array> Registros del más nuevo al más viejo.
     */
    public static function registros($limite = 10)
    {
        $limite = max(1, min(50, (int) $limite));

        $filas = ResetDatosNegocioRegistro::query()
            ->orderBy('id', 'desc')
            ->limit($limite)
            ->get(['id', 'modo', 'estado', 'codigo', 'solicitante', 'origen', 'motivo', 'total_filas', 'respaldo_ruta', 'respaldo_bytes', 'started_at', 'finished_at']);

        $registros = [];
        foreach ($filas as $fila) {
            $registros[] = [
                'id' => (int) $fila->id,
                'modo' => $fila->modo,
                'estado' => $fila->estado,
                'codigo' => $fila->codigo,
                'solicitante' => $fila->solicitante,
                'origen' => $fila->origen,
                'motivo' => $fila->motivo,
                'total_filas' => (int) $fila->total_filas,
                'respaldo_ruta' => $fila->respaldo_ruta,
                'respaldo_bytes' => (int) $fila->respaldo_bytes,
                'started_at' => $fila->started_at === null ? null : $fila->started_at->toDateTimeString(),
                'finished_at' => $fila->finished_at === null ? null : $fila->finished_at->toDateTimeString(),
            ];
        }

        return $registros;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Validación del pedido
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Valida el pedido contra la lista cerrada del contrato y lo normaliza.
     *
     * No se usa `$request->validate()` a propósito: el contrato es cerrado (un campo desconocido es error), el
     * cuerpo se lee crudo para que `TrimStrings` no "arregle" un nombre de base con espacios, y el error tiene la
     * forma del contrato (`{ok:false, error:'validacion', detalle:{campo:[mensajes]}}`).
     *
     * `dry_run` no se valida: sólo `false`, `0`, `'0'` y `'false'` ejecutan; cualquier otra cosa (o su ausencia) es
     * una simulación, para que un valor raro nunca borre por accidente.
     *
     * @param array $datos Cuerpo crudo.
     * @return array{ejecutar: bool, confirmar: string|null, token: string|null, incluir_cae: bool, incluir_integraciones: bool, solicitante: string, origen: string, motivo: string|null}
     * @throws ResetDatosNegocioRechazadoException 422 `validacion`.
     */
    public static function validar_pedido(array $datos)
    {
        $errores = [];

        foreach (array_keys($datos) as $campo) {
            if (!in_array($campo, self::CAMPOS_PERMITIDOS, true)) {
                $errores[(string) $campo][] = 'Parámetro no permitido.';
            }
        }

        // Textos opcionales con largo máximo.
        $solicitante = self::texto_opcional($datos, 'solicitante', self::LARGO_SOLICITANTE, $errores);
        $origen = self::texto_opcional($datos, 'origen', self::LARGO_ORIGEN, $errores);
        $motivo = self::texto_opcional($datos, 'motivo', self::LARGO_MOTIVO, $errores);
        $confirmar = self::texto_opcional($datos, 'confirmar_base_de_datos', 255, $errores);
        $token = self::texto_opcional($datos, 'token_de_simulacion', 255, $errores);

        // Banderas que levantan un freno: sólo un booleano claro las prende.
        $incluir_cae = self::bandera($datos, 'incluir_comprobantes_con_cae', $errores);
        $incluir_integraciones = self::bandera($datos, 'incluir_integraciones_conectadas', $errores);

        if (count($errores) > 0) {
            throw new ResetDatosNegocioRechazadoException('validacion', 'El pedido no cumple el contrato: revisá los campos indicados.', 422, $errores);
        }

        $dry_run = array_key_exists('dry_run', $datos) ? $datos['dry_run'] : true;

        return [
            'ejecutar' => ($dry_run === false || $dry_run === 0 || $dry_run === '0' || $dry_run === 'false'),
            'confirmar' => $confirmar,
            'token' => $token,
            'incluir_cae' => $incluir_cae,
            'incluir_integraciones' => $incluir_integraciones,
            'solicitante' => $solicitante === null ? 'admin' : $solicitante,
            'origen' => $origen === null ? 'admin' : $origen,
            'motivo' => $motivo,
        ];
    }

    /**
     * Lee un campo de texto opcional: ausente, null o '' = null; un no-texto o uno demasiado largo es error.
     *
     * @param array $datos Cuerpo crudo.
     * @param string $campo Nombre del campo.
     * @param int $largo_maximo Largo máximo en caracteres.
     * @param array $errores Errores acumulados (se agrega por referencia).
     * @return string|null
     */
    protected static function texto_opcional(array $datos, $campo, $largo_maximo, array &$errores)
    {
        if (!array_key_exists($campo, $datos) || $datos[$campo] === null || $datos[$campo] === '') {
            return null;
        }

        if (!is_string($datos[$campo])) {
            $errores[$campo][] = 'Tiene que ser un texto.';

            return null;
        }

        if (mb_strlen($datos[$campo]) > $largo_maximo) {
            $errores[$campo][] = 'No puede superar ' . $largo_maximo . ' caracteres.';

            return null;
        }

        return $datos[$campo];
    }

    /**
     * Lee una bandera booleana opcional (true/false, 1/0, '1'/'0', 'true'/'false'); ausente o null = false.
     *
     * @param array $datos Cuerpo crudo.
     * @param string $campo Nombre del campo.
     * @param array $errores Errores acumulados (se agrega por referencia).
     * @return bool
     */
    protected static function bandera(array $datos, $campo, array &$errores)
    {
        if (!array_key_exists($campo, $datos) || $datos[$campo] === null) {
            return false;
        }

        if (is_array($datos[$campo])) {
            $errores[$campo][] = 'Tiene que ser verdadero o falso.';

            return false;
        }

        $valor = filter_var($datos[$campo], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($valor === null) {
            $errores[$campo][] = 'Tiene que ser verdadero o falso.';

            return false;
        }

        return $valor;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Simulación
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Simulación (dry-run): cuenta lo que se vaciaría, no escribe nada en negocio y, sin bloqueos, emite el token.
     *
     * @param array $pedido Pedido normalizado.
     * @param string $base Nombre de la base.
     * @param array $contexto Contexto de la corrida (por referencia no hace falta: se actualiza el registro).
     * @return array Respuesta C1 de simulación.
     */
    protected static function simular(array $pedido, $base, array &$contexto)
    {
        $conteos = self::contar_tablas_a_vaciar();
        $contexto['conteos'] = $conteos;
        $contexto['total_filas'] = (int) array_sum($conteos);

        $estimado = self::respaldo_estimado_bytes($conteos);
        $libre = self::espacio_libre_bytes();
        $bloqueos = self::chequeos_de_estado($pedido, $estimado, $libre);

        $ejecutable = count($bloqueos) === 0;
        $token = null;
        $vence = null;

        if ($ejecutable) {
            // 40 caracteres hexadecimales de 20 bytes aleatorios; en la base queda sólo su sha256.
            $token = bin2hex(random_bytes(20));
            $vence = Carbon::now()->addMinutes(self::MINUTOS_DEL_TOKEN);

            $contexto['registro']['token_hash'] = hash('sha256', $token);
            $contexto['registro']['token_vence_at'] = $vence->toDateTimeString();
        }

        $contexto['resultado'] = ['bloqueos' => $bloqueos, 'ejecutable' => $ejecutable];

        // Una simulación con bloqueos terminó bien (informó); el código deja a la vista el primero.
        self::cerrar_registro($contexto, 'ok', $ejecutable ? null : $bloqueos[0]['codigo'], $contexto['resultado']);

        return [
            'ok' => true,
            'dry_run' => true,
            'base' => $base,
            'ejecutable' => $ejecutable,
            'bloqueos' => $bloqueos,
            'a_vaciar' => (object) $conteos,
            'total_filas' => $contexto['total_filas'],
            'ajustes_previstos' => (object) self::contar_ajustes(),
            'archivos_previstos' => [
                'imagenes' => count(ArchivosDelResetHelper::imagenes_a_mover()),
                'carpetas' => (object) ArchivosDelResetHelper::carpetas_previstas(),
            ],
            'respaldo_estimado_bytes' => $estimado,
            'espacio_libre_bytes' => $libre,
            'token_de_simulacion' => $token,
            'token_vence_en' => $vence === null ? null : $vence->copy()->utc()->toIso8601ZuluString(),
            'registro_id' => (int) $contexto['registro']['id'],
        ];
    }

    // ---------------------------------------------------------------------------------------------------------
    // Ejecución
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Ejecución real: confirmación, chequeos, respaldo, vaciado, ajustes, archivos, cache y verificación.
     *
     * @param array $pedido Pedido normalizado.
     * @param string $base Nombre de la base.
     * @param array $contexto Contexto de la corrida.
     * @return array Respuesta C1 de ejecución.
     * @throws ResetDatosNegocioRechazadoException Si se rechaza o falla (nada borrado salvo `reset_incompleto`).
     */
    protected static function ejecutar(array $pedido, $base, array &$contexto)
    {
        // Un reset grande tarda minutos: si quien llama corta la conexión, el proceso sigue hasta terminar.
        @set_time_limit(0);
        @ignore_user_abort(true);

        // 1) Confirmación: nombre exacto de la base + token vigente de esta base.
        $simulacion = self::exigir_la_confirmacion($pedido, $base);

        // 2) Estado: se vuelve a chequear porque pudo cambiar desde la simulación.
        $conteos = self::contar_tablas_a_vaciar();
        $contexto['conteos'] = $conteos;
        $contexto['total_filas'] = (int) array_sum($conteos);

        $estimado = self::respaldo_estimado_bytes($conteos);
        $bloqueos = self::chequeos_de_estado($pedido, $estimado, self::espacio_libre_bytes());

        if (count($bloqueos) > 0) {
            $primero = $bloqueos[0];

            throw new ResetDatosNegocioRechazadoException($primero['codigo'], $primero['mensaje'], 409, $primero['detalle']);
        }

        // 3) Lo que se lee ANTES del vaciado: las imágenes que `images` referencia y el dueño (para la cache).
        $imagenes = ArchivosDelResetHelper::imagenes_a_mover();
        $id_del_dueno = DB::table('users')->whereNull('owner_id')->value('id');

        // 4) Respaldo obligatorio. Si falla, no se borró nada.
        $stamp = Carbon::now()->format('Ymd-His');
        $tablas_con_ajuste = self::tablas_con_ajuste();
        $esperadas = $conteos;
        foreach ($tablas_con_ajuste as $tabla) {
            $esperadas[$tabla] = (int) DB::table($tabla)->count();
        }

        try {
            $respaldo = RespaldoDelResetHelper::crear($stamp, $esperadas);
        } catch (RuntimeException $e) {
            throw new ResetDatosNegocioRechazadoException(
                'respaldo_fallido',
                'No se pudo escribir el respaldo previo, así que no se borró nada. ' . $e->getMessage(),
                500
            );
        }

        $contexto['respaldo'] = $respaldo;
        RespaldoDelResetHelper::aplicar_retencion();

        // 5) Se consume el token (un solo uso): recién ahora que el respaldo está hecho y antes de borrar.
        if (!self::consumir_el_token($simulacion)) {
            throw new ResetDatosNegocioRechazadoException('confirmacion_invalida', self::MENSAJE_CONFIRMACION, 409);
        }

        // 6) Desde acá TRUNCATE ya hizo (o va a hacer) commit: un fallo deja el reset incompleto y el respaldo es la vuelta atrás.
        try {
            $vaciadas_en_orden = self::vaciar_las_tablas();
            $ajustes = self::aplicar_ajustes();
            $archivos = ArchivosDelResetHelper::mover($imagenes, $stamp);
            $cache_limpiada = self::limpiar_caches($id_del_dueno);
            $verificacion = self::verificar_el_vaciado($vaciadas_en_orden);
        } catch (\Throwable $e) {
            // El motivo técnico queda en el log y en el registro (recortado); la respuesta no lleva SQL ni trazas.
            $contexto['resultado']['error'] = mb_substr($e->getMessage(), 0, 300);
            Log::error('ResetDatosNegocio: el reset quedó incompleto después del respaldo.', ['error' => $contexto['resultado']['error'], 'respaldo' => $respaldo['ruta_relativa']]);

            throw (new ResetDatosNegocioRechazadoException(
                'reset_incompleto',
                'El reset se interrumpió a mitad de camino: parte de los datos ya se borró. Restaurá desde el respaldo antes de reintentar.',
                500,
                ['respaldo' => $respaldo['ruta_relativa']]
            ));
        }

        RespaldoDelResetHelper::aplicar_retencion();

        $con_advertencias = count($archivos['errores']) > 0
            || count($ajustes['salteados']) > 0
            || count($verificacion['tablas_con_filas']) > 0
            || count($verificacion['auto_increment_sin_reiniciar']) > 0
            || !$cache_limpiada;
        $estado = $con_advertencias ? 'ok_con_advertencias' : 'ok';
        $duracion_ms = (int) round((microtime(true) - $contexto['inicio']) * 1000);

        $respuesta = [
            'ok' => true,
            'dry_run' => false,
            'estado' => $estado,
            'con_advertencias' => $con_advertencias,
            'base' => $base,
            'registro_id' => (int) $contexto['registro']['id'],
            'vaciadas' => (object) $conteos,
            'total_filas' => $contexto['total_filas'],
            'ajustes' => (object) $ajustes['aplicados'],
            'ajustes_salteados' => $ajustes['salteados'],
            'respaldo' => [
                'ruta_relativa' => $respaldo['ruta_relativa'],
                'bytes' => $respaldo['bytes'],
                'sha256' => $respaldo['sha256'],
            ],
            'archivos' => [
                'movidos' => $archivos['movidos'],
                'carpetas' => $archivos['carpetas'],
                'errores' => $archivos['errores'],
            ],
            'verificacion' => $verificacion,
            'cache_limpiada' => $cache_limpiada,
            'duracion_ms' => $duracion_ms,
        ];

        $contexto['resultado'] = [
            'ajustes' => $ajustes['aplicados'],
            'ajustes_salteados' => $ajustes['salteados'],
            'archivos' => $respuesta['archivos'],
            'verificacion' => $verificacion,
            'cache_limpiada' => $cache_limpiada,
            'duracion_ms' => $duracion_ms,
        ];

        self::cerrar_registro($contexto, $estado, null, $contexto['resultado']);

        return $respuesta;
    }

    /**
     * Exige la confirmación de la ejecución: nombre exacto de la base y token de una simulación vigente.
     *
     * 🔴 El mismo 409 `confirmacion_invalida` y el mismo mensaje para cualquier falla (nombre errado, token
     * ausente, desconocido, vencido, usado o de otra base): distinguirlos serviría para adivinar el nombre de la base.
     * El nombre se compara con `hash_equals` (sensible a mayúsculas, sin recortar espacios), igual que
     * `BorradoTotalDeBaseHelper::esta_autorizado`.
     *
     * @param array $pedido Pedido normalizado.
     * @param string $base Nombre real de la base.
     * @return ResetDatosNegocioRegistro La fila de la simulación a la que pertenece el token.
     * @throws ResetDatosNegocioRechazadoException 409 `confirmacion_invalida`.
     */
    protected static function exigir_la_confirmacion(array $pedido, $base)
    {
        $nombre_ok = $pedido['confirmar'] !== null && hash_equals($base, (string) $pedido['confirmar']);

        $simulacion = null;
        if ($pedido['token'] !== null) {
            $simulacion = ResetDatosNegocioRegistro::query()
                ->where('modo', 'simulacion')
                ->where('token_hash', hash('sha256', $pedido['token']))
                ->where('base', $base)
                ->whereNull('token_usado_at')
                ->where('token_vence_at', '>', Carbon::now())
                ->first();
        }

        if (!$nombre_ok || $simulacion === null) {
            throw new ResetDatosNegocioRechazadoException('confirmacion_invalida', self::MENSAJE_CONFIRMACION, 409);
        }

        return $simulacion;
    }

    /**
     * Marca el token como usado de forma atómica: sólo una ejecución puede consumirlo.
     *
     * @param ResetDatosNegocioRegistro $simulacion Fila de la simulación.
     * @return bool true si esta llamada lo consumió; false si otro se le adelantó.
     */
    protected static function consumir_el_token(ResetDatosNegocioRegistro $simulacion)
    {
        $afectadas = DB::table('reset_datos_negocio_registros')
            ->where('id', $simulacion->id)
            ->whereNull('token_usado_at')
            ->update(['token_usado_at' => Carbon::now()->toDateTimeString()]);

        return $afectadas === 1;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Chequeos de estado
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Chequeos de estado que se niegan a vaciar. En la simulación se informan; en la ejecución frena el primero.
     *
     * @param array $pedido Pedido normalizado (banderas `incluir_cae` e `incluir_integraciones`).
     * @param int $respaldo_estimado_bytes Tamaño estimado del respaldo.
     * @param int|null $espacio_libre_bytes Espacio libre en disco, o null si el servidor no lo informa.
     * @return array<int, array{codigo: string, mensaje: string, detalle: array}> Bloqueos, en orden de prioridad.
     */
    protected static function chequeos_de_estado(array $pedido, $respaldo_estimado_bytes, $espacio_libre_bytes)
    {
        $bloqueos = [];

        // Varios dueños (o ninguno): un TRUNCATE borraría a todos; el reset no adivina de quién es la base.
        $duenos = (int) DB::table('users')->whereNull('owner_id')->count();
        if ($duenos !== 1) {
            $bloqueos[] = [
                'codigo' => 'varios_duenos',
                'mensaje' => 'La base tiene ' . $duenos . ' dueños y el reset sólo funciona con exactamente uno: vaciar las tablas borraría los datos de todos.',
                'detalle' => ['duenos' => $duenos],
            ];
        }

        // Una tabla nueva que nadie clasificó: no se adivina si es negocio o configuración.
        $sin_clasificar = CatalogoDeTablasDelResetHelper::tablas_sin_clasificar();
        if (count($sin_clasificar) > 0) {
            $bloqueos[] = [
                'codigo' => 'tablas_sin_clasificar',
                'mensaje' => 'La base tiene tablas que el reset no conoce (versión del código y de la base desfasadas): ' . implode(', ', $sin_clasificar) . '.',
                'detalle' => ['tablas' => $sin_clasificar],
            ];
        }

        // Facturas con CAE de un punto de venta de producción: borrarlas no las anula en ARCA.
        if (!$pedido['incluir_cae']) {
            $con_cae = self::contar_comprobantes_con_cae_de_produccion();
            if ($con_cae > 0) {
                $bloqueos[] = [
                    'codigo' => 'comprobantes_con_cae',
                    'mensaje' => 'Hay ' . $con_cae . ' comprobantes con CAE de producción. Borrarlos del sistema NO los anula en ARCA. Si corresponde, anulalos con nota de crédito antes; si querés seguir igual, marcá "incluir comprobantes con CAE" (el respaldo los conserva).',
                    'detalle' => ['cantidad' => $con_cae],
                ];
            }
        }

        // Tienda Nube / Mercado Libre conectados: sus productos publicados usan el id como SKU y no se despublican.
        if (!$pedido['incluir_integraciones']) {
            $plataformas = self::plataformas_conectadas_que_frenan();
            if (count($plataformas) > 0) {
                $bloqueos[] = [
                    'codigo' => 'integraciones_conectadas',
                    'mensaje' => 'Hay integraciones conectadas (' . implode(', ', $plataformas) . '). Los productos publicados afuera NO se despublican y con los ids reiniciados el próximo sync los confundiría. Desconectalas antes o marcá "incluir integraciones conectadas".',
                    'detalle' => ['plataformas' => $plataformas],
                ];
            }
        }

        // Disco: el respaldo tiene que entrar. Si el servidor no informa el espacio libre no se frena por eso
        // (el propio volcado falla cerrado si no hay lugar).
        if ($espacio_libre_bytes !== null && $espacio_libre_bytes < $respaldo_estimado_bytes) {
            $bloqueos[] = [
                'codigo' => 'sin_espacio_para_respaldo',
                'mensaje' => 'No hay espacio en disco para el respaldo previo (hacen falta unos ' . $respaldo_estimado_bytes . ' bytes y hay ' . $espacio_libre_bytes . ').',
                'detalle' => ['necesario_bytes' => $respaldo_estimado_bytes, 'libre_bytes' => $espacio_libre_bytes],
            ];
        }

        return $bloqueos;
    }

    /**
     * Cantidad de comprobantes con CAE cuyo punto de venta (`afip_information`) es de producción.
     *
     * Cuenta también los borrados lógicos: siguen existiendo en ARCA.
     *
     * @return int
     */
    protected static function contar_comprobantes_con_cae_de_produccion()
    {
        if (!Schema::hasTable('afip_tickets') || !Schema::hasTable('afip_information')) {
            return 0;
        }

        return (int) DB::table('afip_tickets')
            ->join('afip_information', 'afip_information.id', '=', 'afip_tickets.afip_information_id')
            ->whereNotNull('afip_tickets.cae')
            ->where('afip_tickets.cae', '!=', '')
            ->where('afip_information.afip_ticket_production', 1)
            ->count();
    }

    /**
     * Slugs de las plataformas que publican artículos afuera y hoy están conectadas.
     *
     * @return array<int, string>
     */
    protected static function plataformas_conectadas_que_frenan()
    {
        if (!Schema::hasTable('platform_connectors') || !Schema::hasTable('platforms')) {
            return [];
        }

        $slugs = DB::table('platform_connectors')
            ->join('platforms', 'platforms.id', '=', 'platform_connectors.platform_id')
            ->where('platform_connectors.status', PlatformConnector::STATUS_CONECTADO)
            ->whereIn('platforms.slug', self::PLATAFORMAS_QUE_FRENAN)
            ->distinct()
            ->pluck('platforms.slug')
            ->all();

        sort($slugs);

        return $slugs;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Conteos, estimaciones y espacio
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Filas de cada tabla a vaciar que existe y tiene filas.
     *
     * @return array<string, int> tabla => filas (sólo las que tienen alguna).
     */
    protected static function contar_tablas_a_vaciar()
    {
        $existentes = array_flip(CatalogoDeTablasDelResetHelper::tablas_reales());
        $conteos = [];

        foreach (CatalogoDeTablasDelResetHelper::TABLAS_A_VACIAR as $tabla) {
            if (!isset($existentes[$tabla])) {
                continue;
            }

            $filas = (int) DB::table($tabla)->count();
            if ($filas > 0) {
                $conteos[$tabla] = $filas;
            }
        }

        return $conteos;
    }

    /**
     * Tablas que llevan algún ajuste y existen en la base (se vuelcan completas en el respaldo).
     *
     * @return array<int, string>
     */
    protected static function tablas_con_ajuste()
    {
        $existentes = array_flip(CatalogoDeTablasDelResetHelper::tablas_reales());
        $tablas = [];

        foreach (CatalogoDeTablasDelResetHelper::AJUSTES as $ajuste) {
            if (isset($existentes[$ajuste['tabla']]) && !in_array($ajuste['tabla'], $tablas, true)) {
                $tablas[] = $ajuste['tabla'];
            }
        }

        return $tablas;
    }

    /**
     * Tamaño estimado del respaldo: lo que ocupan las tablas a volcar × 1,2 (el gzip comprime, así que sobra).
     *
     * @param array<string, int> $conteos Tablas a vaciar con filas.
     * @return int Bytes.
     */
    protected static function respaldo_estimado_bytes(array $conteos)
    {
        $tablas = array_unique(array_merge(array_keys($conteos), self::tablas_con_ajuste()));

        if (count($tablas) === 0) {
            return 0;
        }

        $longitudes = DB::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->whereIn('TABLE_NAME', $tablas)
            ->pluck('DATA_LENGTH', 'TABLE_NAME')
            ->all();

        $total = 0;
        foreach ($tablas as $tabla) {
            $longitud = isset($longitudes[$tabla]) ? (int) $longitudes[$tabla] : 0;

            // information_schema puede informar 0 de una tabla recién escrita: se estima por filas (256 bytes).
            if (isset($conteos[$tabla])) {
                $longitud = max($longitud, $conteos[$tabla] * 256);
            }

            $total += max($longitud, 16384);
        }

        return (int) ceil($total * self::MARGEN_DEL_RESPALDO);
    }

    /**
     * Espacio libre en el disco donde va el respaldo.
     *
     * @return int|null Bytes, o null si el servidor no lo informa (`disk_free_space` deshabilitada o sin cuota visible).
     */
    protected static function espacio_libre_bytes()
    {
        $libre = @disk_free_space(storage_path('app'));

        return $libre === false ? null : (int) $libre;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Vaciado, ajustes, caches y verificación
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Vacía con TRUNCATE todas las tablas de la lista que existen (tengan o no filas: así se reinicia el AUTO_INCREMENT).
     *
     * `SET FOREIGN_KEY_CHECKS=0` durante el vaciado (por si alguna base vieja conserva una foreign key) y de nuevo
     * en 1 en el `finally`.
     *
     * @return array<int, string> Tablas vaciadas.
     */
    protected static function vaciar_las_tablas()
    {
        $conexion = DB::connection();
        $existentes = array_flip(CatalogoDeTablasDelResetHelper::tablas_reales());
        $vaciadas = [];

        $conexion->statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach (CatalogoDeTablasDelResetHelper::TABLAS_A_VACIAR as $tabla) {
                if (!isset($existentes[$tabla])) {
                    continue;
                }

                $conexion->statement('TRUNCATE TABLE `' . $tabla . '`');
                $vaciadas[] = $tabla;
            }
        } finally {
            $conexion->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        return $vaciadas;
    }

    /**
     * Cuántas filas tocaría cada ajuste hoy (para la simulación).
     *
     * @return array<string, int> clave del ajuste => filas.
     */
    protected static function contar_ajustes()
    {
        $previstos = [];

        foreach (CatalogoDeTablasDelResetHelper::AJUSTES as $clave => $ajuste) {
            if (!self::el_ajuste_es_aplicable($ajuste)) {
                continue;
            }

            $previstos[$clave] = (int) self::consulta_del_ajuste($ajuste)->count();
        }

        return $previstos;
    }

    /**
     * Aplica los ajustes sobre las filas que se conservan. Un ajuste cuya tabla o columna no existe se saltea.
     *
     * @return array{aplicados: array<string, int>, salteados: array<int, string>}
     */
    protected static function aplicar_ajustes()
    {
        $aplicados = [];
        $salteados = [];

        foreach (CatalogoDeTablasDelResetHelper::AJUSTES as $clave => $ajuste) {
            if (!self::el_ajuste_es_aplicable($ajuste)) {
                $salteados[] = $clave;
                continue;
            }

            $filas = (int) self::consulta_del_ajuste($ajuste)->count();

            if ($ajuste['accion'] === 'delete') {
                self::consulta_del_ajuste($ajuste)->delete();
            } else {
                self::consulta_del_ajuste($ajuste)->update($ajuste['set']);
            }

            $aplicados[$clave] = $filas;
        }

        return ['aplicados' => $aplicados, 'salteados' => $salteados];
    }

    /**
     * ¿Existen la tabla y todas las columnas que el ajuste toca o filtra?
     *
     * @param array $ajuste Definición del ajuste.
     * @return bool
     */
    protected static function el_ajuste_es_aplicable(array $ajuste)
    {
        if (!Schema::hasTable($ajuste['tabla'])) {
            return false;
        }

        $columnas = array_keys($ajuste['set']);
        if ($ajuste['filtro'] !== null) {
            $columnas[] = $ajuste['filtro']['columna'];
        }

        foreach ($columnas as $columna) {
            if (!Schema::hasColumn($ajuste['tabla'], $columna)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Consulta (sin ejecutar) sobre las filas que el ajuste alcanza.
     *
     * @param array $ajuste Definición del ajuste.
     * @return \Illuminate\Database\Query\Builder
     */
    protected static function consulta_del_ajuste(array $ajuste)
    {
        $consulta = DB::table($ajuste['tabla']);

        if ($ajuste['filtro'] !== null) {
            if ($ajuste['filtro']['operador'] === 'no_nulo') {
                $consulta->whereNotNull($ajuste['filtro']['columna']);
            } else {
                $consulta->where($ajuste['filtro']['columna'], 'like', $ajuste['filtro']['valor']);
            }
        }

        return $consulta;
    }

    /**
     * Limpia las caches que guardan ids de negocio: el índice de la importación de artículos del dueño y la cache general.
     *
     * Un fallo acá no deshace el vaciado: se informa como advertencia.
     *
     * @param int|null $id_del_dueno Id del dueño (null si no se pudo resolver).
     * @return bool true si se limpió todo.
     */
    protected static function limpiar_caches($id_del_dueno)
    {
        try {
            if ($id_del_dueno !== null) {
                ArticleIndexCache::limpiar_cache((int) $id_del_dueno);
            }

            Cache::flush();

            return true;
        } catch (\Throwable $e) {
            Log::warning('ResetDatosNegocio: no se pudo limpiar la cache después del vaciado.', ['error' => mb_substr($e->getMessage(), 0, 200)]);

            return false;
        }
    }

    /**
     * Verifica el vaciado: cada tabla vaciada sin filas y con el contador de ids reiniciado.
     *
     * Se mira `SHOW CREATE TABLE` y no `information_schema.TABLES`, que queda cacheado después de un TRUNCATE.
     * Algo que no cumpla (un cron que escribió en el medio, por ejemplo) no deshace nada: se informa.
     *
     * @param array<int, string> $tablas Tablas vaciadas.
     * @return array{tablas_con_filas: array<int, string>, auto_increment_sin_reiniciar: array<int, string>}
     */
    protected static function verificar_el_vaciado(array $tablas)
    {
        $con_filas = [];
        $sin_reiniciar = [];

        foreach ($tablas as $tabla) {
            if (DB::table($tabla)->exists()) {
                $con_filas[] = $tabla;
            }

            $fila = (array) DB::selectOne('SHOW CREATE TABLE `' . $tabla . '`');
            $sql = isset($fila['Create Table']) ? $fila['Create Table'] : '';

            if (preg_match('/AUTO_INCREMENT=(\d+)/', $sql, $coincidencias) === 1 && (int) $coincidencias[1] > 1) {
                $sin_reiniciar[] = $tabla . '=' . $coincidencias[1];
            }
        }

        return ['tablas_con_filas' => $con_filas, 'auto_increment_sin_reiniciar' => $sin_reiniciar];
    }

    // ---------------------------------------------------------------------------------------------------------
    // Registro
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Abre la fila de registro de esta corrida (estado `en_curso`) antes de hacer nada.
     *
     * Guarda IP y user agent (recortado a 120 caracteres) y nada más del pedido: nunca headers ni payload, porque
     * ahí viajan la clave de admin y el token.
     *
     * 🔴 Se escribe con `DB::table()` y NO con el modelo Eloquent: `AuditLogRecorder` audita todo evento de modelo y
     * dejaría dos filas en `audit_logs` por cada intento (una al abrir y otra al cerrar), o sea, una simulación
     * "sin efectos" tocaría una tabla de negocio. Esta tabla es de sistema y tiene su propio registro.
     *
     * @param array $pedido Pedido normalizado.
     * @param string $base Nombre de la base.
     * @param Request $request Pedido HTTP.
     * @return array Datos de la fila abierta: id, modo, base, solicitante, origen, motivo, ip, user_agent (y, si hay token, token_hash y token_vence_at).
     */
    protected static function abrir_registro(array $pedido, $base, Request $request)
    {
        $agente = $request->userAgent();
        $ahora = Carbon::now()->toDateTimeString();

        $registro = [
            'modo' => $pedido['ejecutar'] ? 'ejecucion' : 'simulacion',
            'base' => $base,
            'solicitante' => $pedido['solicitante'],
            'origen' => $pedido['origen'],
            'motivo' => $pedido['motivo'],
            'ip' => $request->ip(),
            'user_agent' => is_string($agente) ? mb_substr($agente, 0, 120, 'UTF-8') : null,
        ];

        $registro['id'] = (int) DB::table('reset_datos_negocio_registros')->insertGetId(array_merge($registro, [
            'estado' => 'en_curso',
            'started_at' => $ahora,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]));

        return $registro;
    }

    /**
     * Cierra la fila de registro con el estado final y escribe la misma información como `Log::warning`.
     *
     * @param array $contexto Contexto de la corrida (registro, conteos, total, respaldo).
     * @param string $estado `ok`, `ok_con_advertencias`, `rechazado` o `error`.
     * @param string|null $codigo Código del contrato si no salió todo bien.
     * @param array $resultado Datos extra para la columna `resultado`.
     * @return void
     */
    protected static function cerrar_registro(array $contexto, $estado, $codigo, array $resultado)
    {
        $registro = $contexto['registro'];
        $ahora = Carbon::now()->toDateTimeString();

        $cambios = [
            'estado' => $estado,
            'codigo' => $codigo,
            'conteos' => json_encode((object) $contexto['conteos']),
            'total_filas' => $contexto['total_filas'],
            'resultado' => json_encode($resultado),
            'finished_at' => $ahora,
            'updated_at' => $ahora,
        ];

        // Sólo una simulación ejecutable trae token: se guarda su hash y su vencimiento, nunca el token.
        if (isset($registro['token_hash'])) {
            $cambios['token_hash'] = $registro['token_hash'];
            $cambios['token_vence_at'] = $registro['token_vence_at'];
        }

        if ($contexto['respaldo'] !== null) {
            $cambios['respaldo_ruta'] = $contexto['respaldo']['ruta_relativa'];
            $cambios['respaldo_bytes'] = $contexto['respaldo']['bytes'];
            $cambios['respaldo_sha256'] = $contexto['respaldo']['sha256'];
        }

        DB::table('reset_datos_negocio_registros')->where('id', $registro['id'])->update($cambios);

        // Sin clave de admin, sin token, sin headers: sólo quién, desde dónde y cuánto.
        Log::warning('ResetDatosNegocio ' . $registro['modo'] . ': ' . $estado . ($codigo === null ? '' : ' (' . $codigo . ')'), [
            'registro_id' => $registro['id'],
            'base' => $registro['base'],
            'solicitante' => $registro['solicitante'],
            'origen' => $registro['origen'],
            'motivo' => $registro['motivo'],
            'total_filas' => $contexto['total_filas'],
            'tablas_con_filas' => count($contexto['conteos']),
            'respaldo' => $contexto['respaldo'] === null ? null : $contexto['respaldo']['ruta_relativa'],
            'ip' => $registro['ip'],
            'user_agent' => $registro['user_agent'],
        ]);
    }
}
