<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\ActualizacionDelSistemaHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Los endpoints del aviso de actualización del sistema que usa el SPA de empresa (misión
 * aviso-actualizacion-sistema, 25/11/2026, contrato §4.3):
 *
 *   GET  api/actualizacion-del-sistema/estado     (con sesión) polling de 60 s; registra la presencia
 *   POST api/actualizacion-del-sistema/respuesta  (con sesión) `{respuesta: 'acepto'|'ahora_no'}`
 *   GET  api/actualizacion-del-sistema/publico    (sin sesión) lo mira la pantalla de login
 *
 * El controlador sólo traduce HTTP: toda la lógica (transiciones, presencia, respuesta) vive en
 * `ActualizacionDelSistemaHelper`. Un usuario con el sistema bloqueado nunca llega a `estado` ni a
 * `respuesta`: el middleware `BloqueoPorActualizacion` ya le contestó la marca 503.
 *
 * La validación de `respuesta` (422) la hace el helper a mano; no se usa `$request->validate()`.
 *
 * Nota de arquitectura: el repo no tiene una clase `__base_controller` (el archivo es una plantilla de
 * texto con `MODEL_NAME`), así que extiende `Controller` como el resto de los controladores sueltos.
 */
class ActualizacionDelSistemaController extends Controller
{
    /**
     * Estado visible para esta computadora (y registro de su presencia).
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function estado(Request $request): JsonResponse
    {
        return response()->json(ActualizacionDelSistemaHelper::estado_para_sesion($request, $request->user()), 200);
    }

    /**
     * La respuesta de esta computadora al pedido de OK.
     *
     * @param \Illuminate\Http\Request $request Cuerpo `respuesta`.
     * @return \Illuminate\Http\JsonResponse 200 con el mismo cuerpo que `estado`; 409 `sin_pedido`; 422.
     */
    public function respuesta(Request $request): JsonResponse
    {
        $resultado = ActualizacionDelSistemaHelper::responder($request, $request->user(), $request->input('respuesta'));

        return response()->json($resultado['body'], $resultado['status']);
    }

    /**
     * Estado para la pantalla de login, sin sesión: `libre`/`pidiendo_ok`/`rechazado` en 200, o la marca 503
     * si se está actualizando.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function publico(): JsonResponse
    {
        return ActualizacionDelSistemaHelper::respuesta_publica();
    }
}
