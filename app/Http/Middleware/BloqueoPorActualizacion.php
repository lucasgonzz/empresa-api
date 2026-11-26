<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Helpers\ActualizacionDelSistemaHelper;
use App\Http\Controllers\Helpers\MostradorHelper;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Corta el uso del sistema mientras se actualiza (misión aviso-actualizacion-sistema, 25/11/2026).
 *
 * Va al FINAL del grupo `api` del Kernel, después de `PruebaVencida` y con el mismo molde: necesita la sesión que
 * Sanctum ya estableció. En cada request con usuario mira si el estado de su dueño es `actualizando` (con
 * `vence_at` en el futuro); si lo es, cierra la sesión del lado servidor (`logout` + `invalidate`, que también
 * rota el `remember_token`) y contesta la marca 503 `sistema_actualizando`. La request siguiente ya es 401.
 *
 * No toca:
 *  - la sesión maestra (el humo del pipeline prueba la versión nueva con ella),
 *  - la sesión de soporte (token firmado del admin),
 *  - las rutas `api/admin-sync/*` (el admin tiene que poder liberar el estado),
 *  - las requests sin usuario (no hay a quién cerrarle la sesión; el login se corta en `AuthController`).
 *
 * El costo en el camino caliente es una lectura cacheada 10 segundos por dueño (ver
 * `ActualizacionDelSistemaHelper::esta_bloqueado()`); sin tabla o con la base caída no bloquea nada.
 */
class BloqueoPorActualizacion
{
    /**
     * @param \Illuminate\Http\Request $request
     * @param \Closure                 $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // El canal del admin nunca se corta, ni siquiera con una sesión abierta en el mismo navegador.
        if ($request->is('api/admin-sync/*')) {
            return $next($request);
        }

        $user = $this->usuario_de_la_sesion();

        if (is_null($user)) {
            return $next($request);
        }

        // El acceso maestro y el de soporte entran a mirar aunque el sistema esté bloqueado.
        if (MostradorHelper::es_sesion_maestra($request)
            || ($request->hasSession() && $request->session()->has('acceso_soporte_token_id'))) {
            return $next($request);
        }

        if (!ActualizacionDelSistemaHelper::esta_bloqueado(ActualizacionDelSistemaHelper::owner_id_de($user))) {
            return $next($request);
        }

        // Sesión cerrada del lado servidor: el SPA recibe la marca ahora y un 401 en la próxima request.
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
        }

        // Los guards de Sanctum/web guardan al usuario en memoria: se sueltan para que nada más de este proceso
        // (un worker de larga vida, o el siguiente request de una suite) siga viendo la sesión ya cerrada.
        Auth::forgetGuards();

        return ActualizacionDelSistemaHelper::respuesta_marca();
    }

    /**
     * El usuario de la sesión, resuelto por el guard de Sanctum (cookie o Bearer), o null.
     *
     * @return \App\Models\User|null
     */
    private function usuario_de_la_sesion()
    {
        try {
            return Auth::guard('sanctum')->user();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
