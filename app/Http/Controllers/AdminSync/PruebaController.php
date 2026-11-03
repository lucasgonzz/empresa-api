<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\PruebaHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * La prueba gratuita vista desde el admin (C3, misión prueba-experiencia-empresa, 3/11/2026).
 *
 * - `POST admin-sync/prueba/vencimiento` `{vence_at: ISO|null}` → 200 `{vence_at: ISO|null}`. Fija
 *   `users.expired_at` del dueño; `null` saca el vencimiento (contrató). 422 si `vence_at` no es una fecha.
 * - `GET admin-sync/prueba/estado` → 200 `{vence_at, vencida, semilla_presente, hitos: {tipo: ISO|null},
 *   activa}`.
 *
 * Las dos con `X-Admin-Api-Key` (`admin.api.key`). Un empresa viejo contesta 404 y el admin lo tolera.
 * 409 `dueno_no_resoluble` si la instancia no tiene dueño.
 */
class PruebaController extends Controller
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function vencimiento(Request $request): JsonResponse
    {
        $validador = Validator::make($request->all(), [
            'vence_at' => 'nullable|date',
        ]);

        if ($validador->fails()) {
            return response()->json([
                'message' => $validador->errors()->first(),
                'errors'  => $validador->errors(),
            ], 422);
        }

        $dueno = PruebaHelper::dueno();

        if (is_null($dueno)) {
            return response()->json(['code' => 'dueno_no_resoluble', 'message' => 'La instancia no tiene dueño.'], 409);
        }

        $vence_at = PruebaHelper::parsear_fecha($request->input('vence_at'));

        PruebaHelper::fijar_vencimiento($dueno->id, $vence_at);

        $guardado = PruebaHelper::vence_at($dueno->id);

        return response()->json([
            'vence_at' => is_null($guardado) ? null : PruebaHelper::iso($guardado),
        ], 200);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function estado(): JsonResponse
    {
        $dueno = PruebaHelper::dueno();

        if (is_null($dueno)) {
            return response()->json(['code' => 'dueno_no_resoluble', 'message' => 'La instancia no tiene dueño.'], 409);
        }

        return response()->json(PruebaHelper::estado_para_admin($dueno->id), 200);
    }
}
