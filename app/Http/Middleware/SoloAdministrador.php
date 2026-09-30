<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Helpers\MostradorHelper;
use Closure;
use Illuminate\Http\Request;

/**
 * Deja pasar SOLO al dueño de la cuenta (o a un empleado con admin_access / acceso maestro) a las
 * rutas que ESCRIBEN la configuración general y la configuración online. Pedido de Lucas del
 * 30/9/2026, misión config-solo-administrador.
 *
 * Uso en rutas, SIEMPRE después de auth:sanctum (necesita la persona autenticada):
 *   Route::put('online-configuration/{id}', ...)->middleware('solo_administrador');
 *
 * 🔴 REUSA MostradorHelper::puede_ver() Y NO ESCRIBE OTRA REGLA, igual que SoloElDuenoIa: el menú
 * de la SPA ya decide "administrador" con dueño || admin_access, y dos reglas para la misma
 * pregunta se despegan la primera vez que una se corrige.
 *
 * ⚠️ Solo protege la ESCRITURA. `GET online-configuration` queda abierto: la SPA lo carga en el
 * arranque para todos los usuarios. Las preferencias por persona (modo oscuro, impresora,
 * chat IA, etc.) tampoco pasan por acá: las tiene que poder usar cualquier empleado.
 */
class SoloAdministrador
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure                  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if (!MostradorHelper::puede_ver()) {

            return response()->json([
                'message' => 'Solo el dueño o un administrador puede cambiar la configuración.',
            ], 403);
        }

        return $next($request);
    }
}
