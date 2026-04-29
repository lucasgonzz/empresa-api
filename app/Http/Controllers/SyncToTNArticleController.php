<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\SyncToTNArticle;
use Illuminate\Http\Request;

class SyncToTNArticleController extends Controller
{

    // FROM DATES
    public function index($from_date = null, $until_date = null) {
        $models = SyncToTNArticle::where('user_id', $this->userId())
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

        $models = $models->paginate(50);
        return response()->json(['models' => $models], 200);
    }

    public function destroy($id) {
        $model = SyncToTNArticle::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        return response(null);
    }

    /**
     * Devuelve la cantidad de sincronizaciones con estado 'error' para el usuario actual.
     * Usado por el frontend para mostrar el badge de errores en el menú de Tienda Nube.
     *
     * @return \Illuminate\Http\JsonResponse { count: int }
     */
    public function failed_count() {
        /* Contar registros con status 'error' del usuario autenticado */
        $count = SyncToTNArticle::where('user_id', $this->userId())
                    ->where('status', 'error')
                    ->count();

        return response()->json(['count' => $count], 200);
    }
}
