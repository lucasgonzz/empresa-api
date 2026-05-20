<?php

namespace App\Http\Controllers;

use App\Models\ExportHistory;

class ExportHistoryController extends Controller
{
    /**
     * Lista el historial de exportaciones del owner filtrado por modelo.
     *
     * @param string $model_name
     * @return \Illuminate\Http\JsonResponse
     */
    public function index($model_name)
    {
        $models = ExportHistory::where('user_id', $this->userId())
                                ->where('model_name', $model_name)
                                ->orderBy('id', 'DESC')
                                ->withAll()
                                ->take(50)
                                ->get();

        return response()->json(['models' => $models], 200);
    }
}
