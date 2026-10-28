<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Log;

/** Correlación optativa. Nunca consulta SQL ni captura parámetros, cuerpos o errores en bruto. */
class DiagnosticoConexion
{
    public function handle($request, Closure $next)
    {
        $usuarios = config('diagnostico_conexion.usuarios', []);
        // El apagado no resuelve siquiera Auth: no agrega consultas a los demás comercios.
        if (!$usuarios || !preg_match('/^[a-f0-9]{32}$/D', (string) $request->header('X-Diagnostico-Id'))) {
            return $next($request);
        }
        $persona = $request->user();
        if (!$persona || !in_array((string) $persona->id, $usuarios, true) || (string) $request->header('X-Diagnostico-Persona') !== (string) $persona->id) {
            return $next($request);
        }
        $ruta = $request->route();
        $contexto = [
            'request_id' => $request->header('X-Diagnostico-Id'),
            'auth_user_id' => (int) $persona->id,
            'owner_id' => (int) ($persona->owner_id ?: $persona->id),
            'metodo' => $request->method(),
            'ruta_template' => $ruta ? substr($ruta->uri(), 0, 200) : 'sin_ruta',
        ];
        $inicio = microtime(true);
        $request->attributes->set('diagnostico_conexion_activo', true);
        $this->anotar('INICIO', $contexto);
        try {
            $respuesta = $next($request);
        } catch (\Throwable $error) {
            $this->anotar('EXCEPCION', array_merge($contexto, [
                'duracion_ms' => (int) round((microtime(true) - $inicio) * 1000),
                'clase' => get_class($error),
                'status' => $error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $error->getStatusCode() : ($error instanceof \Illuminate\Validation\ValidationException ? 422 : 500),
            ]));
            throw $error;
        }
        $clase = $request->attributes->get('diagnostico_conexion_clase');
        $this->anotar($clase ? 'EXCEPCION' : 'FIN', array_merge($contexto, $clase ? ['clase' => $clase] : [], [
            'duracion_ms' => (int) round((microtime(true) - $inicio) * 1000),
            'status' => $respuesta->getStatusCode(),
        ]));
        return $respuesta;
    }

    private function anotar($etapa, array $contexto)
    {
        try {
            Log::channel('diagnostico_conexion')->info($etapa, $contexto);
        } catch (\Throwable $ignorado) {
            // No reportar a otro canal ni cambiar la respuesta de la operación observada.
        }
    }
}
