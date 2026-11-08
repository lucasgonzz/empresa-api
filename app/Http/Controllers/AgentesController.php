<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use App\Services\SupportAgentesSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentesController extends Controller
{
    public function canales(Request $request, SupportAgentesSync $sync): JsonResponse
    {
        $persona = SupportAgentesSync::persona_propia($request);
        $personal = $persona && !$persona->owner_id && AsistenteCanalHelper::tiene_extension($persona);
        $soporte = SupportAgentesSync::puede_usar_soporte($persona);
        $canales = ($personal || $soporte) ? $sync->canales($persona) : [];

        return response()->json([
            'asistente' => $personal ? ($canales['asistente'] ?? null) : null,
            'soporte' => $soporte ? ($canales['soporte'] ?? null) : null,
        ]);
    }
}
