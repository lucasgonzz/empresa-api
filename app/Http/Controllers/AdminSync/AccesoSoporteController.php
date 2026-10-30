<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\AccesoSoporteHelper;
use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La puerta con la que el admin pide un acceso de soporte de un solo uso a ESTE cliente — misión
 * acceso-soporte-firmado (30/10/2026), contrato C1.
 *
 *   POST api/admin-sync/acceso-soporte   body {solicitante?: string≤60, motivo?: string≤120}
 *     200 {ok: true, token, vence_en_segundos, usuario_id}
 *     401 {error: unauthorized}                                   (lo contesta el middleware)
 *     403 {code: acceso_soporte_desactivado, message}
 *     409 {code: dueno_no_resoluble, message}
 *
 * 🔴 LA FIRMA ES LA CLAVE COMPARTIDA. La ruta va con `admin.api.key:siempre`: exige `X-Admin-Api-Key` aunque
 * la instancia no tenga `ADMIN_API_INBOUND_KEY` cargada. Con el `admin.api.key` a secas, «no hay clave
 * cargada» significa «no hay con qué comparar, pasá» (es lo que pasa en las demos), y esta ruta ABRE LA
 * PUERTA DE UN COMERCIO: una instancia sin clave la dejaría abierta para cualquiera. Sin clave → 401, lo
 * contesta el middleware antes de llegar acá, y acá no se vuelve a validar nada.
 *
 * El dueño sale de `AsistenteCanalHelper::dueno()` (`config('app.USER_ID')` o el único dueño de la base):
 * si no se puede decidir sin ambigüedad → 409 `dueno_no_resoluble`, NUNCA «el primero». «El primero» es el
 * defecto del login maestro en las bases que comparten varios comercios, y acá equivocarse es meter al
 * soporte en el negocio de otro.
 *
 * Toda la lógica (generar, hashear, guardar, sanear) vive en `AccesoSoporteHelper`: el controlador orquesta.
 * El token en claro viaja SOLO en esta respuesta: no se loguea, no se guarda.
 *
 * Los gates devuelven 403/409 con `code` y nunca 404: el admin interpreta un 404/405 SIN `code` como «este
 * cliente tiene una versión vieja y no tiene la ruta», y no se puede confundir con un rechazo de acá.
 */
class AccesoSoporteController extends Controller
{
    /**
     * Emite un token de acceso de soporte para el dueño de esta instancia.
     *
     * @param  Request  $request  Body opcional `{solicitante, motivo}`; se sanean en el helper.
     * @return JsonResponse 200 con el token en claro · 403 desactivado · 409 sin dueño resoluble
     */
    public function store(Request $request): JsonResponse
    {
        if (!AccesoSoporteHelper::habilitado()) {

            return response()->json([
                'code'    => 'acceso_soporte_desactivado',
                'message' => 'El acceso de soporte está desactivado en este cliente (ACCESO_SOPORTE_HABILITADO).',
            ], 403);
        }

        $dueno = AsistenteCanalHelper::dueno();

        if (is_null($dueno)) {

            return response()->json([
                'code'    => 'dueno_no_resoluble',
                'message' => 'No se pudo resolver el dueño de esta instancia. '
                           . 'Si la base la comparten varios comercios, falta USER_ID en el .env de este frente.',
            ], 409);
        }

        /** El token en claro. Existe solo en esta variable y en el cuerpo de la respuesta de abajo. */
        $token = AccesoSoporteHelper::emitir(
            $dueno,
            $request->input('solicitante'),
            $request->input('motivo'),
            $request->ip()
        );

        // `no-store`: la respuesta lleva un secreto (aunque de 2 minutos); ningún proxy ni caché del
        // navegador tiene por qué guardarla.
        return response()->json([
            'ok'                => true,
            'token'             => $token,
            'vence_en_segundos' => AccesoSoporteHelper::ttl_segundos(),
            'usuario_id'        => $dueno->id,
        ], 200)->header('Cache-Control', 'no-store');
    }
}
