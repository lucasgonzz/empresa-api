<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\comisiones\ComisionesHelper;
use App\Models\CurrentAcount;
use App\Models\SellerCommission;
use Illuminate\Support\Facades\Log;

class SellerCommissionHelper {

    /**
     * Pasa a 'active' las comisiones pendientes ('inactive') de la venta que acaba de saldarse
     * por completo, y les setea liquidada_at y recalcula el saldo del vendedor en esa moneda.
     *
     * Grupo 268 · Prompt 02:
     * - Filtra `whereNull('haber')` para no tomar nunca una fila de tipo pago (bug latente:
     *   antes el where('sale_id', ...) las hubiera tomado si alguna vez quedara una asociada).
     * - liquidada_at = now() al pasar a active (bug C: antes el saldo de una comision diferida se
     *   calculaba cuando todavia estaba inactive y nunca se recalculaba al liquidarse).
     *
     * @param \App\Models\CurrentAcount $current_acount
     * @param \App\Models\CurrentAcount $pago El movimiento de pago que termino de saldar la venta.
     * @return void
     */
    static function checkCommissionStatus($current_acount, $pago) {

        if (is_null($current_acount->sale_id)) {
            // Sin sale_id (notas de debito, saldos iniciales), where('sale_id', null) de Laravel
            // se traduce a whereNull('sale_id') mas abajo: sin este corte activaria comisiones
            // HUERFANAS (sin venta) de CUALQUIER vendedor del tenant, no solo las de esta venta.
            return;
        }

        if (!is_null($current_acount->sale)) {
            Log::info('Checkeando la comision de la venta n°'.$current_acount->sale->num);
        }

        $seller_commissions = SellerCommission::where('sale_id', $current_acount->sale_id)
                                                ->whereNull('haber')
                                                ->get();

        // Pares seller_id + moneda_id afectados, para recalcular el saldo de cada uno una sola
        // vez despues del loop (una venta puede tener mas de una comision, ej. articulos + promos).
        $pares = [];

        foreach ($seller_commissions as $seller_commission) {
            if ($seller_commission->status == 'inactive') {
                $seller_commission->status = 'active';
                $seller_commission->liquidada_at = now();
                Log::info('Se puso en active');
                $seller_commission->save();
                $seller_commission->pagada_por()->attach($pago->id);

                $moneda_id = !is_null($seller_commission->moneda_id) ? $seller_commission->moneda_id : 1;
                $pares[$seller_commission->seller_id.'-'.$moneda_id] = [
                    'seller_id' => $seller_commission->seller_id,
                    'moneda_id' => $moneda_id,
                ];
            }
        }

        foreach ($pares as $par) {
            ComisionesHelper::recalcular_saldos($par['seller_id'], $par['moneda_id']);
        }
    }

    /**
     * Grupo 268 · Prompt 02, bug D: revierte las comisiones que se habian dado por liquidadas de
     * una venta que dejo de estar saldada (ej. se borro o modifico un pago). Se llama desde
     * CurrentAcountHelper::checkPagos(), al final, despues de que los debitos ya quedaron con su
     * estado definitivo.
     *
     * Solo revierte comisiones de vendedores con commission_after_pay_sale = 1: las de
     * liquidacion inmediata nunca dependieron de que la venta se saldara, asi que no tiene
     * sentido tocarlas aca (ademas de que ya nacen con liquidada_at seteada al crearse).
     *
     * Dos correcciones sobre la primera version (halladas por el checker, verificadas contra
     * codigo real):
     * - `whereNotNull('debe')` en el filtro de current_acounts: sin esto, una NOTA DE CREDITO de
     *   la venta (mismo sale_id, status='nota_credito', nunca 'pagado') hacia que su venta
     *   entrara como "no pagada" aunque el debito original SI estuviera 'pagado' -> revertia una
     *   comision ya liquidada de una venta saldada, y checkPagos la volvia a revertir en cada
     *   corrida (irrecuperable).
     * - El filtro de comisiones ya no es solo `status = 'active'`: una comision que otro camino
     *   (ej. CurrentAcountHelper::updateSellerCommissionsStatus(), disparado ANTES del delete en
     *   CurrentAcountController::destroy()) ya haya dejado en 'inactive' pero con `liquidada_at`/
     *   `saldo` todavia seteados (sin recalcular) tambien tiene que pasar por aca, si no el
     *   ledger queda con saldo viejo para siempre.
     *
     * @param int $credit_account_id
     * @return void
     */
    static function revertirComisionesNoSaldadas($credit_account_id) {

        $sale_ids_no_pagados = CurrentAcount::where('credit_account_id', $credit_account_id)
                                                ->whereNotNull('sale_id')
                                                ->whereNotNull('debe')
                                                ->where('status', '!=', 'pagado')
                                                ->pluck('sale_id');

        if (count($sale_ids_no_pagados) == 0) {
            return;
        }

        $seller_commissions = SellerCommission::whereIn('sale_id', $sale_ids_no_pagados)
                                                ->whereNull('haber')
                                                ->where(function ($q) {
                                                    $q->where('status', 'active')
                                                        ->orWhereNotNull('liquidada_at')
                                                        ->orWhereNotNull('saldo');
                                                })
                                                ->get();

        $pares = [];

        foreach ($seller_commissions as $seller_commission) {

            $seller = $seller_commission->seller;

            if (is_null($seller) || !$seller->commission_after_pay_sale) {
                // Liquidacion inmediata: no depende de que la venta se salde, no se toca.
                continue;
            }

            $seller_commission->status = 'inactive';
            $seller_commission->liquidada_at = null;
            $seller_commission->saldo = null;
            // Sin este detach, cada ciclo revertir->reactivar acumula una fila mas en el pivot
            // (medido: 3 corridas de checkPagos = 3 imputaciones para la misma comision).
            $seller_commission->pagada_por()->detach();
            $seller_commission->save();

            $moneda_id = !is_null($seller_commission->moneda_id) ? $seller_commission->moneda_id : 1;
            $pares[$seller_commission->seller_id.'-'.$moneda_id] = [
                'seller_id' => $seller_commission->seller_id,
                'moneda_id' => $moneda_id,
            ];
        }

        foreach ($pares as $par) {
            ComisionesHelper::recalcular_saldos($par['seller_id'], $par['moneda_id']);
        }
    }

    /**
     * Grupo 268 · Prompt 02: unica rama viva de esta funcion (la que arma la descripcion de la
     * venta colgaba de commissionForSeller(), codigo muerto que nadie llamaba, y tenia el bug de
     * usar $sale sin definir). SellerCommissionController es el unico llamador real, y siempre
     * la invoca sin argumentos.
     *
     * @return string
     */
    static function getDescription() {
        return 'Pago a vendedor';
    }

}
