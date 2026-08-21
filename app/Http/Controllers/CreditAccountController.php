<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use Illuminate\Http\Request;

class CreditAccountController extends Controller
{

    function index($credit_account_id, $cantidad_movimientos) {
        $models = CurrentAcount::where('credit_account_id', $credit_account_id)
                            // ->where('model_name', $model_name)
                            // ->where('model_id', $model_id)
                            ->orderBy('created_at', 'DESC')
                            ->take($cantidad_movimientos)
                            ->with('current_acount_payment_methods')
                            ->with('pagado_por')
                            ->with('cheques')
                            ->with('sale.afip_tickets', 'afip_ticket')
                            // ->get();
                            ->get();

        if (!UserHelper::user()->cc_ultimas_arriba) {
            $models = $models->reverse()->values();
        }
                            
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = CreditAccount::create([
            'num'                   => $this->num(''),
            'name'                  => $request->name,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('CreditAccount', $model->id);
        return response()->json(['model' => $this->fullModel('CreditAccount', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('CreditAccount', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = CreditAccount::find($id);
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('CreditAccount', $model->id);
        return response()->json(['model' => $this->fullModel('CreditAccount', $model->id)], 200);
    }

    public function destroy($id) {
        $model = CreditAccount::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('CreditAccount', $model->id);
        return response(null);
    }

    /**
     * Fija (o borra) el límite de crédito de UNA cuenta: una entidad × una moneda (misión 160).
     *
     * Endpoint propio y no ClientController@update porque el límite no vive en `clients`: vive en
     * `credit_accounts`, una fila por moneda. `ClientController@update` reasigna todos los campos
     * del cliente desde el request, y un array de límites metido ahí lo obligaría a decidir sobre
     * dos tablas a la vez.
     *
     * Si la cuenta de esa moneda todavía no existe (cliente viejo que nunca tuvo movimientos en
     * dólares), se crea con CreditAccountHelper::crear_credit_accounts(), que es el ÚNICO lugar del
     * sistema donde nacen las credit_account y es idempotente. No se crea a mano acá.
     *
     * limite_credito null o '' => se borra el límite (vuelve al comportamiento sin tope).
     */
    public function update_limite_credito(Request $request) {

        $model_name = $request->model_name;

        if ($model_name !== 'client' && $model_name !== 'provider') {
            return response()->json(['message' => 'model_name inválido: tiene que ser "client" o "provider".'], 422);
        }

        $model_id = (int) $request->model_id;
        $moneda_id = (int) $request->moneda_id;

        if ($moneda_id !== 1 && $moneda_id !== 2) {
            return response()->json(['message' => 'moneda_id inválido: tiene que ser 1 (Peso) o 2 (Dolar).'], 422);
        }

        CreditAccountHelper::crear_credit_accounts($model_name, $model_id);

        $model = CreditAccount::where('model_name', $model_name)
                                ->where('model_id', $model_id)
                                ->where('moneda_id', $moneda_id)
                                // 🔴 No es opcional: sin este where, un POST directo con un
                                // model_id ajeno edita el límite de un cliente de otro comercio.
                                ->where('user_id', $this->userId())
                                ->first();

        if (is_null($model)) {
            return response()->json(['message' => 'No existe la cuenta corriente de esa moneda.'], 404);
        }

        $limite = $request->limite_credito;

        // Un límite en 0 o negativo se guarda como null, no como 0: "sin límite" y "no puede deber
        // ni un peso" son cosas distintas, y un 0 escrito por accidente frenaría todas las ventas
        // de ese cliente.
        if (is_null($limite) || $limite === '' || (float) $limite <= 0) {
            $model->limite_credito = null;
        } else {
            $model->limite_credito = round((float) $limite, 2);
        }

        $model->save();

        return response()->json([
            'credit_account' => $model->fresh(),
            'model'          => $this->fullModel($model_name, $model_id),
        ], 200);
    }
}
