<?php

namespace App\Http\Controllers\Helpers\Budget;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Budget;
use App\Models\BudgetStatus;
use Exception;

/**
 * Encapsula la duplicación de un presupuesto existente.
 *
 * Replica líneas (artículos, servicios, promociones, descuentos y recargos) y campos
 * escalares alineados con BudgetController::store, forzando estado "Sin confirmar"
 * para no generar venta ni movimientos de cuenta corriente al clonar.
 */
class BudgetDuplicarHelper {

    /**
     * Clona el presupuesto origen en un registro nuevo y delega adjuntos en los mismos
     * helpers que usa el alta por API.
     *
     * @param Budget $source Presupuesto cargado con relaciones (p. ej. scope `withAll`).
     * @param Controller $controller Controlador actual para correlativo `num()` y `fullModel()` en checkStatus.
     * @return Budget Modelo nuevo persistido con relaciones cargadas (`withAll`).
     *
     * @throws Exception Si el presupuesto no pertenece al usuario autenticado o no existe estado inicial.
     */
    public static function duplicate(Budget $source, Controller $controller): Budget {
        /** Verificación de ownership coherente con BudgetController::index (filtro por user_id). */
        if ((int) $source->user_id !== (int) UserHelper::userId()) {
            throw new Exception('No autorizado a duplicar este presupuesto');
        }

        /** Estado inicial obligatorio para evitar confirmación y venta automática al duplicar. */
        $budget_status = BudgetStatus::where('name', 'Sin confirmar')->first();
        /** Respaldo por migración default(1) si el seed no existiera en el entorno. */
        $budget_status_id = $budget_status ? (int) $budget_status->id : 1;

        /** Campos escalares copiados del origen según BudgetController::store. */
        $model = Budget::create([
            'num'                       => $controller->num('budgets'),
            'client_id'                 => $source->client_id,
            'start_at'                  => $source->start_at,
            'finish_at'                 => $source->finish_at,
            'observations'              => $source->observations,
            'price_type_id'             => $source->price_type_id,
            'sale_status_id'            => $source->sale_status_id,
            'discount_stock'            => !is_null($source->discount_stock) ? $source->discount_stock : 1,
            'iva_aplicado'              => !is_null($source->iva_aplicado) ? $source->iva_aplicado : 1,
            'total'                     => $source->total,
            'budget_status_id'          => $budget_status_id,
            'address_id'                => $source->address_id,
            'surchages_in_services'     => $source->surchages_in_services,
            'discounts_in_services'     => $source->discounts_in_services,
            'moneda_id'                 => $source->moneda_id,
            'valor_dolar'               => $source->valor_dolar,
            'omitir_en_cuenta_corriente' => $source->omitir_en_cuenta_corriente,
            'employee_id'               => $controller->userId(false),
            'user_id'                   => $controller->userId(),
        ]);

        /** Payloads en el formato que esperan GeneralHelper::attachModels y BudgetHelper::attach*. */
        $discounts_payload = self::discounts_to_payload($source);
        $surchages_payload = self::surchages_to_payload($source);

        GeneralHelper::attachModels($model, 'discounts', $discounts_payload, ['percentage'], false);
        GeneralHelper::attachModels($model, 'surchages', $surchages_payload, ['percentage'], false);

        /** Igual que en store: línea base de artículos antes de adjuntar (colección vacía en alta). */
        $previus_articles = $model->articles;

        BudgetHelper::attachArticles($model, self::articles_to_payload($source));
        BudgetHelper::attachServices($model, self::services_to_payload($source));
        BudgetHelper::attachPromocionVinotecas($model, self::promociones_vinoteca_to_payload($source));

        BudgetHelper::checkStatus($controller->fullModel('Budget', $model->id), $previus_articles);

        /** Devuelve instancia alineada con `fullModel` / `withAll` para validaciones posteriores en el controlador. */
        $created = Budget::withAll()->find($model->id);
        if (!$created) {
            throw new Exception('No se pudo recargar el presupuesto duplicado');
        }
        return $created;
    }

    /**
     * Arma el array de descuentos para `GeneralHelper::attachModels` (claves id + percentage).
     *
     * @param Budget $source Presupuesto origen con relación `discounts` cargada.
     * @return array<int, array<string, mixed>>
     */
    private static function discounts_to_payload(Budget $source): array {
        /** Lista acumulada de filas pivot para el nuevo presupuesto. */
        $rows = [];
        foreach ($source->discounts as $discount) {
            $rows[] = [
                'id' => $discount->id,
                'percentage' => $discount->pivot->percentage,
            ];
        }
        return $rows;
    }

    /**
     * Arma el array de recargos para `GeneralHelper::attachModels`.
     *
     * @param Budget $source Presupuesto origen con relación `surchages` cargada.
     * @return array<int, array<string, mixed>>
     */
    private static function surchages_to_payload(Budget $source): array {
        /** Lista acumulada de filas pivot para el nuevo presupuesto. */
        $rows = [];
        foreach ($source->surchages as $surchage) {
            $rows[] = [
                'id' => $surchage->id,
                'percentage' => $surchage->pivot->percentage,
            ];
        }
        return $rows;
    }

    /**
     * Convierte artículos del origen al formato esperado por `BudgetHelper::attachArticles`.
     *
     * @param Budget $source Presupuesto origen con relación `articles` cargada.
     * @return array<int, array<string, mixed>>
     */
    private static function articles_to_payload(Budget $source): array {
        /** Filas listas para attachArticles (incluye pivot y datos para artículos inactivos). */
        $rows = [];
        foreach ($source->articles as $article) {
            $rows[] = [
                'id' => $article->id,
                'status' => $article->status,
                'bar_code' => $article->bar_code,
                'provider_code' => $article->provider_code,
                'name' => $article->name,
                'pivot' => [
                    'amount' => $article->pivot->amount,
                    'bonus' => $article->pivot->bonus,
                    'location' => $article->pivot->location,
                    'price' => $article->pivot->price,
                    'price_type_personalizado_id' => $article->pivot->price_type_personalizado_id,
                ],
            ];
        }
        return $rows;
    }

    /**
     * Convierte servicios del origen al formato esperado por `BudgetHelper::attachServices`.
     *
     * @param Budget $source Presupuesto origen con relación `services` cargada.
     * @return array<int, array<string, mixed>>
     */
    private static function services_to_payload(Budget $source): array {
        /** Filas con id y pivot amount/price. */
        $rows = [];
        foreach ($source->services as $service) {
            $rows[] = [
                'id' => $service->id,
                'pivot' => [
                    'amount' => $service->pivot->amount,
                    'price' => $service->pivot->price,
                ],
            ];
        }
        return $rows;
    }

    /**
     * Convierte promociones vinoteca del origen al formato de `BudgetHelper::attachPromocionVinotecas`.
     *
     * @param Budget $source Presupuesto origen con relación `promocion_vinotecas` cargada.
     * @return array<int, array<string, mixed>>
     */
    private static function promociones_vinoteca_to_payload(Budget $source): array {
        /** Filas con id y pivot amount/price. */
        $rows = [];
        foreach ($source->promocion_vinotecas as $promo) {
            $rows[] = [
                'id' => $promo->id,
                'pivot' => [
                    'amount' => $promo->pivot->amount,
                    'price' => $promo->pivot->price,
                ],
            ];
        }
        return $rows;
    }

}
