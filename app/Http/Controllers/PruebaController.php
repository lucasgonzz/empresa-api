<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\PruebaHelper;
use App\Http\Controllers\Helpers\UserHelper;
use Illuminate\Http\JsonResponse;

/**
 * La prueba gratuita vista desde el SPA (misión prueba-experiencia-empresa, 3/11/2026).
 *
 * - `GET prueba/estado` → `{prueba: {...}|null}`, lo mismo que viaja en `user.prueba` al iniciar sesión. El
 *   Listado lo vuelve a pedir después de una importación, para esconder el botón cuando ya no hay semilla.
 * - `POST prueba/modal-visto` → marca que el dueño vio el modal de primer ingreso (una sola vez por dueño).
 *
 * Fuera del modo prueba contestan `{prueba: null}` y no escriben nada: este SPA se despliega en los ~40
 * clientes reales.
 */
class PruebaController extends Controller
{
    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function estado(): JsonResponse
    {
        return response()->json([
            'prueba' => PruebaHelper::resumen_para_spa(UserHelper::userId(true)),
        ], 200);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function modal_visto(): JsonResponse
    {
        $owner_id = UserHelper::userId(true);

        if (PruebaHelper::activa($owner_id)) {
            PruebaHelper::marcar_modal_visto($owner_id);
        }

        return response()->json([
            'prueba' => PruebaHelper::resumen_para_spa($owner_id),
        ], 200);
    }
}
