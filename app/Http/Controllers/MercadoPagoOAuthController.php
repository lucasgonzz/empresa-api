<?php

namespace App\Http\Controllers;

use App\Services\MercadoPago\MercadoPagoOAuthService;
use Illuminate\Http\Request;

/**
 * Endpoints del OAuth de Mercado Pago para que el comercio conecte/desconecte su propia cuenta
 * de cobros (grupo 170, prompt 598). La lógica de negocio (armar la URL, canjear tokens,
 * refrescar) vive en `App\Services\MercadoPago\MercadoPagoOAuthService`, siguiendo el mismo
 * criterio que ya usa el repo para el OAuth de Mercado Libre / Tienda Nube
 * (`App\Services\PlatformConnector\PlatformConnectorOAuthService`).
 */
class MercadoPagoOAuthController extends Controller
{
    /**
     * Devuelve al SPA la URL de autorización de Mercado Pago que el comercio autenticado debe
     * abrir para conectar su cuenta. No redirige desde el backend: el SPA es quien abre la
     * ventana de autorización.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function connect()
    {
        $service = new MercadoPagoOAuthService();

        try {
            $url = $service->build_authorization_url($this->userId());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['url' => $url], 200);
    }

    /**
     * Callback público (sin auth Sanctum: es un redirect del navegador del comercio) al que
     * Mercado Pago vuelve con `code` y `state`. El comercio se identifica a través del `state`
     * persistido en `connect`, no de una sesión autenticada.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\Response
     */
    public function callback(Request $request)
    {
        $service = new MercadoPagoOAuthService();

        return $service->handle_callback($request);
    }

    /**
     * Desconecta la cuenta de Mercado Pago del comercio autenticado: limpia sus tokens y apaga
     * el master switch (`mp_enabled`).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function disconnect()
    {
        $service = new MercadoPagoOAuthService();
        $configuration = $service->disconnect($this->userId());

        if (!$configuration) {
            return response()->json(['message' => 'No existe una configuración online para este comercio.'], 422);
        }

        return response()->json(['model' => $this->fullModel('OnlineConfiguration', $configuration->id)], 200);
    }
}
