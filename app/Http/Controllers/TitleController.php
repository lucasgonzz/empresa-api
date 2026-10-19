<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\TenenciaDeLaTiendaHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\Title;
use Illuminate\Http\Request;

class TitleController extends Controller
{

    public function index() {
        $models = Title::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Title::create([
            'num'                   => $this->num('titles'),
            'header'                => $request->header,
            'lead'                  => $request->lead,
            'image_url'             => $request->image_url,
            'crop_image_url'        => $request->crop_image_url,
            'hosting_image_url'     => $request->hosting_image_url,
            'text_color'            => $request->text_color,
            'color'                 => $request->color,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('Title', $model->id);
        return response()->json(['model' => $this->fullModel('Title', $model->id)], 201);
    }  

    public function show($id) {
        $ajeno = $this->registro_ajeno_o_404($id);
        if ($ajeno) {
            return $ajeno;
        }

        return response()->json(['model' => $this->fullModel('Title', $id)], 200);
    }

    public function update(Request $request, $id) {
        $ajeno = $this->registro_ajeno_o_404($id);
        if ($ajeno) {
            return $ajeno;
        }

        $model = Title::find($id);
        $model->header                = $request->header;
        $model->lead                  = $request->lead;
        $model->image_url             = $request->image_url;
        $model->crop_image_url        = $request->crop_image_url;
        $model->hosting_image_url     = $request->hosting_image_url;
        $model->text_color            = $request->text_color;
        $model->color                 = $request->color;
        $model->save();
        $this->sendAddModelNotification('Title', $model->id);
        return response()->json(['model' => $this->fullModel('Title', $model->id)], 200);
    }

    public function destroy($id) {
        $ajeno = $this->registro_ajeno_o_404($id);
        if ($ajeno) {
            return $ajeno;
        }

        $model = Title::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Title', $model->id);
        return response(null);
    }

    /**
     * El banner (título) tiene que ser del comercio de la sesión.
     *
     * Devuelve null si el registro es del dueño de la sesión y, si no, el 404 que el método contesta
     * tal cual (ajeno, inexistente y no-id dan lo mismo). Ver `TenenciaDeLaTiendaHelper`.
     *
     * @param  mixed  $id  Lo que llegó en la ruta.
     * @return \Illuminate\Http\JsonResponse|null
     */
    protected function registro_ajeno_o_404($id) {
        return TenenciaDeLaTiendaHelper::respuesta_si_no_es_del_dueno(Title::class, $id, $this->userId(), 'El banner no existe o no es de tu cuenta.');
    }
}
