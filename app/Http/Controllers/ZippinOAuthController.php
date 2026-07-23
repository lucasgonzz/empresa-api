<?php

namespace App\Http\Controllers;

use App\Services\Zippin\ZippinOAuthService;
use Illuminate\Http\Request;

/**
 * Endpoints del OAuth de Zippin para que el comercio conecte/desconecte su propia cuenta de
 * envíos (grupo 171, prompt 599). La lógica de negocio (armar la URL, canjear tokens, obtener el
 * account_id, refrescar) vive en `App\Services\Zippin\ZippinOAuthService`, siguiendo el mismo
 * criterio que `App\Http\Controllers\MercadoPagoOAuthController` (prompt 598).
 */
class ZippinOAuthController extends Controller
{
    /**
     * Devuelve al SPA la URL de autorización de Zippin que el comercio autenticado debe abrir
     * para conectar su cuenta. No redirige desde el backend: el SPA es quien abre la ventana de
     * autorización.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function connect()
    {
        $service = new ZippinOAuthService();

        try {
            $url = $service->build_authorization_url($this->userId());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['url' => $url], 200);
    }

    /**
     * Callback público (sin auth Sanctum: es un redirect del navegador del comercio) al que
     * Zippin vuelve con `code` y `state`. El comercio se identifica a través del `state`
     * persistido en `connect`, no de una sesión autenticada.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\Response
     */
    public function callback(Request $request)
    {
        $service = new ZippinOAuthService();

        return $service->handle_callback($request);
    }

    /**
     * Desconecta la cuenta de Zippin del comercio autenticado: limpia sus tokens y apaga el
     * master switch (`zippin_enabled`).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function disconnect()
    {
        $service = new ZippinOAuthService();
        $configuration = $service->disconnect($this->userId());

        if (!$configuration) {
            return response()->json(['message' => 'No existe una configuración online para este comercio.'], 422);
        }

        return response()->json(['model' => $this->fullModel('OnlineConfiguration', $configuration->id)], 200);
    }
}
