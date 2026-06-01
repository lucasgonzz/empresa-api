<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\MasiveUpdateHelper;
use App\Jobs\ProcessMasiveUpdateRevertJob;
use App\Models\MasiveUpdate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MasiveUpdateController extends Controller
{
    /**
     * Lista historial de actualizaciones masivas del owner por modelo.
     *
     * @param string $model_name
     * @return \Illuminate\Http\JsonResponse
     */
    public function index($model_name)
    {
        $models = MasiveUpdate::where('user_id', $this->userId())
            ->where('model_name', $model_name)
            ->orderBy('id', 'DESC')
            ->withAll()
            ->take(50)
            ->get();

        $models->each(function ($masive_update) {
            $masive_update->can_revert = MasiveUpdateHelper::can_revert($masive_update);
        });

        return response()->json(['models' => $models], 200);
    }

    /**
     * Detalle de una actualización masiva con artículos y cambios.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $masive_update = MasiveUpdate::where('user_id', $this->userId())
            ->where('id', $id)
            ->withAll()
            ->with(['articles' => function ($query) {
                $query->select('articles.id', 'articles.name', 'articles.num');
            }])
            ->first();

        if (!$masive_update) {
            return response()->json(['message' => 'Registro no encontrado'], 404);
        }

        $masive_update->can_revert = MasiveUpdateHelper::can_revert($masive_update);
        $masive_update->criteria = json_decode($masive_update->criteria_json, true);

        $articles_detail = [];
        foreach ($masive_update->articles as $article) {
            $articles_detail[] = [
                'id' => $article->id,
                'name' => $article->name,
                'num' => $article->num,
                'changes' => json_decode($article->pivot->changes_json, true),
            ];
        }
        $masive_update->articles_detail = $articles_detail;

        return response()->json(['model' => $masive_update], 200);
    }

    /**
     * Encola la reversión de una actualización masiva completada.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function revert($id)
    {
        $parent_masive_update = MasiveUpdate::where('user_id', $this->userId())
            ->where('id', $id)
            ->first();

        if (!$parent_masive_update) {
            return response()->json(['message' => 'Registro no encontrado'], 404);
        }

        if (!MasiveUpdateHelper::can_revert($parent_masive_update)) {
            return response()->json([
                'message' => 'Esta actualización no puede revertirse',
            ], 422);
        }

        $revert_masive_update = MasiveUpdateHelper::create_pending_revert(
            $parent_masive_update,
            $this->userId(false)
        );

        ProcessMasiveUpdateRevertJob::dispatch($revert_masive_update->id);

        Log::info('MasiveUpdateController: reversión encolada', [
            'parent_id' => $parent_masive_update->id,
            'revert_id' => $revert_masive_update->id,
        ]);

        return response()->json([
            'message' => 'La reversión se está procesando en segundo plano',
            'masive_update_id' => $revert_masive_update->id,
        ], 200);
    }
}
