<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\PruebaHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Services\WhatsappBotAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function simular_agente(Request $request, WhatsappBotAiService $agente): JsonResponse
    {
        $dueno = $request->user();
        if (!empty($dueno->owner_id)) {
            return response()->json(['message' => 'Solo el dueño puede probar el agente.'], 403);
        }

        $owner_id = (int) $dueno->id;
        if (!PruebaHelper::en_periodo_vigente($owner_id)) {
            return response()->json(['message' => 'La simulación está disponible durante la prueba gratuita.'], 403);
        }

        $datos = $request->validate([
            'pregunta' => 'required|string|max:500',
        ], [
            'pregunta.required' => 'Escribí una pregunta para el agente.',
            'pregunta.string'   => 'La pregunta tiene que ser un texto.',
            'pregunta.max'      => 'La pregunta puede tener hasta 500 caracteres.',
        ]);

        $error_catalogo = PruebaHelper::error_catalogo_para_probar_agente($owner_id);
        if ($error_catalogo !== null) {
            return response()->json($error_catalogo, 422);
        }

        $resultado = $agente->simular_respuesta($owner_id, trim($datos['pregunta']));
        if ($resultado['body'] !== '') {
            return response()->json(['respuesta' => $resultado['body']]);
        }

        if ($resultado['motivo'] === 'solo_foto') {
            return response()->json([
                'message' => 'El agente sugeriría una foto. Esta simulación muestra respuestas de texto. Probá con otra pregunta.',
            ], 422);
        }

        return response()->json([
            'message' => 'El agente no pudo responder en este momento. Probá de nuevo en unos minutos.',
        ], 503);
    }
}
