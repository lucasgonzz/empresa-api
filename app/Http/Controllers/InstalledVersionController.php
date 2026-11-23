<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\InstalledVersionHelper;

/**
 * Consulta de la versión instalada y su historial (el menú del SPA). Sin lógica: delega en el helper.
 */
class InstalledVersionController extends Controller
{
    /**
     * Devuelve la versión actual y el historial del comercio del usuario logueado.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        return response()->json(InstalledVersionHelper::summary($this->userId()), 200);
    }
}
