<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\TenenciaDeLaTiendaHelper;
use App\Models\Brand;
use Illuminate\Http\Request;

class BrandController extends Controller
{

    public function index() {
        $models = Brand::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Brand::create([
            'num'                   => $this->num('brands'),
            'name'                  => $request->name,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('Brand', $model->id);
        return response()->json(['model' => $this->fullModel('Brand', $model->id)], 201);
    }  

    public function show($id) {
        $ajeno = $this->registro_ajeno_o_404($id);
        if ($ajeno) {
            return $ajeno;
        }

        return response()->json(['model' => $this->fullModel('Brand', $id)], 200);
    }

    public function update(Request $request, $id) {
        $ajeno = $this->registro_ajeno_o_404($id);
        if ($ajeno) {
            return $ajeno;
        }

        $model = Brand::find($id);
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('Brand', $model->id);
        return response()->json(['model' => $this->fullModel('Brand', $model->id)], 200);
    }

    public function destroy($id) {
        $ajeno = $this->registro_ajeno_o_404($id);
        if ($ajeno) {
            return $ajeno;
        }

        $model = Brand::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Brand', $model->id);
        return response(null);
    }

    /**
     * La marca tiene que ser del comercio de la sesión.
     *
     * Devuelve null si es del dueño de la sesión y, si no, el 404 que el método contesta tal cual
     * (ajeno, inexistente y no-id dan lo mismo). Ver `TenenciaDeLaTiendaHelper`.
     *
     * @param  mixed  $id  Lo que llegó en la ruta.
     * @return \Illuminate\Http\JsonResponse|null
     */
    protected function registro_ajeno_o_404($id) {
        return TenenciaDeLaTiendaHelper::respuesta_si_no_es_del_dueno(Brand::class, $id, $this->userId(), 'La marca no existe o no es de tu cuenta.');
    }
}
