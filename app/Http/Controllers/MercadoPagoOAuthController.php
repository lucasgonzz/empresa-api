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
     * Desconecta la cuenta de Mercado Pago del comercio autenticado: limpia los tokens de su
     * `platform_connector` y, si todavía le quedaban credenciales viejas en
     * `online_configuration`, también las limpia y apaga ahí el master switch (`mp_enabled`).
     *
     * Contrato conservado (mismo path, misma clave `model` con el `online_configuration`). Lo
     * único que cambió: antes, un comercio sin `online_configuration` recibía 422 y no se
     * desconectaba nada; ahora la desconexión se completa igual sobre el conector y se responde
     * 200 con `model: null`. El 422 anterior describía un fracaso que ya no ocurre.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function disconnect()
    {
        $service = new MercadoPagoOAuthService();
        $configuration = $service->disconnect($this->userId());

        return response()->json([
            'model' => $configuration ? $this->fullModel('OnlineConfiguration', $configuration->id) : null,
        ], 200);
    }
}
