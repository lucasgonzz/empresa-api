<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\ChequeHelper;
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

    /**
     * Un banco del catálogo, resuelto por banco_del_dueno() igual que update() y destroy(): un id
     * de otra cuenta, o uno que no existe, es un 404.
     *
     * 🔴 No volver a un `fullModel('ChequeBanco', $id)` pelado (que es lo que había hasta el
     * 3/10/2026, misión cheques-filtro-por-dueno): un id ajeno se contesta igual que uno
     * inexistente; en una base compartida los ids son correlativos entre comercios, y así se leía
     * el banco de cualquier comercio sumando 1 (y un id inexistente era un 200 con `model: null`).
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id) {
        $model = $this->banco_del_dueno($id);
        return response()->json(['model' => $this->fullModel('ChequeBanco', $model->id)], 200);
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
     * El banco, scopeado por dueño: un id de otra cuenta es un 404, no un banco ajeno leído,
     * editado o borrado. Es el resolvedor de show(), update() y destroy().
     *
     * @param  int  $id
     * @return \App\Models\ChequeBanco
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException  Si el banco no es del dueño o no
     *                                                                existe (Laravel lo contesta 404
     *                                                                y no lo reporta).
     */
    protected function banco_del_dueno($id) {
        // El id de la ruta, con LA lectura de ids (ChequeHelper::id_del_pedido()): hasta el 3/10/2026
        // iba crudo al where y MySQL lo casteaba ('84abc' o ' 84' eran el banco 84). Un texto que no
        // es un id se lee como 0, y ningún banco tiene el id 0: es el mismo 404.
        return ChequeBanco::where('user_id', $this->userId())
                            ->where('id', ChequeHelper::id_del_pedido($id))
                            ->firstOrFail();
    }
}
