<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\Combo;
use Illuminate\Http\Request;

class ComboController extends Controller
{

    public function index() {
        $models = Combo::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Combo::create([
            'num'                   => $this->num('combos'),
            'name'                  => $request->name,
            'cost'                  => $request->cost,
            'price'                 => $request->price,
            /*
                El interruptor que publica el combo en el ecommerce (mision
                combos-y-rangos-de-precio, 16/9/2026).

                🔴 Se normaliza a 1/0 y NO se asigna pelado, por dos motivos distintos:

                1. `combos.online` es NOT NULL con default 0. Un `$request->online` ausente vale
                   null, y una asignacion pelada de null a esa columna la rompe en MySQL estricto.
                2. Compatibilidad hacia atras: una empresa-spa vieja, sin el check en el ABM, no
                   manda la clave. Con esta forma el combo nace apagado, que es la direccion
                   segura: nadie estrena combos en su tienda sin haberlo decidido.
            */
            'online'                => $request->online ? 1 : 0,
            'user_id'               => $this->userId(),
        ]);

        GeneralHelper::attachModels($model, 'articles', $request->articles, ['amount']);

        return response()->json(['model' => $this->fullModel('Combo', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Combo', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Combo::find($id);
        $model->name                = $request->name;
        $model->cost                = $request->cost;
        $model->price               = $request->price;
        /* Mismo criterio que en `store()`: 1/0 siempre, nunca null. */
        $model->online              = $request->online ? 1 : 0;
        $model->save();

        GeneralHelper::attachModels($model, 'articles', $request->articles, ['amount']);
        return response()->json(['model' => $this->fullModel('Combo', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Combo::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('Combo', $model->id);
        return response(null);
    }
}
