<?php

namespace App\Http\Controllers;

use App\Services\MercadoLibre\MercadoLibreConexionService;
use App\Services\MercadoLibre\MercadoLibreNoConfiguradoException;
use Illuminate\Http\Request;

/**
 * Conexión de la cuenta de Mercado Libre del comercio y estado de la cuenta (misión
 * mercado-libre-integracion, 27/10/2026). La lógica vive en `MercadoLibreConexionService`; el
 * callback del OAuth sigue en `routes/web.php` → `PlatformConnectorOAuthService`.
 */
class MercadoLibreConexionController extends Controller
{
    /**
     * URL de autorización que la SPA abre con `window.open(url)`.
     *
     * Le pasa al servicio el host de esta API (`$request->getHost()`), que viaja en el `state`
     * para que la URL central del admin sepa a qué backend reenviar el callback.
     *
     * Respuesta: `{url}`; sin credenciales de la app, 422 `{message}`.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function connect(Request $request)
    {
        $service = new MercadoLibreConexionService();

        try {
            $url = $service->url_de_autorizacion($this->userId(), $request->getHost());
        } catch (MercadoLibreNoConfiguradoException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['url' => $url], 200);
    }

    /**
     * Desconecta la cuenta de Mercado Libre del comercio. Respuesta: `{ok: true}`.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function disconnect()
    {
        $service = new MercadoLibreConexionService();
        $service->desconectar($this->userId());

        return response()->json(['ok' => true], 200);
    }

    /**
     * Estado de la cuenta para la solapa "Cuenta": conexión, reputación, si puede publicar, modelo
     * de la cuenta y la cola de sincronización. Sin conexión responde 200 con `conectado=false`.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function cuenta()
    {
        $service = new MercadoLibreConexionService();

        return response()->json(['cuenta' => $service->cuenta($this->userId())], 200);
    }
}
