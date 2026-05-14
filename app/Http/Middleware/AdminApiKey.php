<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminApiKey
{
    /**
     * Valida que el request venga del admin-api central.
     * Compara header X-Admin-Api-Key contra config('services.admin_api.api_key').
     *
     * Si services.admin_api.require_api_key es false, la validación se omite (integración sin clave;
     * volver a true cuando se reactive la autenticación por API key).
     *
     * @param Request $request Request HTTP entrante.
     * @param Closure $next Siguiente middleware / controlador.
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next)
    {
        /**
         * Si está desactivada la exigencia de clave, no se valida el header (comportamiento temporal).
         */
        if (! config('services.admin_api.require_api_key', false)) {
            return $next($request);
        }

        $received = $request->header('X-Admin-Api-Key');
        $expected = config('services.admin_api.api_key');

        if (empty($expected) || empty($received) || !hash_equals((string) $expected, (string) $received)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
