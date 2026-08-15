<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\Address;
use Illuminate\Http\Request;

class AddressController extends Controller
{

    public function index() {
        $models = Address::where('user_id', $this->userId())
                            ->orderBy('created_at', 'ASC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Address::create([
            'num'                   => $this->num('addresses'),
            'street'                => $request->street,
            'street_number'         => $request->street_number,
            'city'                  => $request->city,
            'province'              => $request->province,
            'default_address'       => $request->default_address,
            // Designación de depósito de origen preferente para sugerencias de
            // stock (v2). Cast a bool: la columna no admite null y el ABM sin
            // la extensión no manda la clave.
            'es_deposito_origen'    => (bool) $request->es_deposito_origen,
            'user_id'               => $this->userId(),
            // afip_information por defecto de la sucursal, usado para resolver
            // la identidad fiscal en ventas en negro (remitos sin facturacion).
            'default_afip_information_id' => $request->default_afip_information_id,
        ]);
        $this->sendAddModelNotification('Address', $model->id);
        return response()->json(['model' => $this->fullModel('Address', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Address', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Address::find($id);
        $model->street                = $request->street;
        $model->phone                 = $request->phone;
        $model->email                 = $request->email;
        $model->street_number         = $request->street_number;
        $model->city                  = $request->city;
        $model->province              = $request->province;
        $model->default_address       = $request->default_address;
        // Solo si el request trae la clave CON valor: el ABM sin la extensión de
        // sugerencias no la manda, y no debe pisar una designación existente.
        // El !is_null es la otra mitad del criterio (el mismo de
        // usar_condicion_fiscal_en_costeo en UserController@update): sin él, un
        // payload con la clave en null des-designa el depósito en silencio.
        if ($request->has('es_deposito_origen') && !is_null($request->es_deposito_origen)) {
            $model->es_deposito_origen = (bool) $request->es_deposito_origen;
        }
        // afip_information por defecto de la sucursal, usado para resolver
        // la identidad fiscal en ventas en negro (remitos sin facturacion).
        $model->default_afip_information_id = $request->default_afip_information_id;
        $model->save();
        $this->sendAddModelNotification('Address', $model->id);
        return response()->json(['model' => $this->fullModel('Address', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Address::find($id);

        $model->articles()->detach();

        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Address', $model->id);
        return response(null);
    }
}
