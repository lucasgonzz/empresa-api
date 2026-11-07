<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\PruebaHelper;
use App\Services\AsistenteUnificadoDePrueba;
use App\Services\DemoChatProxy;
use Illuminate\Http\Request;

class PruebaChatController extends Controller
{
    public function index(Request $request, DemoChatProxy $proxy)
    {
        return PruebaHelper::puede_usar_chat($request->user(), $request)
            ? $proxy->solicitar_prueba('GET') : response(null, 204);
    }

    public function iniciar(Request $request, DemoChatProxy $proxy)
    {
        return PruebaHelper::puede_usar_chat($request->user(), $request)
            ? $proxy->solicitar_prueba('POST', '/iniciar') : response(null, 204);
    }

    public function trasladar(Request $request, AsistenteUnificadoDePrueba $asistente)
    {
        $dueno = $request->user();
        if (!PruebaHelper::puede_usar_chat($dueno, $request) || !$asistente->tiene_acceso_personal($dueno)) {
            return response(null, 204);
        }

        return response()->json($asistente->trasladar($dueno));
    }

    public function mensaje(Request $request, DemoChatProxy $proxy)
    {
        if (!PruebaHelper::puede_usar_chat($request->user(), $request)) {
            return response(null, 204);
        }

        $datos = $request->validate([
            'contenido' => 'required|string|max:4000',
            'cliente_uuid' => 'required|uuid',
        ]);

        return $proxy->solicitar_prueba('POST', '/mensajes', $datos);
    }
}
