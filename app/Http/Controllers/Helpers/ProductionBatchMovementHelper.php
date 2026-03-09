<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Stock\StockMovementController;
use App\Models\OrderProductionStatus;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchMovement;
use App\Models\ProductionBatchMovementInput;
use App\Models\RecipeRoute;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductionBatchMovementHelper
{
    /**
     * Preview: calcula insumos planificados por ruta+estado y arma tablita (planned/actual)
     */
    public static function preview_movement(ProductionBatch $batch, Request $request)
    {
        $recipe_route = self::get_recipe_route($batch);

        $planned_inputs = self::calculate_planned_inputs(
            $recipe_route,
            $request->to_order_production_status_id,
            $request->amount
        );

        // Warning si el usuario definió inputs en ese estado aunque sea receive (según tu decisión: "libre")
        $warnings = [];
        if (count($planned_inputs) > 0) {
            $warnings[] = 'Este movimiento descontará insumos definidos para el estado destino.';
        }

        return [
            'planned_inputs' => $planned_inputs,
            'warnings'       => $warnings,
        ];
    }

    /**
     * Crea movimiento + inputs + stock en 1 transacción
     */
    public static function create_movement(ProductionBatch $batch, Request $request, $controller_instance)
    {
        return DB::transaction(function () use ($batch, $request, $controller_instance) {

            self::validate_provider_rules($request);

            // opcional: validar available en from_status si viene informado (solo para advance)
            // si decidís no usar from_status nunca salvo advance, lo resolvemos en front.
            if (
                !is_null($request->from_order_production_status_id)
                && $request->from_order_production_status_id != 0
            ) {
                $available = self::get_current_amount_in_status($batch->id, $request->from_order_production_status_id);
                if ($request->amount > $available) {
                    abort(422, 'No hay cantidad suficiente en el estado origen para avanzar.');
                }
            }

            $movement = ProductionBatchMovement::create([
                'production_batch_id'                 => $batch->id,
                'production_batch_movement_type_id'   => $request->production_batch_movement_type_id,
                'from_order_production_status_id'     => $request->from_order_production_status_id,
                'to_order_production_status_id'       => $request->to_order_production_status_id,
                'amount'                              => $request->amount,
                'provider_id'                         => $request->provider_id,
                'address_id'                          => $request->address_id,
                'to_address_id'                       => $request->to_address_id,
                'meta'                                => $request->meta ? json_encode($request->meta) : null,
                'employee_id'                         => $request->employee_id,
            ]);

            // inputs planificados
            $recipe_route = self::get_recipe_route($batch);

            $planned_inputs = self::calculate_planned_inputs(
                $recipe_route,
                $movement->to_order_production_status_id,
                $movement->amount,
                $movement->address_id
            );

            // si el front mandó overrides, pisamos actual_amount por artículo (y address si viene)
            $inputs_to_save = self::merge_actual_inputs_overrides($planned_inputs, $request->inputs);

            // guardar inputs y descontar stock insumos (si hay)
            foreach ($inputs_to_save as $input) {
                $row = ProductionBatchMovementInput::create([
                    'production_batch_movement_id' => $movement->id,
                    'article_id'                   => $input['article_id'],
                    'address_id'                   => $input['address_id'] ?? null,
                    'planned_amount'               => $input['planned_amount'],
                    'actual_amount'                => $input['actual_amount'],
                    'order_production_status_id'   => $input['order_production_status_id'] ?? null,
                ]);

                self::apply_input_stock_movement($row, $movement, -1);
            }

            // ingresar stock del producto si llegó al end_status de la receta
            self::apply_output_stock_if_end_status($batch, $movement, $controller_instance, +1);

            return $movement;
        });
    }

    /**
     * Ajusta consumos reales y aplica delta a stock
     */
    public static function update_movement_inputs(ProductionBatchMovement $movement, Request $request, $controller_instance)
    {
        return DB::transaction(function () use ($movement, $request, $controller_instance) {

            foreach ($request->inputs as $input_update) {
                $input = $movement->inputs->firstWhere('id', $input_update['id']);
                if (is_null($input)) {
                    abort(404, 'Insumo no encontrado en el movimiento.');
                }

                $old_actual = (float)$input->actual_amount;
                $new_actual = (float)$input_update['actual_amount'];
                $delta = $new_actual - $old_actual;

                if ($delta != 0.0) {
                    // delta > 0 => descontar extra (negativo), delta < 0 => devolver (positivo)
                    self::apply_delta_stock_for_input($input, $movement, $delta);
                }

                $input->actual_amount = $new_actual;
                $input->save();
            }

            return $movement;
        });
    }

    /**
     * Elimina movimiento y revierte stock (insumos + producto si aplica)
     */
    public static function delete_movement(ProductionBatchMovement $movement, $controller_instance)
    {
        DB::transaction(function () use ($movement, $controller_instance) {

            // revertir stock insumos (devolver)
            foreach ($movement->inputs as $input) {
                self::apply_input_stock_movement($input, $movement, +1);
            }

            // revertir producto si había ingresado stock por end_status
            self::apply_output_stock_if_end_status($movement->production_batch, $movement, $controller_instance, -1);

            $movement->delete();
        });
    }

    /* ============================================================
        Helpers internos
    ============================================================ */

    private static function get_recipe_route(ProductionBatch $batch): RecipeRoute
    {
        if (!is_null($batch->recipe_route)) {
            return $batch->recipe_route;
        }

        // fallback: si no tiene route seteada, intentar default de la receta
        if (!is_null($batch->recipe)) {
            $route = $batch->recipe->recipe_routes()->where('is_default', 1)->first();
            if (!is_null($route)) {
                return $route;
            }
        }

        abort(422, 'El lote no tiene una ruta de receta configurada.');
    }

    /**
     * Calcula planned_inputs para un estado destino:
     * trae insumos de recipe_route_articles cuyo order_production_status_id == to_status_id
     */
    private static function calculate_planned_inputs(RecipeRoute $recipe_route, $to_status_id, $movement_amount, $movement_address_id = null)
    {
        $inputs = [];

        foreach ($recipe_route->articles as $article) {
            $pivot_status_id = $article->pivot->order_production_status_id;

            if (!is_null($pivot_status_id) && (int)$pivot_status_id === (int)$to_status_id) {
                $planned = (float)$article->pivot->amount * (float)$movement_amount;

                $address_id = null;

                if (!is_null($movement_address_id)) {
                    $address_id = $movement_address_id;
                } else if (
                    !is_null($recipe_route->from_address_id)
                    && $recipe_route->from_address_id != 0
                ) {
                    $address_id = $recipe_route->from_address_id;
                } else {
                    $address_id = $article->pivot->address_id;
                }

                $inputs[] = [
                    'article_id'                 => $article->id,
                    'article_name'               => $article->name,
                    'planned_amount'             => $planned,
                    'actual_amount'              => $planned,
                    'address_id'                 => $address_id,
                    'order_production_status_id' => $pivot_status_id,
                    'notes'                      => $article->pivot->notes,
                ];
            }
        }

        return $inputs;
    }

    private static function merge_actual_inputs_overrides(array $planned_inputs, $overrides)
    {
        if (is_null($overrides) || !is_array($overrides)) {
            return $planned_inputs;
        }

        $overrides_by_article = [];
        foreach ($overrides as $o) {
            $overrides_by_article[(int)$o['article_id']] = $o;
        }

        foreach ($planned_inputs as &$pi) {
            $aid = (int)$pi['article_id'];
            if (isset($overrides_by_article[$aid])) {
                $pi['actual_amount'] = (float)$overrides_by_article[$aid]['actual_amount'];
                if (isset($overrides_by_article[$aid]['address_id'])) {
                    $pi['address_id'] = $overrides_by_article[$aid]['address_id'];
                }
            }
        }

        return $planned_inputs;
    }

    private static function apply_input_stock_movement(ProductionBatchMovementInput $input, ProductionBatchMovement $movement, int $direction)
    {
        // direction = -1 descuenta, +1 devuelve
        $amount = (float)$input->actual_amount * (int)$direction;

        if ($amount == 0) {
            return;
        }

        $stock_movement_ct = new StockMovementController();

        $data = [];
        $data['model_id'] = $input->article_id;
        $data['amount'] = $amount; // negativo descuenta, positivo devuelve
        $data['concepto_stock_movement_name'] = 'Insumo de produccion';

        $data['observations'] = 'Batch #'.$movement->production_batch_id.' mov #'.$movement->id;

        if (!is_null($input->address_id)) {
            $data['from_address_id'] = $input->address_id;
        }

        $stock_movement_ct->crear($data);
    }

    private static function apply_delta_stock_for_input(ProductionBatchMovementInput $input, ProductionBatchMovement $movement, float $delta)
    {
        if ($delta == 0.0) {
            return;
        }

        // delta > 0 => consumió más => descuenta extra (negativo)
        // delta < 0 => consumió menos => devuelve (positivo)
        $amount = -$delta;

        $stock_movement_ct = new StockMovementController();

        $data = [];
        $data['model_id'] = $input->article_id;
        $data['amount'] = $amount;
        $data['concepto_stock_movement_name'] = 'Ajuste Insumo de produccion';
        $data['observations'] = 'Ajuste Batch #'.$movement->production_batch_id.' mov #'.$movement->id;

        if (!is_null($input->address_id)) {
            $data['from_address_id'] = $input->address_id;
        }

        $stock_movement_ct->crear($data);
    }

    private static function apply_output_stock_if_end_status(ProductionBatch $batch, ProductionBatchMovement $movement, $controller_instance, int $direction)
    {
        
        $end_status = OrderProductionStatus::where('user_id', UserHelper::userId())  
                                            ->orderBy('position', 'DESC')
                                            ->first();

        if ((int)$movement->to_order_production_status_id !== (int)$end_status->id) {
            return;
        }

        $amount = (float)$movement->amount * (int)$direction;
        if ($amount == 0) {
            return;
        }

        $stock_movement_ct = new StockMovementController();

        $data = [];
        $data['model_id'] = $batch->article_id;
        $data['amount'] = $amount;
        $data['concepto_stock_movement_name'] = 'Produccion';
        $data['observations'] = 'Batch #'.$batch->id;

        // Si querés, acá se puede usar to_address_id (depósito destino del producto terminado)
        if (!is_null($movement->to_address_id)) {
            $data['to_address_id'] = $movement->to_address_id;
        }

        $stock_movement_ct->crear($data);
    }

    /**
     * Valida reglas básicas para provider_id según el tipo de movimiento.
     * Acá aún no resolvemos slug→type porque todavía no creamos helper de lookup por slug.
     * Por ahora, lo hacemos "por id" a nivel DB: cuando seedemos, estos IDs quedan fijos en tu entorno.
     * Mejor práctica: validar por slug (lo hacemos en la próxima iteración).
     */
    private static function validate_provider_rules(Request $request)
    {
        // En la próxima iteración lo pasamos a validar por slug (start/advance/send_to_provider/receive_from_provider/etc.)
        // Por ahora solo exigimos que si viene provider_id, sea integer (ya lo valida Request).
        // Si querés HARD validation ahora: exigimos provider en receive/send si meta trae algo, pero es débil.

        return true;
    }

    /**
     * Cantidad actual en un estado = SUM(to) - SUM(from) por batch y status
     */
    private static function get_current_amount_in_status($production_batch_id, $status_id)
    {
        $in = ProductionBatchMovement::where('production_batch_id', $production_batch_id)
                ->where('to_order_production_status_id', $status_id)
                ->sum('amount');

        $out = ProductionBatchMovement::where('production_batch_id', $production_batch_id)
                ->where('from_order_production_status_id', $status_id)
                ->sum('amount');

        return (float)$in - (float)$out;
    }

}