<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Helpers\asistente_ia\IaAppHelper;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Las credenciales de máquina no son una segunda entrada a todo el ERP.
 * Sanctum autentica el token, pero no aplica sus habilidades automáticamente. Además prioriza
 * la cookie: por eso esta frontera mira el Bearer explícito aunque también haya sesión web.
 * No identifica scripts dentro de un navegador: con una cookie válida tienen los mismos
 * permisos que la interfaz; esta frontera delimita credenciales, no prueba presencia humana.
 */
class DelimitarAccesoPorToken
{
    public function handle(Request $request, Closure $next)
    {
        $bearer = $request->bearerToken();

        if (is_null($bearer) || trim((string) $bearer) === '') {
            return $next($request);
        }

        $ruta = $request->route();
        $con_sanctum = is_object($ruta) && in_array('auth:sanctum', $ruta->gatherMiddleware(), true);
        $token = PersonalAccessToken::findToken((string) $bearer);

        if (is_null($token)) {
            // Un webhook o admin-sync conserva su autenticación propia; no usa este token.
            return $con_sanctum ? $this->rechazo('token_no_valido', 401) : $next($request);
        }

        $habilidades = is_array($token->abilities) ? array_values($token->abilities) : [];

        if ($habilidades === ['mcp']) {
            $acciones = [
                'App\\Http\\Controllers\\McpController@post' => 'POST',
                'App\\Http\\Controllers\\McpController@get' => 'GET',
                'App\\Http\\Controllers\\McpController@delete' => 'DELETE',
            ];
            $metodo = $request->isMethod('HEAD') ? 'GET' : strtoupper($request->method());
            $accion = is_object($ruta) ? $ruta->getActionName() : '';

            // La ruta resuelta debe ser el transporte exacto, no su prefijo ni un alias nuevo.
            if (is_object($ruta) && $ruta->uri() === 'api/mcp'
                && isset($acciones[$accion]) && $acciones[$accion] === $metodo) {
                return $next($request);
            }

            return $this->rechazo('fuera_del_mcp');
        }

        if ($habilidades === [IaAppHelper::HABILIDAD]) {
            return IaAppHelper::ruta_permitida($request)
                ? $next($request) : $this->rechazo('fuera_de_la_app');
        }

        // No convertir un token con '*', habilidades mezcladas o futuras en acceso general.
        return $con_sanctum ? $this->rechazo('token_no_autorizado') : $next($request);
    }

    private function rechazo($codigo, $estado = 403)
    {
        return response()->json([
            'code' => $codigo,
            'message' => 'Esta clave no está autorizada para esta operación. Usá la interfaz del sistema o el MCP de ComercioCity.',
        ], $estado, [], JSON_UNESCAPED_UNICODE);
    }
}
