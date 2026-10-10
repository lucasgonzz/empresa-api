<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\ProductionBatchMovementHelper;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchMovement;
use Illuminate\Http\Request;

class ProductionBatchMovementController extends Controller
{
    /**
     * Textos del "Hacia estado" obligatorio, iguales para el alta y para la vista previa.
     *
     * El select de la SPA manda 0 cuando el usuario deja "Seleccione...", y un movimiento hacia el
     * estado 0 no consume nada, no da de alta el producto y deja una cantidad "en un estado que
     * no existe" (medido en Quino2). Por eso 0 se rechaza igual que la falta del campo, y el aviso
     * habla de lo que el usuario ve en pantalla ("Hacia estado"), no del nombre de la columna.
     *
     * Es la misma regla en los seis tipos de movimiento (decision de Lucas, 10/10/2026).
     *
     * @return array
     */
    private function mensajes_del_hacia_estado()
    {
        $texto = 'Elegí el estado al que va el movimiento ("Hacia estado").';

        return [
            'to_order_production_status_id.required'    => $texto,
            'to_order_production_status_id.integer'     => $texto,
            'to_order_production_status_id.min'         => $texto,
        ];
    }

    /**
     * Preview: devuelve insumos planificados (y editable actual_amount) para renderizar la tablita
     */
    public function preview(Request $request)
    {
        $request->validate([
            'production_batch_id'                 => 'required|integer',
            'production_batch_movement_type_id'   => 'required|integer',
            'to_order_production_status_id'       => 'required|integer|min:1',
            'from_order_production_status_id'     => 'nullable|integer',
            'amount'                              => 'required|numeric|min:0.0001',
            'provider_id'                         => 'nullable|integer',
            'address_id'                          => 'nullable|integer',
        ], $this->mensajes_del_hacia_estado());

        $batch = ProductionBatch::with('recipe', 'recipe_route.articles')->findOrFail($request->production_batch_id);

        $result = ProductionBatchMovementHelper::preview_movement($batch, $request);

        return response()->json($result, 200);
    }

    /**
     * Store: crea el movimiento + inputs + descuenta stock insumos + incrementa stock producto si corresponde
     */
    public function store(Request $request)
    {
        $request->validate([
            'production_batch_id'                 => 'required|integer',
            'production_batch_movement_type_id'   => 'required|integer',
            'to_order_production_status_id'       => 'required|integer|min:1',
            'from_order_production_status_id'     => 'nullable|integer',
            'amount'                              => 'required|numeric|min:0.0001',
            'provider_id'                         => 'nullable|integer',
            'address_id'                          => 'nullable|integer',
            'meta'                                => 'nullable|array',

            // inputs opcional: si viene, pisa el actual_amount (editable)
            'inputs'                              => 'nullable|array',
            'inputs.*.article_id'                 => 'required_with:inputs|integer',
            'inputs.*.address_id'                 => 'nullable|integer',
            'inputs.*.actual_amount'              => 'required_with:inputs|numeric|min:0',
        ], $this->mensajes_del_hacia_estado());

        $batch = ProductionBatch::with('recipe', 'recipe_route.articles')->findOrFail($request->production_batch_id);

        $movement = ProductionBatchMovementHelper::create_movement($batch, $request, $this);

        return response()->json(['model' => $this->fullModel('ProductionBatchMovement', $movement->id)], 201);
    }

   
    public function update(Request $request, $id)
    {

        $movement = ProductionBatchMovement::find($id);
        $movement->notes = $request->notes;
        $movement->save();

        $movement = ProductionBatchMovementHelper::update_movement_inputs($movement, $request, $this);

        return response()->json(['production_batch' => $this->fullModel('ProductionBatchMovement', $movement->id)], 200);
    }

    /**
     * Ajustar consumos reales (delta stock)
     */
    public function update_inputs(Request $request, $id)
    {

        $movement = ProductionBatchMovement::with('inputs')->findOrFail($id);

        $movement = ProductionBatchMovementHelper::update_movement_inputs($movement, $request, $this);

        return response()->json(['production_batch' => $this->fullModel('ProductionBatch', $movement->production_batch_id)], 200);
    }

    /**
     * Destroy: revierte stock (insumos + producido si aplica) y elimina movimiento
     */
    public function destroy($id)
    {
        $movement = ProductionBatchMovement::with('production_batch.recipe', 'inputs')->findOrFail($id);

        ProductionBatchMovementHelper::delete_movement($movement, $this);

        return response(null, 204);
    }
}