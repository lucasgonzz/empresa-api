<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiConversation;
use App\Models\AiMessageAction;
use App\Models\Article;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Presupuesto común del MCP y el asistente; no pretende distinguir scripts de personas. */
class PresupuestoDeOperacionesIaHelper
{
    public static function herramienta(AiConversation $conversacion, array &$reserva = [])
    {
        $rechazo = self::admitir($conversacion, 'herramientas', $reserva);
        if ($rechazo !== null) {
            self::liberar($reserva);
            $reserva = [];
        }
        return $rechazo;
    }

    public static function liberar_herramienta(array $reserva)
    {
        self::liberar($reserva);
    }

    public static function escritura(AiConversation $conversacion, AiMessageAction $accion, callable $ejecutar)
    {
        if (!self::es_de_articulos($accion)) {
            return $ejecutar();
        }
        $locks = [];
        $rechazo = self::admitir($conversacion, 'escrituras', $locks);
        if ($rechazo !== null) {
            self::liberar($locks);
            return $rechazo;
        }
        try {
            return $ejecutar();
        } finally {
            // Una limpieza fallida nunca transforma una carga confirmada en un error reintentable.
            self::liberar($locks);
        }
    }

    public static function es_de_articulos(AiMessageAction $accion): bool
    {
        if (in_array($accion->tipo, [AiMessageAction::TIPO_ACTUALIZACION_MASIVA,
            AiMessageAction::TIPO_MOVIMIENTO_STOCK, AiMessageAction::TIPO_STOCK_DEPOSITO,
            AiMessageAction::TIPO_AJUSTE_STOCK_LOTE, AiMessageAction::TIPO_FOTO_ARTICULO,
            AiMessageAction::TIPO_IMAGENES_ARTICULOS, AiMessageAction::TIPO_DESCRIPCION_VERIFICADA], true)) {
            return true;
        }
        // Misión asistente-diario-20261119 (rama C, A4): el renombre masivo escribe artículos como la masiva.
        if ($accion->tipo === AiMessageAction::TIPO_RENOMBRE_MASIVO) {
            return true;
        }
        // Misión asistente-diario-20261121 (M1): destacar un artículo también escribe un artículo.
        if ($accion->tipo === AiMessageAction::TIPO_DESTACADO_ARTICULO) {
            return true;
        }
        $datos = is_array($accion->datos) ? $accion->datos : [];
        if (in_array($accion->tipo, [AiMessageAction::TIPO_ALTA, AiMessageAction::TIPO_EDICION, AiMessageAction::TIPO_BAJA], true)) {
            $declaracion = CatalogoDeEscrituraIaHelper::declaracion((string) ($datos['entidad'] ?? ''));
            return $declaracion !== null && ($declaracion['tabla'] ?? null) === (new Article())->getTable();
        }
        if (in_array($accion->tipo, [AiMessageAction::TIPO_ACCION_PANTALLA, AiMessageAction::TIPO_BORRADO_PANTALLA], true)) {
            // Resolver con el router/catálogo vigente, igual que el ejecutor; no confiar en un prefijo entrante.
            $resuelta = EjecutorAccionDePantallaIaHelper::resolver($datos['metodo'] ?? '', $datos['ruta'] ?? '',
                (array) ($datos['parametros'] ?? []), (array) ($datos['cuerpo'] ?? []));
            if ($resuelta === null) {
                return false;
            }
            $controlador = $resuelta['ruta']->getActionName();
            return preg_match('/\\\\(?:Article|ArticleVariant|ArticleDiscount|ArticleSurchage|StockMovement)Controller@/', $controlador) === 1;
        }
        return false;
    }

    private static function admitir(AiConversation $conversacion, $tipo, array &$locks)
    {
        $admision = null;
        $admision_tomada = false;
        try {
            $persona = (int) $conversacion->auth_user_id;
            $dueno = (int) $conversacion->user_id;
            if ($persona < 1 || $dueno < 1) {
                throw new \RuntimeException('La conversación no identifica persona y negocio.');
            }
            $nombre = config('proteccion_asistente.store') ?: config('recursos_articulos.store');
            $store = Cache::store($nombre);
            $backend = $store->getStore();
            if ($backend instanceof \Illuminate\Cache\ArrayStore || $backend instanceof \Illuminate\Cache\NullStore) {
                throw new \RuntimeException('Los cupos del asistente requieren cache persistente con locks.');
            }
            $ttl = self::positivo('lock_ttl', 360, 301);
            $prefijo = 'asistente:v1:' . sha1(DB::connection()->getDatabaseName()) . ':negocio:' . $dueno;
            $admision = $store->lock($prefijo . ':admision', $ttl);
            if (!$admision->get()) {
                return self::rechazo(429, 1);
            }
            $admision_tomada = true;
            $contadores = [];
            $ahora = now()->timestamp;
            foreach (['persona:' . $persona => 'persona', 'negocio' => 'negocio'] as $identidad => $clase) {
                foreach (['minuto' => 60, 'hora' => 3600] as $ventana => $segundos) {
                    $cupo = self::positivo($tipo . '.' . $clase . '_' . $ventana, null);
                    $clave = $prefijo . ':' . $identidad . ':' . $tipo . ':' . $ventana;
                    $bucket = intdiv($ahora, $segundos);
                    $guardado = $store->get($clave);
                    if ($guardado !== null && (!is_array($guardado) || !isset($guardado['bucket'], $guardado['cantidad'])
                        || !is_int($guardado['bucket']) || !is_int($guardado['cantidad']) || $guardado['cantidad'] < 0)) {
                        throw new \RuntimeException('El contador de cupos es inválido.');
                    }
                    $cantidad = $guardado !== null && $guardado['bucket'] === $bucket ? $guardado['cantidad'] : 0;
                    if ($cantidad >= $cupo) {
                        return self::rechazo(429, $segundos - $ahora % $segundos);
                    }
                    // Claves estables: FileStore no acumula un archivo nuevo por cada ventana.
                    $contadores[] = [$clave, ['bucket' => $bucket, 'cantidad' => $cantidad + 1], $segundos * 2];
                }
            }
            {
                foreach (['persona:' . $persona => 'concurrencia_persona', 'negocio' => 'concurrencia_negocio'] as $clase => $opcion) {
                    $tomado = false;
                    for ($i = 0, $maximo = self::positivo($opcion, null); $i < $maximo; $i++) {
                        $lock = $store->lock($prefijo . ':ocupado:' . $tipo . ':' . $clase . ':' . $i, $ttl);
                        if ($lock->get()) {
                            $locks[] = $lock;
                            $tomado = true;
                            break;
                        }
                    }
                    if (!$tomado) {
                        return self::rechazo(429, 1);
                    }
                }
            }
            foreach ($contadores as $contador) {
                if (!$store->put($contador[0], $contador[1], $contador[2])) {
                    throw new \RuntimeException('No se pudo reservar el presupuesto.');
                }
            }
            return null;
        } catch (\Throwable $e) {
            self::registrar($e);
            return self::rechazo(503, 5);
        } finally {
            if ($admision_tomada) {
                try {
                    if (!$admision->release()) {
                        throw new \RuntimeException('No se pudo liberar la admisión.');
                    }
                } catch (\Throwable $e) {
                    self::registrar($e);
                    // Aún no se ejecutó ninguna carga: fallar cerrado si la limpieza de admisión falla.
                    return self::rechazo(503, 5);
                }
            }
        }
    }

    private static function positivo($clave, $default, $minimo = 1): int
    {
        $valor = config('proteccion_asistente.' . $clave, $default);
        if (filter_var($valor, FILTER_VALIDATE_INT) === false || (int) $valor < $minimo) {
            throw new \RuntimeException('Configuración inválida de cupos: ' . $clave);
        }
        return (int) $valor;
    }

    private static function rechazo($status, $segundos): array
    {
        return ['status' => $status, 'body' => [
            'ok' => false, 'code' => $status === 429 ? 'asistente_ocupado' : 'proteccion_asistente_no_disponible',
            'message' => $status === 429 ? 'Hay demasiadas operaciones. Esperá antes de volver a intentar.' : 'No se puede procesar esta operación ahora. Volvé a intentar más tarde.',
            'retry_after' => $segundos,
        ]];
    }

    private static function liberar(array $locks)
    {
        foreach (array_reverse($locks) as $lock) {
            try {
                if (!$lock->release()) {
                    throw new \RuntimeException('No se pudo liberar una reserva del asistente.');
                }
            } catch (\Throwable $e) {
                self::registrar($e);
            }
        }
    }

    private static function registrar(\Throwable $e)
    {
        try {
            Log::error('La protección del asistente requiere atención de soporte.', ['clase' => get_class($e), 'motivo' => $e->getMessage()]);
        } catch (\Throwable $ignorado) {
            // La limpieza posterior no debe reemplazar el resultado de una carga confirmada.
        }
    }
}
