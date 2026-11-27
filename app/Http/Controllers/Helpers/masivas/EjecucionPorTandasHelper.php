<?php

namespace App\Http\Controllers\Helpers\masivas;

use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Http\Controllers\Helpers\MasiveUpdateHelper;
use App\Jobs\ProcessMasiveUpdateJob;
use App\Jobs\ProcessMasiveUpdateRevertJob;
use App\Models\Article;
use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Models\MasiveUpdate;
use App\Services\Filter\FilterHistoryService;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * Una tanda comparte capacidad con todas las bases del MISMO mysqld que participan del protocolo.
 * No es una cuota dura de CPU ni comprende otras consultas/importaciones o servidores MySQL.
 * El checkpoint y los efectos SQL se confirman juntos: repetir un job nunca repite porcentajes/stock.
 */
class EjecucionPorTandasHelper
{
    const CLAVE_GLOBAL = 'cc:masivas-articulos:v2:capacidad:0';
    const MAX_DESPACHOS = 3;
    /**
     * Techo técnico de artículos por actualización masiva (misión masiva-sin-tope-confirmar-75).
     * No es un freno de negocio (ese es la confirmación por porcentaje del inventario): protege que los
     * `whereIn` por id queden bajo los 65.535 placeholders de PDO y que el snapshot, que se reescribe en
     * cada tanda, no crezca sin control. Es la única fuente: MasiveUpdateHelper la usa también.
     */
    const MAXIMO_ARTICULOS = 50000;
    /** Segundos de vencimiento por tanda: cubre el shared hosting (1 tanda por minuto) con 50% de margen. */
    const SEGUNDOS_POR_TANDA = 90;
    private static $procesador_inservible = false;

    public static function es_de_articulos($modelo): bool
    {
        $nombre = $modelo instanceof MasiveUpdate ? $modelo->model_name : $modelo;
        return strtolower(GeneralHelper::getModelName((string) $nombre)) === strtolower(Article::class);
    }

    private static function consulta_articulos()
    {
        // GeneralHelper tolera mayúsculas, guiones y guiones bajos también en trabajos históricos.
        return MasiveUpdate::whereRaw("LOWER(REPLACE(REPLACE(model_name, '_', ''), '-', '')) = ?", ['article']);
    }

    public static function estado(MasiveUpdate $masiva)
    {
        $criterio = json_decode($masiva->criteria_json, true);
        return is_array($criterio) && isset($criterio['tandas']) && is_array($criterio['tandas'])
            && ($criterio['tandas']['version'] ?? null) === 2 ? $criterio['tandas'] : null;
    }

    /**
     * Segundos que tiene una masiva de $cantidad artículos para terminar.
     *
     * Con la cantidad de hoy (hasta 4.000 artículos, 80 tandas) da exactamente el vencimiento de siempre
     * (`deadline_segundos`, 7200); por encima crece a razón de SEGUNDOS_POR_TANDA por tanda. Sin esto una
     * masiva grande (27.580 artículos = 552 tandas) vencería a mitad de camino aunque se quite el tope.
     *
     * @param int $cantidad Artículos de la selección congelada.
     * @return int
     */
    public static function segundos_de_vencimiento(int $cantidad): int
    {
        $base = self::opcion('deadline_segundos', 7200, 1, 7200);
        $tandas = (int) ceil($cantidad / self::opcion('tamanio', 50, 1, 50));
        return max($base, $tandas * self::SEGUNDOS_POR_TANDA);
    }

    public static function inicializar(array $criterio): array
    {
        $ahora = now()->timestamp;
        $criterio['tandas'] = ['version' => 2, 'cursor' => 0, 'generacion' => 0, 'inicio' => $ahora,
            'deadline' => $ahora + self::segundos_de_vencimiento(count($criterio['resolved_models_id'] ?? [])),
            'no_antes' => $ahora, 'ultimo_despacho' => $ahora, 'despachos' => 1];
        return $criterio;
    }

    public static function prechequear($dueno)
    {
        $pendientes = self::consulta_articulos()->where('user_id', $dueno)->whereIn('status', ['pending', 'processing'])->count();
        return $pendientes >= self::opcion('pendientes', 2, 1, 2)
            ? ['status' => 429, 'body' => ['code' => 'masivas_ocupadas', 'retry_after' => 60,
                'message' => 'Ya hay actualizaciones de artículos pendientes. Esperá a que terminen antes de pedir otra.']]
            : null;
    }

    public static function encolar($dueno, callable $crear): array
    {
        try {
            self::verificar_cola();
            // La fila del dueño serializa también dos confirmaciones desde empleados distintos.
            return DB::transaction(function () use ($dueno, $crear) {
                $usuario = DB::table('users')->where('id', $dueno)->lockForUpdate()->first();
                if (!$usuario) {
                    throw new \RuntimeException('No existe el dueño de la operación.');
                }
                $pendientes = self::consulta_articulos()->where('user_id', $dueno)
                    ->whereIn('status', ['pending', 'processing'])->count();
                if ($pendientes >= self::opcion('pendientes', 2, 1, 2)) {
                    return ['status' => 429, 'body' => ['code' => 'masivas_ocupadas', 'retry_after' => 60,
                        'message' => 'Ya hay actualizaciones de artículos pendientes. Esperá a que terminen antes de pedir otra.']];
                }
                return ['status' => 200, 'masiva' => $crear()];
            });
        } catch (\Throwable $e) {
            self::registrar($e);
            return ['status' => 503, 'body' => ['code' => 'masivas_no_disponibles', 'retry_after' => 60,
                'message' => 'No se puede encolar la actualización ahora. Contactá a soporte si continúa.']];
        }
    }

    private static function verificar_cola()
    {
        if (Queue::connection() instanceof SyncQueue || Queue::connection() instanceof \Illuminate\Queue\NullQueue) {
            throw new \RuntimeException('La masiva requiere un worker de cola asíncrono; sync ignora las pausas.');
        }
    }

    /** Ejecuta una sola tanda; una entrega antigua o prematura no cambia ni redistribuye trabajo. */
    public static function ejecutar($id, $cursor_esperado = null, $generacion_esperada = null)
    {
        self::exigir_procesador_cli();
        $vista = MasiveUpdate::find($id);
        if (!$vista || !self::es_de_articulos($vista) || !in_array($vista->status, ['pending', 'processing'], true)) {
            return;
        }
        $conexion = DB::connection();
        $pdo = null;
        $tomados = [];
        $siguiente = null;
        $sin_capacidad = false;
        try {
            self::verificar_cola();
            $pdo = $conexion->getPdo(); // Siempre escritora: nunca select() que pueda ir a una réplica.
            if ($conexion->getDriverName() !== 'mysql') {
                throw new \RuntimeException('Las tandas requieren MySQL en la conexión escritora.');
            }
            $conexion->transaction(function () use ($conexion, $pdo, $vista, $cursor_esperado, $generacion_esperada, &$tomados, &$siguiente, &$sin_capacidad) {
                // Dentro de la transacción Laravel no reintenta una consulta reconectando sin candados.
                foreach ([self::CLAVE_GLOBAL, 'cc:masivas:v2:dueno:' . sha1($conexion->getDatabaseName() . ':' . $vista->user_id)] as $nombre) {
                    $consulta_dueno = $pdo->prepare('SELECT IS_USED_LOCK(?)');
                    $consulta_dueno->execute([$nombre]);
                    $dueno_actual = $consulta_dueno->fetchColumn();
                    if ($dueno_actual !== null && $dueno_actual !== false
                        && (int) $dueno_actual === (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn()) {
                        self::retirar_procesador();
                        throw new \RuntimeException('El procesador conserva un candado anterior; debe retirarse antes de admitir más tandas.');
                    }
                    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
                    $stmt->execute([$nombre]);
                    if ((int) $stmt->fetchColumn() !== 1) {
                        $sin_capacidad = true;
                        return; // Sin espera: la continuación se programa después de liberar lo propio.
                    }
                    $tomados[] = $nombre;
                }
                if ($conexion->getPdo() !== $pdo) {
                    throw new \RuntimeException('La conexión cambió durante la admisión.');
                }
                $masiva = MasiveUpdate::whereKey($vista->id)->lockForUpdate()->first();
                if (!$masiva || !in_array($masiva->status, ['pending', 'processing'], true)) {
                    return;
                }
                $masiva->model_name = 'article';
                // Una operación por dueño, también ENTRE tandas: la siguiente espera su turno.
                $primera = self::consulta_articulos()->where('user_id', $masiva->user_id)
                    ->whereIn('status', ['pending', 'processing'])->orderBy('id')->value('id');
                if ((int) $primera !== (int) $masiva->id) {
                    $sin_capacidad = true;
                    return;
                }
                $criterio = json_decode($masiva->criteria_json, true);
                $estado = self::estado($masiva);
                if ($estado === null) {
                    if (!is_array($criterio) || array_key_exists('tandas', $criterio)) {
                        throw new \RuntimeException('La versión del checkpoint no es recuperable. Contactá a soporte.');
                    }
                    if ($masiva->status !== 'pending') {
                        if ($masiva->updated_at->timestamp + 3600 > now()->timestamp) {
                            return; // Puede seguir ejecutándose con código anterior: no cancelarlo ni reaplicarlo.
                        }
                        throw new \RuntimeException('La operación anterior se interrumpió sin checkpoint. Soporte debe revisar sus cambios antes de reanudar.');
                    }
                    if ($masiva->action === 'revert') {
                        $criterio['resolved_models_id'] = DB::table('masive_update_article')
                            ->where('masive_update_id', $masiva->parent_masive_update_id)->orderBy('id')->limit(self::MAXIMO_ARTICULOS + 1)->pluck('id')->all();
                    }
                    if (empty($criterio['resolved_models_id']) || count($criterio['resolved_models_id']) > self::MAXIMO_ARTICULOS) {
                        throw new \RuntimeException('La selección anterior no tiene un snapshot válido.');
                    }
                    $criterio['resolved_models_id'] = array_values(array_unique(array_map('intval', $criterio['resolved_models_id'])));
                    $criterio = self::inicializar_legacy($masiva, $criterio);
                    $estado = $criterio['tandas'];
                }
                self::validar_estado($estado, $criterio);
                if (($cursor_esperado !== null && (int) $cursor_esperado !== $estado['cursor'])
                    || ($generacion_esperada !== null && (int) $generacion_esperada !== $estado['generacion'])
                    || ($cursor_esperado === null && $estado['generacion'] !== 0)) {
                    return;
                }
                if ($estado['deadline'] <= now()->timestamp) {
                    throw new \RuntimeException('La actualización venció sin terminar. Sus tandas confirmadas permanecen en el historial.');
                }
                if ($estado['no_antes'] > now()->timestamp) {
                    return;
                }
                $ids = array_slice($criterio['resolved_models_id'], $estado['cursor'], self::opcion('tamanio', 50, 1, 50));
                $masiva->status = 'processing';
                $masiva->criteria_json = json_encode($criterio);
                if ($masiva->action === 'revert') {
                    $padre = MasiveUpdate::findOrFail($masiva->parent_masive_update_id);
                    MasiveUpdateHelper::revert_article_pivot_changes($masiva, $padre, $ids);
                    $aplicado = DB::table('masive_update_article')->where('masive_update_id', $masiva->id)->count();
                    $masiva->affected_count = $aplicado;
                    // Contrato histórico de reversión: el contador de cambios del padre al finalizar.
                    $masiva->changes_count = $padre->changes_count;
                } else {
                    $por_id = Article::where('user_id', $masiva->user_id)->whereIn('id', $ids)->get()->keyBy('id');
                    $modelos = [];
                    foreach ($ids as $id_del_articulo) {
                        // La selección ya está congelada y sin duplicados; conservar su orden.
                        $modelos[] = $por_id->has($id_del_articulo) ? $por_id->get($id_del_articulo) : null;
                    }
                    $aplicado = MasiveUpdateHelper::aplicar_modelos($masiva, $modelos);
                    $masiva->affected_count += $aplicado['afectados'];
                    $masiva->changes_count += $aplicado['cambios'];
                }
                $estado['cursor'] += count($ids);
                $estado['generacion']++;
                $estado['no_antes'] = now()->timestamp + self::opcion('pausa', 5, 5, 3600);
                $estado['ultimo_despacho'] = now()->timestamp;
                $estado['despachos'] = 1;
                $criterio['tandas'] = $estado;
                $criterio['used_filters_resolved'] = $criterio['used_filters'] ?? [];
                $masiva->criteria_json = json_encode($criterio);
                $termino = $estado['cursor'] >= count($criterio['resolved_models_id']);
                if ($termino) {
                    $masiva->status = 'completed';
                    $masiva->error_message = null;
                    if ($masiva->action === 'revert') {
                        $padre->status = 'reverted';
                        $padre->reverted_at = now();
                        $padre->save();
                    } else {
                        FilterHistoryService::log_action(['user_id' => $masiva->user_id, 'auth_user_id' => $masiva->employee_id,
                            'action' => 'actualizacion', 'model_name' => 'article', 'filtrados_count' => count($criterio['resolved_models_id']),
                            'afectados_count' => $masiva->changes_count, 'used_filters' => $criterio['used_filters'] ?? []]);
                    }
                }
                $masiva->save();
                // Progreso y finalización pertenecen al mismo commit. El broadcast se difiere al commit.
                $proceso = BackgroundProcessHelper::por_referencia($masiva);
                BackgroundProcessHelper::avanzar($proceso, $estado['cursor'], ['total' => count($criterio['resolved_models_id']),
                    'etapa' => $termino ? 'Finalizando' : 'Esperando la siguiente tanda',
                    'resultado' => ['afectados' => $masiva->affected_count, 'cambios' => $masiva->changes_count]]);
                if ($termino) {
                    BackgroundProcessHelper::completar($proceso, ['afectados' => $masiva->affected_count, 'cambios' => $masiva->changes_count]);
                    $conexion->afterCommit(function () use ($masiva) { self::notificar($masiva, true); });
                } else {
                    $siguiente = $masiva;
                }
            });
        } catch (\Throwable $e) {
            self::registrar($e);
            self::fallar_si_vigente($id, $cursor_esperado, $generacion_esperada, $e->getMessage());
        } finally {
            // PDO original, sin reconectar ni soltar candados fiscales ajenos. No reemplazar un commit exitoso.
            foreach (array_reverse($tomados) as $nombre) {
                try {
                    $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                    $stmt->execute([$nombre]);
                    if ((int) $stmt->fetchColumn() !== 1) {
                        throw new \RuntimeException('No se pudo liberar la capacidad de masivas.');
                    }
                } catch (\Throwable $e) {
                    self::registrar($e);
                    self::retirar_procesador();
                }
            }
        }
        if ($sin_capacidad) {
            $siguiente = self::posponer_por_capacidad($id, $cursor_esperado, $generacion_esperada);
        }
        if ($siguiente !== null && !self::$procesador_inservible) {
            self::despachar($siguiente);
        }
    }

    private static function posponer_por_capacidad($id, $cursor, $generacion)
    {
        return DB::transaction(function () use ($id, $cursor, $generacion) {
            $masiva = MasiveUpdate::whereKey($id)->lockForUpdate()->first();
            $estado = $masiva ? self::estado($masiva) : null;
            if (!$masiva || $estado === null || !in_array($masiva->status, ['pending', 'processing'], true)
                || ($cursor !== null && $estado['cursor'] !== (int) $cursor)
                || ($generacion !== null && $estado['generacion'] !== (int) $generacion)
                || ($cursor === null && $estado['generacion'] !== 0)
                || $estado['no_antes'] > now()->timestamp) {
                return null;
            }
            self::validar_estado($estado, json_decode($masiva->criteria_json, true));
            if ($estado['deadline'] <= now()->timestamp) {
                MasiveUpdateHelper::mark_failed($masiva, 'La actualización venció esperando capacidad del servidor.');
                DB::afterCommit(function () use ($masiva) { self::notificar($masiva, false); });
                return null;
            }
            // Una entrega sí ocurrió. Esperar capacidad no consume el techo de entregas perdidas.
            $estado['generacion']++;
            $estado['despachos'] = 1;
            $estado['ultimo_despacho'] = now()->timestamp;
            $estado['no_antes'] = now()->timestamp + self::opcion('pausa', 5, 5, 3600);
            $criterio = json_decode($masiva->criteria_json, true);
            $criterio['tandas'] = $estado;
            $masiva->criteria_json = json_encode($criterio);
            $masiva->save();
            return $masiva;
        });
    }

    private static function validar_estado(array $estado, array $criterio)
    {
        foreach (['cursor', 'generacion', 'inicio', 'deadline', 'no_antes', 'ultimo_despacho', 'despachos'] as $clave) {
            if (!isset($estado[$clave]) || !is_int($estado[$clave]) || $estado[$clave] < 0) {
                throw new \RuntimeException('El checkpoint de la actualización es inválido.');
            }
        }
        $ids = $criterio['resolved_models_id'] ?? null;
        if (!is_array($ids) || count($ids) < 1 || count($ids) > self::MAXIMO_ARTICULOS || $estado['cursor'] > count($ids)
            || $estado['despachos'] < 1 || $estado['despachos'] > self::MAX_DESPACHOS
            || $estado['deadline'] <= $estado['inicio'] || $estado['deadline'] > $estado['inicio'] + self::segundos_de_vencimiento(count($ids))
            || $estado['no_antes'] < $estado['inicio'] || $estado['ultimo_despacho'] < $estado['inicio']
            || $estado['ultimo_despacho'] > $estado['deadline'] + 120 || $estado['no_antes'] > $estado['deadline'] + 3720) {
            throw new \RuntimeException('El snapshot o los límites del checkpoint son inválidos.');
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) {
                throw new \RuntimeException('El snapshot contiene un ID inválido.');
            }
        }
        if (count(array_unique($ids)) !== count($ids)) {
            throw new \RuntimeException('El snapshot contiene IDs repetidos.');
        }
    }

    public static function despachar(MasiveUpdate $masiva)
    {
        $estado = self::estado($masiva);
        try {
            self::verificar_cola();
            $job = $masiva->action === 'revert'
                ? new ProcessMasiveUpdateRevertJob($masiva->id, $estado['cursor'], $estado['generacion'])
                : new ProcessMasiveUpdateJob($masiva->id, $estado['cursor'], $estado['generacion']);
            dispatch($job->delay(now()->addSeconds(max(0, $estado['no_antes'] - now()->timestamp)))->afterCommit());
        } catch (\Throwable $e) {
            // Checkpoint durable: el reconciliador reintenta un número ACOTADO de despachos.
            self::registrar($e);
        }
    }

    public static function hay_pendientes(): bool
    {
        return self::consulta_articulos()->whereIn('status', ['pending', 'processing'])->exists();
    }

    public static function reconciliar(): int
    {
        self::exigir_procesador_cli();
        self::verificar_cola();
        $limite = self::opcion('reconciliar_maximo', 10, 1, 20);
        $candidatas = self::consulta_articulos()->whereIn('status', ['pending', 'processing'])
            ->orderBy('updated_at')->limit($limite)->get();
        $enviadas = 0;
        foreach ($candidatas as $vista) {
            try {
                $despachar = DB::transaction(function () use ($vista) {
                    $masiva = MasiveUpdate::whereKey($vista->id)->lockForUpdate()->first();
                    if (!$masiva || !in_array($masiva->status, ['pending', 'processing'], true)) {
                        return null;
                    }
                    $estado = self::estado($masiva);
                    if ($estado === null) {
                        $criterio_anterior = json_decode($masiva->criteria_json, true);
                        if (!is_array($criterio_anterior) || array_key_exists('tandas', $criterio_anterior)) {
                            throw new \RuntimeException('La versión del checkpoint no es recuperable.');
                        }
                        // Un job antiguo puede ya haber escrito: nunca volver a aplicar desde cero a ciegas.
                        if ($masiva->status === 'processing' && $masiva->updated_at->timestamp + 3600 > now()->timestamp) {
                            return null; // Puede seguir en un worker anterior: drenar antes de desplegar.
                        }
                        if ($masiva->status === 'processing' || $masiva->created_at->timestamp + 7200 <= now()->timestamp) {
                            MasiveUpdateHelper::mark_failed($masiva, 'La operación anterior no tiene checkpoint recuperable. Contactá a soporte.');
                            return null;
                        }
                        // Legacy pendiente: ejecutar inicializa una sola vez bajo la exclusión global.
                        $criterio = json_decode($masiva->criteria_json, true);
                        if ($masiva->action === 'revert') {
                            $criterio['resolved_models_id'] = DB::table('masive_update_article')->where('masive_update_id', $masiva->parent_masive_update_id)
                                ->orderBy('id')->limit(self::MAXIMO_ARTICULOS + 1)->pluck('id')->all();
                        }
                        if (empty($criterio['resolved_models_id']) || count($criterio['resolved_models_id']) > self::MAXIMO_ARTICULOS) {
                            MasiveUpdateHelper::mark_failed($masiva, 'La operación anterior no tiene selección recuperable.');
                            return null;
                        }
                        $criterio['resolved_models_id'] = array_values(array_unique(array_map('intval', $criterio['resolved_models_id'])));
                        $criterio = self::inicializar_legacy($masiva, $criterio);
                        $criterio['tandas']['ultimo_despacho'] = $masiva->created_at->timestamp;
                        $masiva->criteria_json = json_encode($criterio);
                        $estado = $criterio['tandas'];
                    }
                    self::validar_estado($estado, json_decode($masiva->criteria_json, true));
                    if ($estado['deadline'] <= now()->timestamp) {
                        MasiveUpdateHelper::mark_failed($masiva, 'La actualización no pudo continuar a tiempo. Las tandas confirmadas siguen en el historial. Contactá a soporte.');
                        DB::afterCommit(function () use ($masiva) { self::notificar($masiva, false); });
                        return null;
                    }
                    if ($estado['no_antes'] > now()->timestamp || $estado['ultimo_despacho'] + self::opcion('reconciliar_cada', 120, 60, 3600) > now()->timestamp) {
                        return null;
                    }
                    if ($estado['despachos'] >= self::MAX_DESPACHOS) {
                        MasiveUpdateHelper::mark_failed($masiva, 'La actualización no pudo continuar a tiempo. Las tandas confirmadas siguen en el historial. Contactá a soporte.');
                        DB::afterCommit(function () use ($masiva) { self::notificar($masiva, false); });
                        return null;
                    }
                    $criterio = json_decode($masiva->criteria_json, true);
                    $criterio['tandas']['ultimo_despacho'] = now()->timestamp;
                    $criterio['tandas']['despachos']++;
                    $masiva->criteria_json = json_encode($criterio);
                    $masiva->save();
                    return $masiva;
                });
                if ($despachar !== null) {
                    self::despachar($despachar);
                    $enviadas++;
                }
            } catch (\Throwable $e) {
                self::registrar($e);
                $estado_visto = self::estado($vista);
                self::fallar_si_vigente($vista->id, $estado_visto['cursor'] ?? null, $estado_visto['generacion'] ?? null,
                    'No se pudo recuperar el checkpoint. Contactá a soporte.');
            }
        }
        return $enviadas;
    }

    private static function inicializar_legacy(MasiveUpdate $masiva, array $criterio): array
    {
        if ($masiva->created_at->timestamp + 7200 <= now()->timestamp) {
            throw new \RuntimeException('La operación anterior venció antes de iniciar; no se reaplican sus cambios.');
        }
        $criterio = self::inicializar($criterio);
        $criterio['tandas']['inicio'] = $masiva->created_at->timestamp;
        $criterio['tandas']['deadline'] = $masiva->created_at->timestamp + self::segundos_de_vencimiento(count($criterio['resolved_models_id'] ?? []));
        return $criterio;
    }

    private static function exigir_procesador_cli()
    {
        // PHP no comparte conexiones persistentes CLI entre procesos. FPM sí: nunca ejecutar allí.
        if (PHP_SAPI !== 'cli' || self::$procesador_inservible) {
            throw new \RuntimeException('Las tandas requieren un procesador CLI sano; soporte debe revisar el worker.');
        }
    }

    private static function retirar_procesador()
    {
        self::$procesador_inservible = true;
        try {
            if (app()->bound('queue.worker')) {
                app('queue.worker')->shouldQuit = true;
            }
        } catch (\Throwable $e) {
            self::registrar($e); // El flag local sigue cerrado aunque falle el contenedor al retirar.
        }
    }

    public static function fallar_si_vigente($id, $cursor, $generacion, $mensaje)
    {
        try {
            DB::transaction(function () use ($id, $cursor, $generacion, $mensaje) {
                $masiva = MasiveUpdate::whereKey($id)->lockForUpdate()->first();
                if (!$masiva || !in_array($masiva->status, ['pending', 'processing'], true)) {
                    return;
                }
                $estado = self::estado($masiva);
                if ($estado !== null && isset($estado['cursor'], $estado['generacion'])
                    && is_int($estado['cursor']) && is_int($estado['generacion'])
                    && (($cursor !== null && $estado['cursor'] !== (int) $cursor)
                    || ($generacion !== null && $estado['generacion'] !== (int) $generacion)
                    || ($cursor === null && $estado['generacion'] !== 0))) {
                    return;
                }
                MasiveUpdateHelper::mark_failed($masiva, $mensaje);
                DB::afterCommit(function () use ($masiva) { self::notificar($masiva, false); });
            });
        } catch (\Throwable $e) {
            self::registrar($e); // Si la BD está caída, el reconciliador conserva el estado durable anterior.
        }
    }

    private static function notificar(MasiveUpdate $masiva, $exito)
    {
        try {
            MasiveUpdateHelper::notify_result($masiva, $exito, $masiva->error_message);
        } catch (\Throwable $e) {
            self::registrar($e); // La notificación no vuelve a ejecutar una tanda confirmada.
        }
    }

    private static function opcion($clave, $default, $minimo, $maximo): int
    {
        $valor = config('proteccion_asistente.masivas.' . $clave, $default);
        if (filter_var($valor, FILTER_VALIDATE_INT) === false || (int) $valor < $minimo || (int) $valor > $maximo) {
            throw new \RuntimeException('Configuración inválida de tandas: ' . $clave);
        }
        return (int) $valor;
    }

    private static function registrar(\Throwable $e)
    {
        try {
            Log::error('La ejecución por tandas requiere atención de soporte.', ['clase' => get_class($e), 'motivo' => $e->getMessage()]);
        } catch (\Throwable $ignorado) {
        }
    }
}
