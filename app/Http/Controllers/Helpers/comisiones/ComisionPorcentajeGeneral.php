<?php

namespace App\Http\Controllers\Helpers\comisiones;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\comisiones\ComisionesHelper;
use App\Http\Controllers\Helpers\comisiones\Helper;
use App\Models\SellerCommission;
use Illuminate\Support\Facades\Log;

/**
 * Grupo 268 · Prompt 02 — motor de comision GENERICO por porcentaje, el camino por defecto para
 * cualquier cliente que no tenga uno de los cinco `comision_funcion` hardcodeados (ver el `else`
 * final de ComisionesHelper::crear_comision()).
 *
 * A diferencia de DistriCreoComision (que asume 10% si el vendedor no tiene percentage_commission
 * cargado), este motor NO inventa un porcentaje por defecto: sin percentage_commission cargado y
 * mayor a cero, no crea nada. Ese comportamiento de "10% por defecto" es propio de ese cliente y
 * no se generaliza.
 */
class ComisionPorcentajeGeneral {

    /**
     * Crea una sola comision sobre el TOTAL FINAL de la venta (con descuentos, recargos e IVA ya
     * aplicados — decision explicita de Lucas, 29/7/2026, no usar subtotales ni netos).
     *
     * @param \App\Models\Sale $sale
     * @return void
     */
    function crear_comision($sale) {

        $seller = $sale->seller;

        if (is_null($seller) || (float)$seller->percentage_commission == 0) {
            // percentage_commission es decimal nullable: null, '0.00' o vacio caen todos aca via
            // el casteo a float. Sin porcentaje cargado, no se inventa uno (a diferencia de
            // DistriCreoComision).
            return;
        }

        $porcentaje_comision = $seller->percentage_commission;

        $moneda_id = $sale->moneda_id;
        if (is_null($moneda_id) || $moneda_id == 0) {
            $moneda_id = 1;
        }

        $status = Helper::get_status($sale);

        $ct = new Controller();

        $seller_commission = SellerCommission::create([
            'num'           => $ct->num('seller_commissions'),
            'seller_id'     => $sale->seller_id,
            'percentage'    => $porcentaje_comision,
            'sale_id'       => $sale->id,
            'moneda_id'     => $moneda_id,
            'debe'          => Numbers::redondear($sale->total * (float)$porcentaje_comision / 100),
            'status'        => $status,
            'liquidada_at'  => $status == 'active' ? now() : null,
            'description'   => 'Venta N°'.$sale->num,
            'user_id'       => $ct->userId(),
        ]);

        ComisionesHelper::recalcular_saldos($sale->seller_id, $moneda_id);

        Log::info('Se creo comision generica para sale_id '.$sale->id);
    }

}
