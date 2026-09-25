<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * 🔴 PARA `api/*` NO HAY REDIRECCIÓN: se devuelve null y Laravel contesta 401. Este proyecto no
     * tiene ruta `login` (el login es de la SPA), así que `route('login')` no redirigía a ningún
     * lado: tiraba `Route [login] not defined` y el request moría en 500. Medido en demo3 el
     * 24/9/2026 con el `<img>` de una foto del asistente (`api/ai-mensajes/{id}/imagen/{orden}`):
     * un `<img>` no manda `Accept: application/json`, `expectsJson()` da false y se caía acá. Un 401
     * es lo que corresponde para un request no autenticado a la API, lo pida quien lo pida; el
     * arreglo de fondo de la foto (que la sesión se levante) está en la SPA.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    protected function redirectTo($request)
    {
        if ($request->is('api/*')) {
            return null;
        }

        if (! $request->expectsJson()) {
            return route('login');
        }
    }
}
