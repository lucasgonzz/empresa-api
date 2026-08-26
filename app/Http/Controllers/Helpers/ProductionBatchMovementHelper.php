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

    /**
     * Resuelve cuál es el estado en el que el lote da de alta el producto terminado.
     *
     * Cascada, en orden:
     *   (a) el end_order_production_status_id de la RUTA del lote, si está seteado;
     *   (b) el estado de mayor position DENTRO del grupo de la ruta;
     *   (c) el estado de mayor position de toda la cuenta.
     *
     * (c) es obligatoria y no se puede sacar: es lo que mantiene andando a los clientes que
     * ya venían usando el módulo con una sola lista global de estados y ninguna ruta con
     * estado final propio. Toda ruta existente cae a (c) y no cambia nada para ella.
     *
     * El desempate por id DESC no es decorativo: dos estados con la misma position dan un
     * ganador arbitrario, y este método se llama dos veces sobre el mismo movimiento (al
     * crearlo y al borrarlo). Sin desempate, borrar podía revertir contra otro estado.
     *
     * ⚠️ NO usa get_recipe_route(): ese método hace abort(422) si el lote no tiene ruta, y en
     * el camino del borrado un 422 dejaría un movimiento imborrable para siempre. Acá se lee
     * $batch->recipe_route directo y, si viene null, se salta a (c).
     *
     * @param  \App\Models\ProductionBatch  $batch
     * @return \App\Models\OrderProductionStatus|null
     */
    private static function resolve_end_status(ProductionBatch $batch)
    {
        $route = $batch->recipe_route;

        // (a) La ruta declara explícitamente en qué estado la unidad queda terminada.
        if (
            !is_null($route)
            && !is_null($route->end_order_production_status_id)
            && $route->end_order_production_status_id != 0
        ) {
            $status = OrderProductionStatus::find($route->end_order_production_status_id);

            if (!is_null($status)) {
                return $status;
            }
        }

        // (b) La ruta tiene grupo: el último estado DE ESE GRUPO, no el de toda la cuenta.
        if (
            !is_null($route)
            && !is_null($route->order_production_status_group_id)
            && $route->order_production_status_group_id != 0
        ) {
            $status = OrderProductionStatus::where('order_production_status_group_id', $route->order_production_status_group_id)
                                            ->orderBy('position', 'DESC')
                                            ->orderBy('id', 'DESC')
                                            ->first();

            if (!is_null($status)) {
                return $status;
            }
        }

        // (c) El comportamiento de siempre.
        return self::end_status_global($batch);
    }

    /**
     * La rama (c) sola: el estado de mayor position de toda la cuenta del lote.
     *
     * Está separada de resolve_end_status() porque es EL COMPORTAMIENTO VIEJO, y hay un lugar
     * —el fallback de output_stock_applied null— donde hay que resolver el estado final como se
     * resolvía ANTES de que existieran el estado final por ruta y los grupos. Ahí llamar a la
     * cascada entera daría otro estado y revertiría mal (ver apply_output_stock_if_end_status).
     *
     * El user_id sale del LOTE y no de la sesión: el lote ya sabe de quién es, y este método
     * corre también desde el borrado, donde el usuario logueado puede no ser el dueño del lote.
     *
     * @param  \App\Models\ProductionBatch  $batch
     * @return \App\Models\OrderProductionStatus|null
     */
    private static function end_status_global(ProductionBatch $batch)
    {
        $user_id = !is_null($batch->user_id) ? $batch->user_id : UserHelper::userId();

        return OrderProductionStatus::where('user_id', $user_id)
                                    ->orderBy('position', 'DESC')
                                    ->orderBy('id', 'DESC')
                                    ->first();
    }

    private static function apply_output_stock_if_end_status(ProductionBatch $batch, ProductionBatchMovement $movement, $controller_instance, int $direction)
    {
        /*
         * 🔴 AL BORRAR MANDA LO QUE SE REGISTRÓ AL CREAR, NO LO QUE LA CASCADA RESUELVA AHORA.
         *
         * Este método se llama dos veces sobre el mismo movimiento: con direction +1 al crearlo
         * y con -1 al borrarlo, y entre una llamada y la otra pueden pasar semanas. En el medio
         * el usuario puede cambiar el estado final de la ruta, cambiarle el grupo, agregar un
         * estado con position más alta o mover una position. En cualquiera de esos casos,
         * resolver la cascada de nuevo al borrar da OTRO estado, la comparación no matchea y el
         * borrado no revierte lo que ese movimiento sumó: queda stock fantasma, sin error y sin
         * log.
         *
         * Por eso el resultado se REGISTRA en output_stock_applied cuando el hecho ocurre. No es
         * cachear estado derivado: la fuente no está vigente, es la configuración del momento en
         * que el movimiento se hizo, y esa configuración es mutable. Es la misma razón por la
         * que un stock_movement guarda su stock_resultante.
         *
         * 🔴 SI LA COLUMNA ES NULL, SE RESUELVE POR LA RAMA GLOBAL SOLA, NO POR LA CASCADA.
         *
         * La columna en null significa una cosa sola: el movimiento se creó ANTES de esta
         * migración, o sea cuando el estado final era siempre el de mayor position de toda la
         * cuenta y no existían ni el estado final por ruta ni los grupos. Entonces lo que hay que
         * reconstruir al borrarlo es ESE criterio y ninguno otro: end_status_global().
         *
         * Pasar por la cascada entera acá sería resolver un movimiento viejo con reglas que no
         * existían cuando se creó, y rompe en los dos sentidos. Con Corte(1)/Armado(2)/
         * Terminado(3): un movimiento viejo hacia Terminado sumó 10 y quedó con la columna en
         * null; después del deploy el usuario le pone end_order_production_status_id = Armado a
         * la ruta. Al borrarlo, la cascada devolvería Armado ≠ Terminado, no revertiría, y
         * quedarían +10 fantasma. El espejo también: un movimiento viejo hacia Armado, que no
         * sumó nada, restaría 10 que nunca entraron.
         *
         * Compatibilidad hacia atrás sin backfill.
         */
        if ($direction >= 0) {

            $end_status = self::resolve_end_status($batch);

            $aplica = (
                !is_null($end_status)
                && (int)$movement->to_order_production_status_id === (int)$end_status->id
            );

            $movement->output_stock_applied = $aplica ? 1 : 0;
            $movement->save();

        } else if (!is_null($movement->output_stock_applied)) {

            $aplica = ((int)$movement->output_stock_applied === 1);

        } else {

            // Movimiento anterior a la migración: se resuelve con el criterio que estaba vigente
            // cuando se creó, que es la rama global sola.
            $end_status = self::end_status_global($batch);

            $aplica = (
                !is_null($end_status)
                && (int)$movement->to_order_production_status_id === (int)$end_status->id
            );
        }

        if (!$aplica) {
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

        // Mismo formato que los movimientos de insumo, para poder rastrear el alta del producto
        // hasta el movimiento del lote que la produjo y no solo hasta el lote.
        $data['observations'] = 'Batch #'.$batch->id.' mov #'.$movement->id;

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