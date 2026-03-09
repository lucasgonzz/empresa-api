<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\ProductionBatchMovementHelper;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchMovement;
use Illuminate\Http\Request;

class ProductionBatchMovementController extends Controller
{
    /**
     * Preview: devuelve insumos planificados (y editable actual_amount) para renderizar la tablita
     */
    public function preview(Request $request)
    {
        $request->validate([
            'production_batch_id'                 => 'required|integer',
            'production_batch_movement_type_id'   => 'required|integer',
            'to_order_production_status_id'       => 'required|integer',
            'from_order_production_status_id'     => 'nullable|integer',
            'amount'                              => 'required|numeric|min:0.0001',
            'provider_id'                         => 'nullable|integer',
            'address_id'                          => 'nullable|integer',
        ]);

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
            'to_order_production_status_id'       => 'required|integer',
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
        ]);

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