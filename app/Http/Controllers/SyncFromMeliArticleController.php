<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Jobs\SyncFromMeliArticlesJob;
use App\Models\PlatformConnector;
use App\Models\SyncFromMeliArticle;
use Illuminate\Http\Request;

/**
 * Sincronización entrante: publicaciones de Mercado Libre → artículos locales.
 */
class SyncFromMeliArticleController extends Controller
{
    /**
     * Lista corridas de importación del usuario (opcional filtro por fechas).
     *
     * @param string|null $from_date
     * @param string|null $until_date
     * @return \Illuminate\Http\JsonResponse
     */
    public function index($from_date = null, $until_date = null)
    {
        $models = SyncFromMeliArticle::where('user_id', $this->userId())
            ->orderBy('created_at', 'DESC')
            ->withAll();
        if (!is_null($from_date)) {
            if (!is_null($until_date)) {
                $models = $models->whereDate('created_at', '>=', $from_date)
                    ->whereDate('created_at', '<=', $until_date);
            } else {
                $models = $models->whereDate('created_at', $from_date);
            }
        }

        $models = $models->get();

        return response()->json(['models' => $models], 200);
    }

    /**
     * Muestra una corrida de importación del usuario autenticado.
     *
     * @param int|string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $model = SyncFromMeliArticle::where('id', $id)
            ->where('user_id', $this->userId())
            ->withAll()
            ->first();
        if (!$model) {
            abort(404);
        }

        return response()->json(['model' => $model], 200);
    }

    /**
     * Encola una nueva importación desde Mercado Libre.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $platform_connector = PlatformConnector::find_connected_mercado_libre_for_user((int) $this->userId());
        if (!$platform_connector) {
            return response()->json([
                'message' => 'Conectá tu cuenta de Mercado Libre antes de importar artículos (Integraciones → Conector de plataforma).',
            ], 422);
        }

        $pending = SyncFromMeliArticle::where('user_id', $this->userId())
            ->whereIn('status', [
                SyncFromMeliArticle::STATUS_PENDIENTE,
                SyncFromMeliArticle::STATUS_EN_PROGRESO,
            ])
            ->exists();
        if ($pending) {
            return response()->json([
                'message' => 'Ya hay una importación desde Mercado Libre en curso. Esperá a que finalice.',
            ], 422);
        }

        $model = SyncFromMeliArticle::create([
            'user_id' => $this->userId(),
            'status'  => SyncFromMeliArticle::STATUS_PENDIENTE,
        ]);

        dispatch(new SyncFromMeliArticlesJob($model->id));

        $this->sendAddModelNotification('SyncFromMeliArticle', $model->id);

        return response()->json(['model' => $this->fullModel('SyncFromMeliArticle', $model->id)], 201);
    }

    /**
     * Elimina un registro de sincronización y sus imágenes asociadas si las hubiera.
     *
     * @param int|string $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $model = SyncFromMeliArticle::where('id', $id)
            ->where('user_id', $this->userId())
            ->first();
        if (!$model) {
            abort(404);
        }
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('SyncFromMeliArticle', $model->id);

        return response(null);
    }
}
