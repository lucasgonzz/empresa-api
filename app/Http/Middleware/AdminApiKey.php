<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminApiKey
{
    /**
     * Valida que el request venga del admin-api central.
     * Compara header X-Admin-Api-Key contra config('services.admin_api.api_key')
     * (variable ADMIN_API_INBOUND_KEY).
     *
     * Si se exige o no la clave lo decide `services.admin_api.require_api_key`
     * (variable ADMIN_SYNC_REQUIRE_API_KEY), que es TRI-ESTADO (misión cerrar-admin-sync, 10/10/2026):
     *
     *   - `true`  (definida en true)  => exige la clave.
     *   - `false` (definida en false) => NO exige: se apagó a propósito y se respeta tal cual.
     *   - `null`  (sin definir o vacía) => AUTOMÁTICO: exige la clave si esta instancia tiene
     *     ADMIN_API_INBOUND_KEY cargada, porque ahí hay con qué comparar y dejar la puerta abierta
     *     no tiene razón de ser. Un cliente con clave queda cerrado sin tocarle el .env.
     *   - `null` y la instancia SIN clave cargada => no exige (no hay con qué comparar: las demos y
     *     los entornos de test no tienen clave). Es el mismo comportamiento de antes.
     *
     * Cuando exige: 401 `{"error":"unauthorized"}` si falta el header, no coincide, o la instancia no
     * tiene clave cargada. Cada 401 deja un Log::warning (ruta, método, IP y si traía el header), nunca
     * el valor del header ni la clave: sirve para descubrir llamadores que no la mandan.
     *
     * Esta puerta no consulta la base ni la sesión: un request sin clave se corta antes de llegar al controlador.
     *
     * @param Request $request Request HTTP entrante.
     * @param Closure $next Siguiente middleware / controlador.
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next)
    {
        $expected = $this->clave_de_la_instancia();

        if (! $this->debe_exigir_la_clave($expected)) {
            return $next($request);
        }

        $received = $request->header('X-Admin-Api-Key');

        if ($expected === '' || empty($received) || !hash_equals($expected, (string) $received)) {
            $this->registrar_rechazo($request, ! empty($received));

            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $next($request);
    }

    /**
     * Decide si este request tiene que traer la clave, según el estado de `require_api_key`.
     *
     * @param mixed $clave_de_la_instancia Lo que haya en services.admin_api.api_key.
     * @return bool
     */
    protected function debe_exigir_la_clave($clave_de_la_instancia)
    {
        $flag = config('services.admin_api.require_api_key');

        if ($flag === null) {
            return $clave_de_la_instancia !== '';
        }

        return (bool) $flag;
    }

    /**
     * La clave de admin de ESTA instancia (`ADMIN_API_INBOUND_KEY`), normalizada para que "tiene clave cargada" y "con qué se compara"
     * sean la misma cosa:
     *   - sin espacios ni saltos de línea en los extremos (un `.env` guardado con CRLF no puede dejar la puerta cerrada para siempre);
     *   - si `env()` la convirtió en booleano (una clave que fuera literalmente `true` o `false`), se vuelve al texto, para que no
     *     quede "sin clave" y la instancia abierta por un detalle de cómo Laravel lee el `.env`.
     *
     * @return string '' si no hay clave cargada.
     */
    protected function clave_de_la_instancia()
    {
        $clave = config('services.admin_api.api_key');

        if (is_bool($clave)) {
            return $clave ? 'true' : 'false';
        }

        return is_scalar($clave) ? trim((string) $clave) : '';
    }

    /**
     * Deja constancia de un 401, sin el valor del header ni la clave.
     *
     * @param Request $request     Request rechazado.
     * @param bool    $con_header  Si traía algún X-Admin-Api-Key (sea cual sea su valor).
     * @return void
     */
    protected function registrar_rechazo(Request $request, $con_header)
    {
        $ruta = $request->route();

        Log::warning('admin-sync: pedido rechazado por falta de clave de admin (401)', [
            'ruta'       => $ruta ? $ruta->uri() : $request->path(),
            'metodo'     => $request->method(),
            'ip'         => $request->ip(),
            'con_header' => (bool) $con_header,
        ]);
    }
}
