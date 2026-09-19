<?php

namespace App\Http\Controllers\Helpers\Budget;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\sale\ForzarTotalEsquemaHelper;
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
        $model = Budget::create(ForzarTotalEsquemaHelper::agregar_al_payload([
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
            /*
                Se copia con los demas escalares y no es opcional: los articulos del duplicado se
                adjuntan con el MISMO precio del origen (ya recargado). Sin esta linea el duplicado
                queda con el flag apagado, BudgetHelper::getTotal() vuelve a sumar el recargo y
                BudgetController::duplicate() muere con el mismo 500 del alta.
            */
            'aplicar_recargos_directo_a_items' => $source->aplicar_recargos_directo_a_items,
            /*
                El monto del total forzado (mision forzar-total-por-monto, 17/9/2026). TERCER caso
                del mismo olvido que ya documentan los dos comentarios de arriba y de abajo
                —`aplicar_recargos_directo_a_items` y los combos—, y se rompe exactamente igual: el
                `total` se copia del origen CON el forzado adentro, pero `BudgetHelper::getTotal()`
                lo recalcula sobre el duplicado, que sin esta linea suma 0 de ajuste. La diferencia
                es el monto del forzado y `BudgetController::duplicate()` corta con "El total del
                presupuesto no corresponde con los productos ingresados".

                🔴 Y EL CASO PEOR NO ES EL 500, ES EL QUE NO FALLA. Con un monto de 3 pesos o menos
                la diferencia entra en el margen de tolerancia de `duplicate()`, el duplicado se
                guarda con `total` forzado y `forzar_total_monto` en null —incoherente, en
                silencio— y esa incoherencia despues viaja a la venta por `BudgetHelper::saveSale()`.

                ⚠️ Entra por la guarda de esquema, al final del array: en la ventana entre que el
                deploy sube los archivos y corre las migraciones, `$source->forzar_total_monto`
                devuelve null sin error y ese null viaja igual al INSERT, que revienta con
                `Unknown column`. Ver `ForzarTotalEsquemaHelper`.
            */
            'moneda_id'                 => $source->moneda_id,
            'valor_dolar'               => $source->valor_dolar,
            // Un presupuesto no se puede omitir de la cuenta corriente (decision de Lucas,
            // 18/9/2026): el duplicado nace en 0 aunque el origen tenga un 1 viejo.
            'omitir_en_cuenta_corriente' => 0,
            'employee_id'               => $controller->userId(false),
            'user_id'                   => $controller->userId(),
        ], $source->forzar_total_monto, 'budgets'));

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
        /*
            Sin esta linea el duplicado pierde los combos y muere con el mismo 500 que el alta: el
            `total` se copia del origen (con los combos adentro) pero `BudgetHelper::getTotal()` los
            busca en el duplicado y no los encuentra, la diferencia se pasa del margen de 3 y
            `BudgetController::duplicate()` corta con "El total del presupuesto no corresponde con
            los productos ingresados". Mismo motivo por el que `aplicar_recargos_directo_a_items` se
            copia unas lineas mas arriba.
        */
        BudgetHelper::attachCombos($model, self::combos_to_payload($source));

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
                'name_vender_personalizado' => $article->pivot->name,
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

    /**
     * Convierte los combos del origen al formato de `BudgetHelper::attachCombos`
     * (mision combos-y-rangos-de-precio, 16/9/2026).
     *
     * Devuelve SIEMPRE un array —vacio si el origen no tiene combos— y no null: la clave ausente
     * significa "el que manda esto no sabe de combos" y ahi `attachCombos()` no toca nada. Un
     * duplicado si sabe, y si el origen no tiene combos el duplicado tampoco tiene que tenerlos.
     *
     * 🔴 El origen se lee por `ComboEsquemaHelper` y no por `$source->combos`: en un cliente que
     * todavia no corrio la migracion de `budget_combo`, tocar la relacion aca dejaria sin poder
     * DUPLICAR ningun presupuesto. Sin tabla el duplicado sale sin combos, que es lo mismo que
     * tiene el origen.
     *
     * @param Budget $source Presupuesto origen con relación `combos` cargada.
     * @return array<int, array<string, mixed>>
     */
    private static function combos_to_payload(Budget $source): array {
        /** Filas con id y pivot amount/price. */
        $rows = [];
        foreach (ComboEsquemaHelper::combos_del_presupuesto($source) as $combo) {
            $rows[] = [
                'id' => $combo->id,
                'pivot' => [
                    'amount' => $combo->pivot->amount,
                    'price' => $combo->pivot->price,
                ],
            ];
        }
        return $rows;
    }

}
