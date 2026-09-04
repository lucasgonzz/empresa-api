<?php

namespace App\Http\Middleware;

use App\Models\PrintAgent;
use Closure;
use Illuminate\Http\Request;

/**
 * Autentica al agente de impresion por su token permanente.
 *
 * El agente no es una persona y no tiene sesion de Sanctum: es un programa corriendo en la PC de
 * una caja, que se identifica con un token que recibio al vincularse. Mismo espiritu que
 * AdminApiKey, con dos diferencias: el token es POR EQUIPO y no uno global, y se compara contra un
 * hash guardado en la base en vez de contra una constante de configuracion.
 *
 * Deja el equipo resuelto en el request como `print_agent` para que el controlador no vuelva a
 * buscarlo.
 */
class PrintAgentToken
{
    /**
     * @param Request $request Request HTTP entrante.
     * @param Closure $next Siguiente middleware / controlador.
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next)
    {
        $token = $request->header('X-Print-Agent-Token');

        if (empty($token)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        /*
         * La busqueda es por hash y no por el token en claro: la base nunca lo guarda, asi que
         * quien la lea no puede hacerse pasar por un equipo del comercio.
         */
        $print_agent = PrintAgent::where('token_hash', hash('sha256', $token))->first();

        if (is_null($print_agent)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $request->attributes->set('print_agent', $print_agent);

        return $next($request);
    }
}
