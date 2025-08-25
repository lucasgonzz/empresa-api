<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Jobs\ProcessSyncArticleDescriptionTiendaNube;
use App\Models\Description;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DescriptionController extends Controller
{

    public function index() {
        $models = Description::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Description::create([
            'title'                 => $request->title,
            'article_id'            => $request->model_id,
            'temporal_id'           => $this->getTemporalId($request),
            'content'               => $request->content,
            // 'user_id'               => $this->userId(),
        ]);

        $this->check_tienda_nube($model);

        // if (!is_null($request->model_id)) {
        //     $this->sendAddModelNotification('article', $model->article_id, false);
        // }
        return response()->json(['model' => $this->fullModel('Description', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Description', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Description::find($id);
        $model->title                = $request->title;
        $model->content              = $request->content;
        $model->save();
        $this->check_tienda_nube($model);
        // $this->sendAddModelNotification('Description', $model->id);
        return response()->json(['model' => $this->fullModel('Description', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Description::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Description', $model->id);
        return response(null);
    }

    function check_tienda_nube($description) {

        if (env('USA_TIENDA_NUBE', false) && $description->article) {
            Log::info('Mandando ProcessSyncArticleDescriptionTiendaNube');
            dispatch(new ProcessSyncArticleDescriptionTiendaNube($description->article));
        } else {
            Log::info('NO SE MANDO ProcessSyncArticleDescriptionTiendaNube');
        }
    }
}
