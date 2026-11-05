<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\PruebaHelper;
use App\Services\DemoChatProxy;
use Illuminate\Http\Request;

class DemoChatController extends Controller
{
    public function index(Request $request, DemoChatProxy $proxy)
    {
        return $this->en_demo($request) ? $proxy->solicitar('GET') : response(null, 204);
    }

    public function iniciar(Request $request, DemoChatProxy $proxy)
    {
        return $this->en_demo($request) ? $proxy->solicitar('POST', '/iniciar') : response(null, 204);
    }

    public function mensaje(Request $request, DemoChatProxy $proxy)
    {
        if (!$this->en_demo($request)) {
            return response(null, 204);
        }
        $datos = $request->validate([
            'contenido' => 'required|string|max:4000',
            'cliente_uuid' => 'required|uuid',
        ]);
        return $proxy->solicitar('POST', '/mensajes', $datos);
    }

    public function progreso(Request $request, DemoChatProxy $proxy)
    {
        if (!$this->en_demo($request)) {
            return response(null, 204);
        }
        $datos = $request->validate([
            'clave' => ['required', 'string', 'max:50', 'regex:/^(demo|recurso):[a-zA-Z0-9._-]+$/'],
            'segundos_vistos' => 'present|array|max:600',
            'segundos_vistos.*' => 'integer|min:0|max:7199',
            'duracion' => 'sometimes|numeric|min:1|max:7200',
            'terminado' => 'sometimes|boolean',
        ]);
        return $proxy->solicitar('POST', '/videos/progreso', $datos);
    }

    public function seleccionar(Request $request, DemoChatProxy $proxy)
    {
        if (!$this->en_demo($request)) {
            return response(null, 204);
        }
        // La selección identifica un video; la sesión y el canal del servidor deciden el lead.
        $datos = $request->validate([
            'clave' => ['required', 'string', 'max:50', 'regex:/^(demo|recurso):[a-zA-Z0-9._-]+$/'],
            'cliente_uuid' => 'required|uuid',
        ]);
        return $proxy->solicitar('POST', '/videos/seleccionar', $datos);
    }

    public function omitir(Request $request, DemoChatProxy $proxy)
    {
        if (!$this->en_demo($request)) {
            return response(null, 204);
        }
        $datos = $request->validate(['clave' => ['required', 'string', 'max:50', 'regex:/^(demo|recurso):[a-zA-Z0-9._-]+$/']]);
        return $proxy->solicitar('POST', '/videos/omitir', $datos);
    }

    /**
     * Solo una demo tiene este chat. Una PRUEBA GRATUITA entra por el mismo link mágico y lleva el mismo
     * marcador de sesión, pero su negocio no es un lead de demo: el admin le contestaba 401 y el proxy lo
     * convertía en 503 (4/11/2026). Responde 204 mudo, como fuera de una demo, sin hablarle al admin.
     */
    private function en_demo(Request $request)
    {
        if (PruebaHelper::puede_haber_prueba()) {
            return false;
        }

        return $request->hasSession() && !empty($request->session()->get('demo_ingreso_token_id'));
    }
}
