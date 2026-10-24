<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\AgentesIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La puerta con la que el admin lee y prende/apaga la extensión «Agentes de IA» (`agentes_ia`) de este cliente
 * — misión agentes-ia-extension-unica (22/10/2026).
 *
 *   GET  api/admin-sync/agentes-ia                 → {ok: true, activa: bool}
 *   PUT  api/admin-sync/agentes-ia  {activa: bool} → {ok: true, activa: bool}   (idempotente)
 *
 * 🔴 La ruta va con `admin.api.key:siempre` (mismo molde que `extensiones/acceso`): exige `X-Admin-Api-Key`
 * aunque la instancia no tenga clave cargada, porque el PUT ESCRIBE un permiso del comercio. Sin clave → 401
 * (lo contesta el middleware, antes de llegar acá). Acá no se vuelve a validar nada.
 *
 * El dueño sale de `AsistenteCanalHelper::dueno()` (`config('app.USER_ID')`): sin dueño resoluble → 409, que
 * el admin mapea a `sin_conexion` con un mensaje que pide cargar USER_ID. Toda la lógica de prender/apagar
 * vive en `AgentesIaHelper`.
 *
 * Los gates devuelven siempre 403 y nunca 404 (el admin interpreta un 404 como «versión vieja»): un cliente
 * que todavía no actualizó no tiene esta ruta y responde 404/405 por sí solo, y eso es lo que el admin espera.
 */
class AgentesIaController extends Controller
{
    /**
     * GET api/admin-sync/agentes-ia — si el comercio de esta instancia tiene la extensión.
     *
     * @return JsonResponse 200 `{ok, activa}` · 409 sin dueño resoluble
     */
    public function show(): JsonResponse
    {
        $dueno = AsistenteCanalHelper::dueno();

        if (is_null($dueno)) {

            return $this->sin_dueno();
        }

        return response()->json(['ok' => true, 'activa' => AgentesIaHelper::tiene($dueno)], 200);
    }

    /**
     * PUT api/admin-sync/agentes-ia — prende o apaga la extensión del dueño. Idempotente: pedir el estado en
     * el que ya está no cambia nada. Solo toca `agentes_ia`; las demás extensiones del dueño quedan igual.
     *
     * @param  Request  $request  Body `{activa: bool}` (obligatorio).
     * @return JsonResponse 200 `{ok, activa}` · 409 sin dueño resoluble · 422 body mal formado
     */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'activa' => 'required|boolean',
        ]);

        $dueno = AsistenteCanalHelper::dueno();

        if (is_null($dueno)) {

            return $this->sin_dueno();
        }

        /* `boolean` de Laravel acepta true/false/1/0/"1"/"0": filter_var lo lleva a bool de verdad. */
        $quiere_activa = filter_var($request->input('activa'), FILTER_VALIDATE_BOOLEAN);

        $activa = AgentesIaHelper::fijar($dueno, $quiere_activa);

        return response()->json(['ok' => true, 'activa' => (bool) $activa], 200);
    }

    /**
     * La respuesta cuando no se puede decidir de qué comercio se habla.
     *
     * @return JsonResponse
     */
    protected function sin_dueno(): JsonResponse
    {
        return response()->json([
            'message' => 'No se pudo resolver el dueño de esta instancia. '
                       . 'Si la base la comparten varios comercios, falta USER_ID en el .env de este frente.',
        ], 409);
    }
}
