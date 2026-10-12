<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\PdfLinkHelper;

/**
 * El token del link de un PDF para la SPA (misión pdf-de-venta-publico, 10/10/2026).
 *
 * `GET api/pdf-link/{tipo}/{id}` → `{token}`. Lo pide el botón de WhatsApp de la SPA
 * (`WhatsappBtn.vue`, venta y presupuesto) para sumarle `?t=` al link que manda: el cliente final que
 * lo abre no tiene sesión, y desde esta misión las rutas de PDF ya no son públicas.
 *
 * Va dentro del grupo `auth:sanctum` de `routes/api.php`: hace falta sesión para pedir un token, y
 * el recurso tiene que ser del dueño de esa sesión.
 */
class PdfLinkController extends Controller
{
    /**
     * Devuelve el token del link del recurso (el vigente, o uno nuevo).
     *
     * 404 en los tres casos que no tienen token: un tipo que no admite token (solo se comparten
     * fuera del sistema los de PdfLinkHelper::TIPOS_CON_TOKEN), un recurso que no existe y un recurso
     * de OTRO comercio. Los tres con la misma respuesta, igual que las descargas: un 403 le
     * confirmaría a quien enumera ids que el recurso existe.
     *
     * @param  string  $tipo  Uno de PdfLinkHelper::TIPOS_CON_TOKEN.
     * @param  string  $id    Id del recurso.
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($tipo, $id)
    {
        if (!PdfLinkHelper::admite_token($tipo)) {
            return $this->no_encontrado();
        }

        // El dueño de la sesión, sin el fallback a USER_ID de userId(): sin sesión no hay token.
        $sesion = $this->user();

        if (is_null($sesion)) {
            return $this->no_encontrado();
        }

        $duenios = PdfLinkHelper::duenios_del_recurso($tipo, $id);

        if (is_null($duenios) || $duenios !== [(int) $sesion->id]) {
            return $this->no_encontrado();
        }

        $token = PdfLinkHelper::token_para($tipo, (int) $id, (int) $sesion->id);

        return response()->json(['token' => $token], 200);
    }

    /**
     * La respuesta de "no hay token para eso", única para todos los motivos.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function no_encontrado()
    {
        return response()->json(['message' => 'No encontrado.'], 404);
    }
}
