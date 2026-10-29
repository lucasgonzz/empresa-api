<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Helpers\asistente_ia\IaAppHelper;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Un token de la app «ComercioCity IA» solo abre las rutas de la app (misión app-ia-y-memoria,
 * 29/10/2026, §4 E1.1 del plan).
 *
 * Va en el grupo `api` (Kernel), así que corre en TODAS las rutas del API, antes del `auth:sanctum`
 * de cada una. Por eso no pregunta por $request->user() (todavía no hay nadie autenticado): lee el
 * Bearer, busca el token y mira sus habilidades.
 *
 * Qué hace, exactamente:
 *   - Sin Bearer (la cookie del sistema, los admin-sync, las rutas públicas) → pasa, sin consultar
 *     nada. Es el 99,9 % del tráfico y no cambia en nada.
 *   - Con un Bearer que no existe o que NO es de la app (la clave `mcp`, cualquier otro token) → pasa:
 *     cada ruta decide con su propio middleware, como hasta hoy.
 *   - Con un token de la app (habilidad `ia-app` y sin `*`) → pasa solo si la ruta está en
 *     IaAppHelper::RUTAS_DE_LA_APP; si no, 403 `fuera_de_la_app`.
 *
 * 🔴 POR QUÉ HACE FALTA. Sanctum no mira las habilidades del token salvo que la ruta lo pida: un
 * token `ia-app` filtrado abriría todo el API como si fuera el dueño (artículos, ventas, borrados).
 * Con esto, lo peor que puede hacer es lo que hace la app: chatear con su agente.
 */
class SoloRutasDeLaApp
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $bearer = $request->bearerToken();

        if (is_null($bearer) || trim((string) $bearer) === '') {

            return $next($request);
        }

        $token = PersonalAccessToken::findToken((string) $bearer);

        if (!IaAppHelper::es_token_de_la_app($token)) {

            return $next($request);
        }

        if (IaAppHelper::ruta_permitida($request)) {

            return $next($request);
        }

        return response()->json([
            'code'    => 'fuera_de_la_app',
            'message' => 'Esta clave es de la app ComercioCity IA y no sirve para esta parte del sistema.',
        ], 403, [], JSON_UNESCAPED_UNICODE);
    }
}
