<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Helpers\UserHelper;
use Closure;
use Illuminate\Http\Request;

/**
 * Middleware que verifica que el usuario autenticado tenga habilitada una
 * extensión de empresa identificada por su slug.
 *
 * Uso en rutas:
 *   ->middleware('check_extencion_empresa:ai_excel_import')
 *
 * El slug se pasa como parámetro del middleware y se compara contra la
 * colección de extenciones del usuario propietario (owner).
 */
class CheckExtencionEmpresa
{
    /**
     * Maneja la solicitud entrante.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure                  $next
     * @param  string                    $slug  Slug de la extensión requerida (ej: "ai_excel_import")
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string $slug)
    {
        /*
         * Obtenemos el usuario propietario (owner) usando UserHelper, que ya
         * sabe resolver el owner_id cuando el auth_user es un empleado.
         */
        $user = UserHelper::user();

        if (is_null($user)) {
            return response()->json([
                'message' => 'No autenticado',
            ], 401);
        }

        /*
         * Verificamos si el usuario tiene la extensión activa usando el mismo
         * método que ya usa UserHelper::hasExtencion() en el resto del sistema.
         */
        $tiene_extencion = collect($user->extencions)->contains('slug', $slug);

        if (!$tiene_extencion) {
            return response()->json([
                'message' => 'No tenés acceso a esta funcionalidad. Extensión requerida: ' . $slug,
            ], 403);
        }

        return $next($request);
    }
}
