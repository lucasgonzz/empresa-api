<?php

namespace App\Http\Middleware;

use App\Http\Controllers\CommonLaravel\AuthController;
use App\Services\LocalActividad;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Le avisa a «El local» que una persona está usando el sistema (misión el-local-fase-2, 3/11/2026): como máximo un
 * evento `actividad` cada 5 minutos por usuario (LocalActividad::actividad_de).
 *
 * Corre en cada request del grupo `api`, de TODAS las instancias. Por eso:
 *
 *   - Todo pasa en `terminate()`, con la respuesta ya enviada: el usuario no espera nada de esto.
 *   - Mira `hasUser()` y no `user()`: `auth:sanctum` ya resolvió al usuario en las rutas con sesión, y en una ruta
 *     pública no se agrega ninguna consulta.
 *   - Nada de esto puede tirar una excepción.
 *
 * No cuentan como «el negocio está en el local»:
 *
 *   - 🔴 RUTAS_AUTOMATICAS. La SPA las llama sola, con la pestaña quieta. Sin esta lista, un comercio con tienda y una
 *     pestaña abierta figuraría activo toda la noche.
 *   - Un request rechazado (el candado de otro dispositivo, una sesión de demo revocada): nadie fue atendido. El
 *     rechazo de la demo vacía la sesión antes de llegar acá, así que la exención de más abajo ya no lo vería.
 *   - Un Bearer (la app ComercioCity IA, una clave MCP): no es una persona usando el sistema en un navegador.
 *   - Las sesiones eximidas del candado (login maestro, acceso de soporte, demo): es alguien mirando, no el comercio.
 *     La regla es la misma de AuthController::debe_omitir_candado_de_actividad(), y se le pregunta a él para no tener
 *     una segunda lista de claves de sesión.
 *
 * Lo que dispara un broadcast (un refresco en otra pestaña) cuenta igual: la API no lo puede distinguir de un clic.
 */
class AvisarActividadAlLocal
{
    /** GET que empresa-spa repite solo (`escuchar_orders_y_messages()` en mixins/start_methods.js). */
    const RUTAS_AUTOMATICAS = ['api/buyer', 'api/order/unconfirmed/models'];

    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }

    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed  $response
     * @return void
     */
    public function terminate($request, $response)
    {
        try {
            if (!LocalActividad::activa() || !$request->hasSession() || $request->bearerToken()) {
                return;
            }

            if ($response->getStatusCode() >= 400) {
                return;
            }

            $guard = Auth::guard('sanctum');

            if (!$guard->hasUser()) {
                return;
            }

            if ($request->isMethod('GET') && $request->is(self::RUTAS_AUTOMATICAS)) {
                return;
            }

            if ((new AuthController())->debe_omitir_candado_de_actividad()) {
                return;
            }

            LocalActividad::actividad_de($guard->user());
        } catch (\Throwable $e) {
            Log::warning('AvisarActividadAlLocal: no se pudo avisar la actividad.', ['message' => $e->getMessage()]);
        }
    }
}
