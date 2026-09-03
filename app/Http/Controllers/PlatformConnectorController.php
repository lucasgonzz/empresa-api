<?php

namespace App\Http\Controllers;

use App\Models\Platform;
use App\Models\PlatformConnector;
use Illuminate\Http\Request;

/**
 * CRUD de conectores de plataforma (OAuth por usuario sobre una `Platform`).
 *
 * Notas:
 * - Filtra por `user_id` del tenant autenticado.
 * - Las claves de app viven en `platforms`; acá solo se elige `platform_id`.
 */
class PlatformConnectorController extends Controller
{
    /**
     * Lista conectores del usuario ordenados por fecha descendente.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $models = PlatformConnector::where('user_id', $this->userId())
            ->orderBy('created_at', 'DESC')
            ->withAll()
            ->get();

        return response()->json(['models' => $models], 200);
    }

    /**
     * Crea un conector en estado `sin_conectar`.
     *
     * @param Request $request Payload del cliente (`platform_id`).
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // El filtro no es solo cosmético del listado: si no se valida acá, un POST directo con el
        // `platform_id` de Mercado Pago crea igual un conector que nunca va a poder conectarse
        // (`auth_url` vacío). Mercado Pago se conecta por `integraciones/mercadopago/connect`.
        $platform = Platform::query()->findOrFail((int) $request->platform_id);

        if (!in_array($platform->slug, Platform::SLUGS_CONECTABLES_POR_ABM, true)) {
            return response()->json([
                'message' => 'La plataforma "'.$platform->name.'" no se conecta desde este ABM: tiene su propia pantalla en Integraciones.',
            ], 422);
        }

        $model = PlatformConnector::create([
            'user_id'            => $this->userId(),
            'platform_id'        => (int) $request->platform_id,
            'status'             => PlatformConnector::STATUS_SIN_CONECTAR,
            'auth_code'          => null,
            'access_token'       => null,
            'refresh_token'      => null,
            'expires_at'         => null,
            'platform_user_id'   => null,
            'error_message'      => null,
        ]);

        $this->sendAddModelNotification('PlatformConnector', $model->id);

        return response()->json(['model' => $this->fullModel('PlatformConnector', $model->id)], 201);
    }

    /**
     * Muestra un conector si pertenece al usuario autenticado.
     *
     * @param int|string $id Identificador del conector.
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $this->assert_owned_connector($id);

        return response()->json(['model' => $this->fullModel('PlatformConnector', $id)], 200);
    }

    /**
     * Permite cambiar de plataforma solo mientras el conector no está conectado.
     *
     * @param Request $request Payload parcial o completo.
     * @param int|string $id Identificador del conector.
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $model = $this->assert_owned_connector($id);

        if ($request->has('platform_id')) {
            if ($model->status === PlatformConnector::STATUS_CONECTADO) {
                return response()->json(['message' => 'No se puede cambiar la plataforma de un conector ya conectado.'], 422);
            }
            $platform = Platform::query()->findOrFail((int) $request->platform_id);

            // Mismo criterio que `store()`: no se deja mover un conector a una plataforma que no
            // se conecta desde este ABM.
            if (!in_array($platform->slug, Platform::SLUGS_CONECTABLES_POR_ABM, true)) {
                return response()->json([
                    'message' => 'La plataforma "'.$platform->name.'" no se conecta desde este ABM: tiene su propia pantalla en Integraciones.',
                ], 422);
            }

            $model->platform_id = (int) $request->platform_id;
        }

        $model->save();

        $this->sendAddModelNotification('PlatformConnector', $model->id);

        return response()->json(['model' => $this->fullModel('PlatformConnector', $model->id)], 200);
    }

    /**
     * Elimina el conector del usuario.
     *
     * @param int|string $id Identificador del conector.
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $model = $this->assert_owned_connector($id);
        $model->delete();
        $this->sendDeleteModelNotification('PlatformConnector', $model->id);

        return response(null);
    }

    /**
     * Obtiene el conector y valida pertenencia al tenant actual.
     *
     * @param int|string $id Identificador del conector.
     * @return PlatformConnector
     */
    protected function assert_owned_connector($id): PlatformConnector
    {
        $model = PlatformConnector::where('id', $id)
            ->where('user_id', $this->userId())
            ->first();
        if (!$model) {
            abort(404);
        }

        return $model;
    }
}
