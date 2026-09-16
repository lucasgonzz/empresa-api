<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\combo\ComboAltaHelper;
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

    /**
     * 🔴 El alta en sí (el create, el attach de los artículos con su cantidad y la transacción que
     * los envuelve) vive en ComboAltaHelper::crear() desde la misión agente-ia-mano-derecha
     * (16/9/2026), porque el asistente de IA también da de alta combos y no puede pasar por acá.
     * Acá queda lo que es del HTTP: leer el request y armar la respuesta. El payload y las
     * respuestas de `POST api/combo` no cambiaron, y este camino sigue SIN validar nada — la
     * validación del helper (ComboAltaHelper::validar()) la llama el asistente, no la pantalla:
     * ver el docblock del helper.
     *
     * 🔴 `online` VA EN EL only(), Y NO ES UN CAMPO MÁS (misión combos-y-rangos-de-precio,
     * 16/9/2026). Lo que el only() no nombra no llega al helper, y olvidarlo acá no rompe nada a
     * la vista: el alta responde 201, el combo queda creado, y lo único que pasa es que nace
     * apagado aunque el dueño haya tildado "Mostrar en la tienda". Se publica recién si lo reabre
     * y lo vuelve a guardar, porque update() sí lo escribe — o sea, editar anda y crear no, sin un
     * error en ningún lado y sin que ningún test se ponga rojo. Si mañana el ABM suma otra columna,
     * va en esta lista en el mismo diff.
     *
     * @param Request $request name, cost, price, articles, online
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request) {

        // El correlativo va como closure para que num() corra ADENTRO de la transacción del helper
        // y su lockForUpdate se sostenga hasta el commit (ver el docblock de ComboAltaHelper::crear()).
        $model = ComboAltaHelper::crear($request->only(['name', 'cost', 'price', 'articles', 'online']), $this->userId(), function () {
            return $this->num('combos');
        });

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
        /*
            El interruptor que publica el combo en el ecommerce. Se normaliza a 1/0 y no se asigna
            pelado: `combos.online` es NOT NULL con default 0, y una asignación de null la rompe en
            MySQL estricto. Además una empresa-spa vieja (sin el check en el ABM) no manda la clave,
            y con esta forma el combo queda apagado, que es la dirección segura.

            🔴 El MISMO criterio —la misma truthiness de PHP— corre en el alta, que desde el
            refactor de agente-ia-mano-derecha vive en ComboAltaHelper::crear(). Si alguna vez hay
            que cambiarlo, se cambia en los dos lados o crear y editar empiezan a decir cosas
            distintas sobre el mismo combo.
        */
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
