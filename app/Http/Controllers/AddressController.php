<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Stock\StockMovementController;
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

        /*
         * Tanda correctivos 2408, ítem 7: antes del detach se deja un StockMovement por
         * cada artículo con stock en esta sucursal. Hasta hoy el detach evaporaba ese
         * stock sin dejar rastro: el pivot desaparecía, el stock global del artículo
         * quedaba inflado hasta el próximo recálculo, y en el historial de movimientos no
         * había nada que explicara el salto.
         *
         * El movimiento (from_address_id = la sucursal, amount negativo, concepto
         * "Eliminacion de sucursal") hace las dos cosas por el camino normal de
         * StockMovementController::crear(): deja el pivot de esta sucursal en 0 y
         * recalcula el stock global desde los depósitos, así el detach posterior borra
         * filas que ya están en cero y el número final es consistente.
         */
        foreach ($model->articles()->get() as $article) {

            /** Stock del artículo en ESTA sucursal (pivot del belongsToMany). */
            $stock_en_sucursal = (float) $article->pivot->amount;

            if ($stock_en_sucursal == 0) {
                continue;
            }

            $ct_stock_movement = new StockMovementController();

            $ct_stock_movement->crear([
                'model_id'                     => $article->id,
                'amount'                       => -$stock_en_sucursal,
                'from_address_id'              => $model->id,
                'concepto_stock_movement_name' => 'Eliminacion de sucursal',
                'observations'                 => 'Eliminacion de sucursal '.$model->street,
            ]);
        }

        $model->articles()->detach();

        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Address', $model->id);
        return response(null);
    }
}
