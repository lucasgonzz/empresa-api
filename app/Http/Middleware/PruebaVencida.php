<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Helpers\PruebaHelper;
use App\Http\Controllers\Helpers\PruebaHitosHelper;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Corta la prueba gratuita vencida (misión prueba-experiencia-empresa, 3/11/2026).
 *
 * Va en el grupo `api` del Kernel, después de que Sanctum estableció la sesión. En cada request con sesión de
 * una instancia de prueba mira `users.expired_at` del dueño (una query por request, memoizada) y, si ya pasó,
 * contesta 403 `{error: 'prueba_vencida', message, whatsapp_soporte}`: el SPA muestra «Tu prueba terminó» con
 * el botón de WhatsApp. No cierra la sesión: si el admin extiende los días (C3), el negocio recarga y sigue.
 *
 * Corte temprano propio, igual que DemoSessionVigente: en los ~40 clientes reales `es_instancia_de_prueba()`
 * da false sin tocar nada. Las rutas sin sesión (admin-sync, demo-setup, ingreso por token) pasan: el admin
 * tiene que poder mover el vencimiento de una prueba vencida.
 *
 * El login (grupo `web`) no pasa por acá: `AuthController::login()` hace su propio chequeo y contesta el
 * mismo 403, para que el negocio vea la pantalla y no «credenciales incorrectas».
 */
class PruebaVencida
{
    /**
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if (!PruebaHelper::puede_haber_prueba()) {
            return $next($request);
        }

        /*
         * El canal del admin nunca se corta: por ahi llegan `prueba/vencimiento` (mover o sacar el
         * vencimiento de una prueba vencida) y el resto del admin-sync. En produccion no traen sesion,
         * pero una sesion abierta en el mismo navegador (soporte, la suite) no tiene por que cerrarles
         * la puerta.
         */
        if ($request->is('api/admin-sync/*')) {
            return $next($request);
        }

        $user = $this->usuario_de_la_sesion();

        if (is_null($user)) {
            return $next($request);
        }

        $owner_id = PruebaHelper::owner_id_de($user);

        if (!PruebaHelper::vencida($owner_id)) {
            return $next($request);
        }

        $vence_at = PruebaHelper::vence_at($owner_id);

        PruebaHitosHelper::emitir('prueba.vencida', [
            'vence_at' => is_null($vence_at) ? null : PruebaHelper::iso($vence_at),
        ], $owner_id);

        return PruebaHelper::respuesta_vencida($owner_id);
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
