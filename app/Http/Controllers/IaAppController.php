<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\asistente_ia\IaAppHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Las rutas propias de la app «ComercioCity IA» (misión app-ia-y-memoria, 29/10/2026, contrato C5).
 *
 * Van en el mismo gate que el chat del sistema (auth:sanctum + extensión agentes_ia + solo el dueño):
 * la app es ese mismo chat visto desde otro lado. Entran con el token `ia-app` que emitió
 * admin-sync/ia-app/token, y SoloRutasDeLaApp no les deja usar nada que no sea de la app.
 *
 * 🔴 La app nunca llama a `GET api/user`: ese endpoint corre el candado de sesión única. Todo lo que
 * necesita saber de quién es y de qué negocio está en `ia-app/yo`.
 */
class IaAppController extends Controller
{
    /**
     * GET api/ia-app/yo → quién es, de qué negocio, qué sabe hacer este sistema y en qué quedó su
     * cuestionario de inicio.
     *
     * De paso deja armada la memoria del negocio si todavía no existe (es idempotente y barato): la
     * app la va a leer apenas abra la configuración.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function yo(): JsonResponse
    {
        $persona = UserHelper::user(false);
        $dueno   = UserHelper::user(true);

        if (is_null($persona) || is_null($dueno)) {

            return response()->json([
                'code'    => 'sin_persona',
                'message' => 'No se pudo resolver la persona o el dueño de la cuenta.',
            ], 409, [], JSON_UNESCAPED_UNICODE);
        }

        IaAppHelper::asegurar_la_memoria($dueno);

        return response()->json([
            'usuario'      => IaAppHelper::usuario($persona),
            'negocio'      => IaAppHelper::negocio($dueno),
            'capacidades'  => IaAppHelper::capacidades(),
            'cuestionario' => IaAppHelper::cuestionario($dueno),
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * DELETE api/ia-app/token → revoca el token con el que entró la app (cerrar sesión). 204.
     *
     * Solo borra un token de la app: si el pedido vino con la cookie del sistema o con la clave MCP,
     * no hay nada de la app que revocar y contesta 204 igual (cerrar sesión dos veces no es un error).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function revocar_token(Request $request)
    {
        $token = IaAppHelper::token_de_la_app($request);

        if (!is_null($token)) {
            $token->delete();
        }

        return response()->noContent();
    }
}
