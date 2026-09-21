<?php

namespace App\Http\Controllers;

use App\Models\Cheque;
use App\Models\ChequeBanco;
use Illuminate\Http\Request;

/**
 * ABM del catálogo de bancos de cheques (misión cheques-endoso-y-bancos, 21/9/2026), calcado de
 * ExpenseCategoryController: un catálogo por dueño con nombre y nada más.
 *
 * Su `index()` cumple las cuatro condiciones de la whitelist de RecursosInicialesController (sin
 * parámetros, sin leer el request, sin paginar, con la clave `models`), así que la SPA lo baja en
 * la descarga inicial junto con el resto de los catálogos.
 */
class ChequeBancoController extends Controller
{

    public function index() {
        $models = ChequeBanco::where('user_id', $this->userId())
                            ->orderBy('name', 'ASC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = ChequeBanco::create([
            'name'                  => $request->name,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('ChequeBanco', $model->id);
        return response()->json(['model' => $this->fullModel('ChequeBanco', $model->id)], 201);
    }

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ChequeBanco', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = $this->banco_del_dueno($id);
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('ChequeBanco', $model->id);
        return response()->json(['model' => $this->fullModel('ChequeBanco', $model->id)], 200);
    }

    /**
     * Borra el banco del catálogo. Los cheques que lo tenían quedan con `cheque_banco_id` en null
     * y con su texto `banco` INTACTO: el texto es el dato histórico del cheque (lo que decía el
     * papel) y borrar un renglón del catálogo no puede borrar eso.
     */
    public function destroy($id) {
        $model = $this->banco_del_dueno($id);

        Cheque::where('user_id', $this->userId())
                ->where('cheque_banco_id', $model->id)
                ->update(['cheque_banco_id' => null]);

        $model->delete();
        $this->sendDeleteModelNotification('ChequeBanco', $model->id);
        return response(null);
    }

    /**
     * El banco, scopeado por dueño: un id de otra cuenta es un 404, no un banco ajeno editado.
     *
     * @param  int  $id
     * @return \App\Models\ChequeBanco
     */
    protected function banco_del_dueno($id) {
        return ChequeBanco::where('user_id', $this->userId())
                            ->where('id', $id)
                            ->firstOrFail();
    }
}
