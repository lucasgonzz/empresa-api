<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\address\AjusteDePreciosDeSucursalHelper;
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

        /*
         * Ajuste de precios de la sucursal (recargo o descuento en %). Se resuelve y se valida ANTES
         * de crear nada: un valor invalido es un 422 y no queda ni la sucursal a medio escribir.
         * `null` = no hay nada que escribir (las columnas todavia no existen porque el deploy sube
         * el codigo antes de migrar, o la SPA no manda las claves): se crea la sucursal como siempre.
         */
        $ajuste = AjusteDePreciosDeSucursalHelper::resolver_del_request($request);

        if (!is_null($ajuste) && !$ajuste['valido']) {
            return response()->json(['message' => $ajuste['mensaje']], 422);
        }

        $datos = [
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
        ];

        // Depósito madre (misión deposito-madre): mismo cast que es_deposito_origen, la clave
        // ausente queda en 0. Si viene en 1, el hook `saved` de Address desmarca la madre anterior
        // del comercio. Solo si la columna ya existe (Address::columna_madre_existe(): el deploy
        // sube el código antes de migrar, y nombrarla sin la columna era un 500).
        if (Address::columna_madre_existe()) {
            $datos['es_deposito_madre'] = (bool) $request->es_deposito_madre;
        }

        $model = Address::create(array_merge($datos, is_null($ajuste) ? [] : $ajuste['valores']));
        $this->sendAddModelNotification('Address', $model->id);
        return response()->json(['model' => $this->fullModel('Address', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Address', $id)], 200);
    }

    public function update(Request $request, $id) {

        /*
         * Ajuste de precios de la sucursal. Igual que en store, se valida antes de tocar el modelo.
         *
         * 🔴 UPDATE SIN NINGUNA DE LAS DOS CLAVES NO TOCA EL AJUSTE (el helper devuelve null). Una SPA
         * vieja no conoce las claves y no las manda: si aca se escribiera el par "siempre", el primer
         * guardado de la sucursal desde esa SPA le borraria el recargo o el descuento en silencio.
         * Y sin las columnas todavia (deploy a medio hacer) tampoco se las nombra: seria un 500.
         */
        $ajuste = AjusteDePreciosDeSucursalHelper::resolver_del_request($request);

        if (!is_null($ajuste) && !$ajuste['valido']) {
            return response()->json(['message' => $ajuste['mensaje']], 422);
        }

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
        // Depósito madre: el MISMO guard que es_deposito_origen y por los mismos motivos (el ABM
        // sin la extensión no manda la clave, y un null no puede desmarcar el madre en silencio).
        // La unicidad (marcar esta desmarca a la anterior) la resuelve el hook de Address. Y sin la
        // columna todavía (deploy a medio migrar) no se la nombra: sería un 500.
        if (Address::columna_madre_existe()
            && $request->has('es_deposito_madre') && !is_null($request->es_deposito_madre)) {
            $model->es_deposito_madre = (bool) $request->es_deposito_madre;
        }
        // afip_information por defecto de la sucursal, usado para resolver
        // la identidad fiscal en ventas en negro (remitos sin facturacion).
        $model->default_afip_information_id = $request->default_afip_information_id;
        if (!is_null($ajuste)) {
            foreach ($ajuste['valores'] as $columna => $valor) {
                $model->{$columna} = $valor;
            }
        }
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
