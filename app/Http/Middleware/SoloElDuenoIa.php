<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Helpers\MostradorHelper;
use Closure;
use Illuminate\Http\Request;

/**
 * Deja pasar al dueño y a los administradores del negocio en su propia sesión.
 * Las sesiones de ComercioCity quedan afuera del asistente personal.
 *
 * Uso en rutas, SIEMPRE después de auth:sanctum (necesita la persona autenticada):
 *   ->middleware(['auth:sanctum', 'check_extencion_empresa:agentes_ia', 'solo_el_dueno_ia'])
 *
 * 🔴 POR QUÉ REUSA MostradorHelper::puede_ver() Y NO ESCRIBE OTRA REGLA. El mostrador del módulo
 * IA ya resolvía exactamente esta pregunta, y por el mismo motivo: sus informes traen cobranzas,
 * deudas y compras. El chat es la otra cara del mismo módulo y puede contestar todo eso. Dos
 * reglas separadas para la misma pregunta se despegan sola la primera vez que una se corrige.
 *
 * ⚠️ Esto NO reemplaza la tenencia doble del controlador (auth_user_id + user_id) ni a
 * PermisosIaHelper en el camino de confirmación de las tarjetas: un empleado ya no llega hasta
 * acá, pero la defensa en profundidad se queda para el día que el gate se afloje.
 */
class SoloElDuenoIa
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure                  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string $modo = 'privado')
    {
        // El historial conserva su respuesta de recurso inexistente para accesos delegados.
        if ($modo === 'privado' && MostradorHelper::sesion_de_comerciocity($request)) {
            return response()->json(['message' => 'El asistente personal es privado.'], 403);
        }

        if (!MostradorHelper::puede_ver()) {
            return response()->json([
                'message' => 'Solo el dueño puede usar el asistente de IA.',
            ], 403);
        }

        return $next($request);
    }
}
