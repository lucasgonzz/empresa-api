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
     * Lista los conectores del usuario que se manejan DESDE ESTE ABM, ordenados por fecha
     * descendente.
     *
     * 🔴 EL FILTRO POR PLATAFORMA NO ES COSMÉTICO. Sin él, el conector de `mercado_pago` que la
     * migración de copia le crea a casi todo comercio con credencial aparecía en la solapa
     * "Sistema" como una fila fantasma: sin título (el `is_title` es `platform_id`, que se
     * resuelve contra el store `platform`, y ese catálogo ya no trae Mercado Pago), sin
     * `auth_url` (`PlatformConnector::getAuthUrlAttribute()` no tiene rama para MP) y con el
     * botón de borrar habilitado. Borrarla se lleva el token OAuth del comercio.
     *
     * `whereDoesntHave` con `whereNotIn` y no `whereHas` con `whereIn`, a propósito: así un
     * conector viejo con `platform_id` en null sigue apareciendo. Está roto igual, pero era
     * visible antes de esta misión y el ABM es el único lugar desde donde se puede limpiar.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $models = PlatformConnector::where('user_id', $this->userId())
            ->whereDoesntHave('platform', function ($platform_query) {
                $platform_query->whereNotIn('slug', Platform::SLUGS_CONECTABLES_POR_ABM);
            })
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
     * Obtiene el conector, valida pertenencia al tenant actual Y que sea de una plataforma que
     * se maneja desde este ABM.
     *
     * 🔴 LA SEGUNDA VALIDACIÓN ES LA QUE PROTEGE EL TOKEN. Filtrar solo `index()` deja pasar un
     * DELETE a mano sobre el conector de Mercado Pago, y borrarlo se lleva puesto el token
     * OAuth: el comercio sigue cobrando —lo cubre el espejo de `payment_methods`— pero su
     * tarjeta de Tienda online pasa a decir "Desconectado" con el cobro vivo. Es exactamente el
     * "disconnect que miente" que esta misión arregló, entrando por otra puerta. Misma lección
     * que ya se aplicó en `store()` y `update()`: si el filtro no se valida, es cosmética.
     *
     * Va acá y no en cada método porque `show()`, `update()` y `destroy()` pasan todos por esta
     * puerta: un guard solo en `destroy` dejaría a los otros dos operando sobre un conector que
     * este ABM no debería ni conocer.
     *
     * Responde 404 y no 422: para este ABM ese conector no existe, que es lo mismo que ya dice
     * `index()`. Un 422 con explicación es para cuando el operador eligió una plataforma a mano
     * (`store`/`update`), no para un id que nunca estuvo en su listado.
     *
     * @param int|string $id Identificador del conector.
     * @return PlatformConnector
     */
    protected function assert_owned_connector($id): PlatformConnector
    {
        $model = PlatformConnector::with('platform')
            ->where('id', $id)
            ->where('user_id', $this->userId())
            ->first();
        if (!$model) {
            abort(404);
        }

        if ($model->platform && !in_array($model->platform->slug, Platform::SLUGS_CONECTABLES_POR_ABM, true)) {
            abort(404);
        }

        return $model;
    }
}
