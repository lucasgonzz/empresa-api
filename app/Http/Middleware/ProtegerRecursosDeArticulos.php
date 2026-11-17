<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Helpers\UserHelper;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Contiene el costo de recorridos de artículos incluso cuando usan una cookie legítima.
 * No identifica si hubo un click humano: una automatización debajo de estos límites es
 * indistinguible. Importación, venta e impresión usan otros caminos y no consumen estos cupos.
 */
class ProtegerRecursosDeArticulos
{
    public function handle(Request $request, Closure $next)
    {
        $tipo = $this->tipo_costoso($request);
        if ($tipo === null || !config('recursos_articulos.activo', true)) {
            return $next($request);
        }

        // La ruta debe autenticar antes; nunca convertir USER_ID o una IP en identidad.
        if (!$request->user()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        $persona = UserHelper::user(false);
        $dueno = UserHelper::user(true);
        if (!$persona || !$dueno || (int) $persona->id < 1 || (int) $dueno->id < 1) {
            return response()->json(['message' => 'No se pudo resolver la cuenta autenticada.'], 403);
        }

        $locks = [];
        try {
            $store = Cache::store(config('recursos_articulos.store'));
            $backend = $store->getStore();
            if ($backend instanceof \Illuminate\Cache\ArrayStore || $backend instanceof \Illuminate\Cache\NullStore) {
                throw new \RuntimeException('La protección requiere un cache persistente compartido entre procesos.');
            }
            $rechazo = $this->admitir($store, (int) $persona->id, (int) $dueno->id, $tipo, $locks);
        } catch (\Throwable $e) {
            $this->liberar($locks);
            return $this->infraestructura_no_disponible($e);
        }

        if ($rechazo !== null) {
            if (!$this->liberar($locks)) {
                return $this->infraestructura_no_disponible(new \RuntimeException('Falla al liberar la admisión rechazada.'));
            }
            return $rechazo;
        }

        try {
            return $next($request);
        } finally {
            // Incluye excepciones del controller. Si la limpieza falla, conservar su resultado:
            // convertir una escritura confirmada en 503 induciría a repetirla. El TTL acota el lock.
            $this->liberar($locks);
        }
    }

    private function tipo_costoso(Request $request)
    {
        $ruta = $request->route();
        if (!$ruta) {
            return null;
        }
        $uri = $ruta->uri();
        $metodo = strtoupper($request->method());
        if (in_array($uri, ['api/article-update-addresses', 'api/article-update-varians-stock'], true) && $metodo === 'PUT') {
            return 'escritura';
        }
        // PATCH llama al mismo update, y MySQL puede coercionar parámetros como 1e0/1.0.
        // Se protege la operación resuelta, no una clasificación del texto enviado como id.
        if ($uri === 'api/article/{article}') {
            if (in_array($metodo, ['GET', 'HEAD'], true)) {
                return 'lectura';
            }
            if (in_array($metodo, ['PUT', 'PATCH'], true)) {
                return 'escritura';
            }
        }
        return null;
    }

    private function admitir($store, $persona, $dueno, $tipo, array &$locks)
    {
        $prefijo = 'recursos-articulos:v1:negocio:' . $dueno;
        $ttl = max(301, (int) config('recursos_articulos.lock_ttl', 360));
        $admision = $store->lock($prefijo . ':admision', $ttl);
        if (!$admision->get()) {
            return $this->demasiados(1);
        }

        try {
            foreach ([
                'persona:' . $persona => max(1, (int) config('recursos_articulos.concurrencia_persona', 1)),
                'negocio' => max(1, (int) config('recursos_articulos.concurrencia_negocio', 2)),
            ] as $clase => $cantidad) {
                $encontrado = false;
                for ($i = 0; $i < $cantidad; $i++) {
                    $lock = $store->lock($prefijo . ':ocupado:' . $clase . ':' . $i, $ttl);
                    if ($lock->get()) {
                        $locks[] = $lock;
                        $encontrado = true;
                        break;
                    }
                }
                if (!$encontrado) {
                    return $this->demasiados(1);
                }
            }

            $ahora = now()->timestamp;
            $contadores = [];
            foreach (['persona:' . $persona => 'persona', 'negocio' => 'negocio'] as $identidad => $clase) {
                foreach (['minuto' => 60, 'hora' => 3600] as $ventana => $segundos) {
                    $cupo = max(1, (int) config('recursos_articulos.' . $tipo . '.' . $clase . '_' . $ventana));
                    // FileStore solo borra un vencido cuando se vuelve a leer SU clave. Poner
                    // el bucket en el nombre dejaría archivos de cada minuto/hora para siempre.
                    // La clave es estable; el bucket vive en el valor y se renueva bajo admisión.
                    $clave = $prefijo . ':' . $identidad . ':' . $tipo . ':' . $ventana;
                    $bucket = intdiv($ahora, $segundos);
                    $guardado = $store->get($clave);
                    $contador = 0;
                    if ($guardado !== null) {
                        if (!is_array($guardado) || !isset($guardado['bucket'], $guardado['cantidad'])
                            || !is_int($guardado['bucket']) || !is_int($guardado['cantidad']) || $guardado['cantidad'] < 0) {
                            throw new \RuntimeException('El contador de recursos no tiene un formato válido.');
                        }
                        if ($guardado['bucket'] === $bucket) {
                            $contador = $guardado['cantidad'];
                        }
                    }
                    if ($contador >= $cupo) {
                        return $this->demasiados($segundos - ($ahora % $segundos));
                    }
                    $contadores[] = [$clave, ['bucket' => $bucket, 'cantidad' => $contador + 1], $segundos * 2];
                }
            }
            // El lock de admisión serializa comprobar + incrementar todas las ventanas del dueño.
            foreach ($contadores as $contador) {
                if (!$store->put($contador[0], $contador[1], $contador[2])) {
                    throw new \RuntimeException('No se pudo guardar el contador de recursos.');
                }
            }
            return null;
        } finally {
            if (!$admision->release()) {
                throw new \RuntimeException('No se pudo liberar la admisión de recursos.');
            }
        }
    }

    private function liberar(array $locks)
    {
        $error = null;
        foreach (array_reverse($locks) as $lock) {
            try {
                if (!$lock->release()) {
                    throw new \RuntimeException('No se pudo liberar la reserva de recursos.');
                }
            } catch (\Throwable $e) {
                $error = $e;
            }
        }
        if ($error !== null) {
            $this->registrar_error('No se pudo liberar una reserva de recursos de artículos; conserva su TTL.', $error);
        }
        return $error === null;
    }

    private function demasiados($segundos)
    {
        return response()->json([
            'code' => 'recursos_articulos_ocupados',
            'message' => 'Hay demasiadas consultas o cambios de artículos. Esperá antes de volver a intentar.',
        ], 429, ['Retry-After' => (string) max(1, $segundos)]);
    }

    private function infraestructura_no_disponible(\Throwable $error)
    {
        $this->registrar_error('No se pudo aplicar la protección de recursos de artículos.', $error);
        return response()->json([
            'code' => 'proteccion_articulos_no_disponible',
            'message' => 'No se puede procesar el artículo en este momento. Volvé a intentar en unos segundos.',
        ], 503, ['Retry-After' => '5']);
    }

    private function registrar_error($mensaje, \Throwable $error)
    {
        try {
            Log::error($mensaje, ['clase' => get_class($error)]);
        } catch (\Throwable $fallo_del_log) {
            // Un fallo del logger durante finally tampoco debe reemplazar una escritura exitosa.
            // Las reservas conservan su TTL y una admisión nueva sigue fallando cerrada.
        }
    }
}
